<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\NestedElementInterface;
use craft\base\RelationalFieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\User;
use craft\errors\FieldNotFoundException;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Json;
use craft\models\FieldLayout;
use SomehowDigital\Craft\Hub\models\EntryReference;
use yii\base\Component;

/**
 * Finds the elements that reference a given entry or asset, those it references, and keeps the index of the reference tags
 * in their content.
 *
 * References are relations, made by relation fields like Entries, Assets or Link fields, and reference tags by ID in
 * other fields, like the links and images of rich text (e.g. `{entry:123@1:url}`), which Craft keeps no record of; Hub
 * indexes them as elements are saved, see {@see index()}. Reference tags by anything but an ID aren't indexed.
 * References from nested entries are attributed to their root owner, so the list shows the elements editors know. Users
 * reference the asset that is their photo, which is no relation, but deleting the asset takes it from them.
 */
class ReferenceService extends Component
{
	/** reference tags found in the content of elements, by the element and site holding them, the field and their target */
	public const TABLE = '{{%hub_references}}';

	/** most references of each kind, relations and reference tags, read for the elements about to be deleted, see getReferencesTo() */
	public const SOURCE_LIMIT = 500;

	/**
	 * Craft's pattern of reference tags ({@see Elements::REF_TAG_PATTERN}), also matching tags with an empty fallback,
	 * which CKEditor saves for links to elements without a URL, like `{entry:123@1:url||}`.
	 */
	private const TAG_PATTERN = '/\{(?P<elementType>[\w\\\\]+):(?P<ref>[^@:}|]+)(?:@[^:}|]+)?(?::[^}| ]+)?(?: *\|\| *[^}]*)?\}/';

	/**
	 * Elements that reference the element through any relation field (Entries, Assets, Link, …), a reference tag or, for
	 * assets, as the photo of a user.
	 *
	 * @return EntryReference[]
	 */
	public function getReferences(ElementInterface $entry): array
	{
		$id = $entry->getCanonicalId();

		[$relations, $tags] = $this->sourceQueries($id);

		$photos = $entry instanceof Asset ? $this->photoRows($id) : [];

		return $this->collect([...$relations->all(), ...$tags->all(), ...$photos], $entry->siteId, fn (ElementInterface $root) => $root->id === $id);
	}

	/**
	 * Elements referencing each of the targets, like {@see getReferences()}, read at once for the items of a list.
	 *
	 * @param int[] $targetIds
	 * @param callable(ElementInterface):bool $excluded leaves out references by what's excluded, like what's deleted along
	 * @return array<int,EntryReference[]> by the ID of the target
	 */
	public function getReferencesOfEach(array $targetIds, callable $excluded): array
	{
		if ($targetIds === []) {
			return [];
		}

		[$relations, $tags] = $this->sourceQueries($targetIds);
		$rows = [
			...$relations->addSelect(['targetId' => 'relations.targetId'])->all(),
			...$tags->addSelect(['targetId' => 'hubReferences.targetId'])->all(),
			...$this->photoRowsOf($targetIds),
		];
		$siteId = Craft::$app->getSites()->getPrimarySite()->id;
		$sources = $this->loadElements($rows, $siteId);

		$byTarget = [];
		foreach ($rows as $row) {
			$byTarget[(int)$row['targetId']][] = $row;
		}

		$references = [];
		foreach ($byTarget as $id => $targetRows) {
			$references[$id] = $this->collect($targetRows, $siteId, fn (ElementInterface $root) => $root->id === $id || $excluded($root), $sources);
		}

		return $references;
	}

	/**
	 * Elements the element references, through the relation fields and reference tags of it and of its nested entries, each
	 * with the fields leading to the reference, and, for users, their photo. Drafts and revisions show those of their
	 * canonical element, as their reference tags aren't indexed.
	 *
	 * @return EntryReference[]
	 */
	public function getReferenced(ElementInterface $element): array
	{
		$id = (int)$element->getCanonicalId();
		$paths = $this->nestedPaths($id);
		$rows = $this->targetRows(array_keys($paths));

		if ($element instanceof User && $element->photoId !== null) {
			$rows[] = ['id' => $element->photoId, 'type' => Asset::class, 'fieldId' => null, 'sourceId' => $id];
		}

		$targets = $this->loadElements($rows, (int)($element->siteId ?? Craft::$app->getSites()->getPrimarySite()->id));
		$references = [];

		foreach ($rows as $row) {
			$target = $targets[(int)$row['id']] ?? null;

			if ($target === null || isset($paths[$target->id]) || !Craft::$app->getElements()->canView($target)) {
				continue;
			}

			$field = $row['fieldId'] !== null ? Craft::$app->getFields()->getFieldById((int)$row['fieldId']) : null;
			$names = [...$paths[(int)$row['sourceId']], $this->pathName($field, $row['fieldId'] !== null)];

			$references[$target->id] ??= new EntryReference($target);
			$references[$target->id]->addPath(implode(' → ', $names), $field);
		}

		return $this->sorted($references);
	}

	/**
	 * Elements that reference any of the targets, by their root owner, leaving out those that are excluded, like the elements
	 * deleted along with the targets. Up to SOURCE_LIMIT relations and reference tags each are read, as many targets may be
	 * referenced by many; `capped` tells whether there are more.
	 *
	 * @param int[]|Query<array-key,mixed> $targetIds IDs, or a query selecting them
	 * @param callable(ElementInterface):bool $excluded
	 * @return array{references:EntryReference[],capped:bool}
	 */
	public function getReferencesTo(array|Query $targetIds, callable $excluded): array
	{
		if ($targetIds === []) {
			return ['references' => [], 'capped' => false];
		}

		[$relations, $tags] = $this->sourceQueries($targetIds);
		$relations = $relations->orderBy(['id' => SORT_ASC])->limit(self::SOURCE_LIMIT + 1)->all();
		$tags = $tags->orderBy(['id' => SORT_ASC])->limit(self::SOURCE_LIMIT + 1)->all();
		$capped = count($relations) > self::SOURCE_LIMIT || count($tags) > self::SOURCE_LIMIT;
		$rows = [...array_slice($relations, 0, self::SOURCE_LIMIT), ...array_slice($tags, 0, self::SOURCE_LIMIT)];
		$siteId = Craft::$app->getSites()->getPrimarySite()->id;

		return ['references' => $this->collect($rows, $siteId, $excluded), 'capped' => $capped];
	}

	/**
	 * Number of live references targeting each of the given elements, relations and reference tags alike.
	 *
	 * @param int[] $elementIds
	 * @return array<int,int> element ID => references
	 */
	public function getReferenceCounts(array $elementIds): array
	{
		if ($elementIds === []) {
			return [];
		}

		$rows = (new Query())
			->select(['elementId' => 'relations.targetId', 'references' => 'COUNT(*)'])
			->from(['relations' => Table::RELATIONS])
			->innerJoin(['sources' => Table::ELEMENTS], '[[sources.id]] = [[relations.sourceId]]')
			->where(['relations.targetId' => $elementIds])
			->andWhere([
				'sources.dateDeleted' => null,
				'sources.draftId' => null,
				'sources.revisionId' => null,
			])
			->groupBy(['relations.targetId'])
			->all();

		$tags = (new Query())
			->select(['elementId' => 'hubReferences.targetId', 'references' => 'COUNT(*)'])
			->from(['hubReferences' => self::TABLE])
			->innerJoin(['sources' => Table::ELEMENTS], '[[sources.id]] = [[hubReferences.sourceId]]')
			->where(['hubReferences.targetId' => $elementIds])
			->andWhere(['sources.dateDeleted' => null])
			->groupBy(['hubReferences.targetId'])
			->all();

		$photos = (new Query())
			->select(['elementId' => 'hubPhotos.photoId', 'references' => 'COUNT(*)'])
			->from(['hubPhotos' => Table::USERS])
			->innerJoin(['sources' => Table::ELEMENTS], '[[sources.id]] = [[hubPhotos.id]]')
			->where(['hubPhotos.photoId' => $elementIds])
			->andWhere(['sources.dateDeleted' => null])
			->groupBy(['hubPhotos.photoId'])
			->all();

		$counts = [];
		foreach ([...$rows, ...$tags, ...$photos] as $row) {
			$counts[(int)$row['elementId']] = ($counts[(int)$row['elementId']] ?? 0) + (int)$row['references'];
		}

		return $counts;
	}

	/**
	 * Live users whose photo is the asset in a column of a query, for the conditions and counts of what is referenced.
	 *
	 * @param string $assetId SQL of the column holding the asset's ID
	 */
	public static function photosQuery(string $assetId): Query
	{
		return (new Query())
			->from(['hubUsers' => Table::USERS])
			->innerJoin(['hubUserElements' => Table::ELEMENTS], '[[hubUserElements.id]] = [[hubUsers.id]]')
			->where("[[hubUsers.photoId]] = $assetId")
			->andWhere(['hubUserElements.dateDeleted' => null]);
	}

	/**
	 * IDs of the assets that are photos of users. There are few of them and `photoId` has no index, so conditions on many
	 * assets look them up in this list, as an `EXISTS` of {@see photosQuery()} scans the users for each asset.
	 */
	public static function photoIdsQuery(): Query
	{
		return (new Query())
			->select(['hubUsers.photoId'])
			->from(['hubUsers' => Table::USERS])
			->innerJoin(['hubUserElements' => Table::ELEMENTS], '[[hubUserElements.id]] = [[hubUsers.id]]')
			->where(['not', ['hubUsers.photoId' => null]])
			->andWhere(['hubUserElements.dateDeleted' => null]);
	}

	/**
	 * Indexes the reference tags in the content of the elements on all their sites, replacing what was indexed for them.
	 *
	 * The content is read from the database, so the elements aren't loaded. Drafts and revisions aren't indexed, as their
	 * references aren't live, and relation fields neither, as their references are relations. Tags of elements that don't
	 * exist and of the element itself are left out.
	 *
	 * @param int[] $elementIds
	 */
	public function index(array $elementIds): void
	{
		if ($elementIds === []) {
			return;
		}

		$rows = (new Query())
			->select([
				'elementId' => 'elements_sites.elementId',
				'siteId' => 'elements_sites.siteId',
				'content' => 'elements_sites.content',
				'fieldLayoutId' => 'elements.fieldLayoutId',
			])
			->from(['elements_sites' => Table::ELEMENTS_SITES])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[elements_sites.elementId]]')
			->where([
				'elements_sites.elementId' => $elementIds,
				'elements.draftId' => null,
				'elements.revisionId' => null,
			])
			->andWhere(['not', ['elements_sites.content' => null]])
			->all();

		$references = [];

		foreach ($rows as $row) {
			$sourceId = (int)$row['elementId'];
			$siteId = (int)$row['siteId'];

			foreach ($this->findTags($row['content'], $row['fieldLayoutId'] !== null ? (int)$row['fieldLayoutId'] : null) as [$fieldId, $targetId]) {
				if ($targetId !== $sourceId) {
					$references["$sourceId:$siteId:$fieldId:$targetId"] = [$sourceId, $siteId, $fieldId, $targetId];
				}
			}
		}

		$targetIds = array_values(array_unique(array_column($references, 3)));
		$existing = $targetIds === [] ? [] : array_flip(array_map('intval', (new Query())
			->select(['id'])
			->from([Table::ELEMENTS])
			->where(['id' => $targetIds])
			->column()));

		$values = array_values(array_filter($references, fn (array $reference) => isset($existing[$reference[3]])));

		$db = Craft::$app->getDb();

		$db->transaction(function () use ($db, $elementIds, $values) {
			$db->createCommand()->delete(self::TABLE, ['sourceId' => $elementIds])->execute();

			if ($values !== []) {
				$db->createCommand()->batchInsert(self::TABLE, ['sourceId', 'sourceSiteId', 'fieldId', 'targetId'], $values)->execute();
			}
		});
	}

	/**
	 * Elements whose reference tags are indexed, by their ID, to index all of them again.
	 */
	public function indexableQuery(): Query
	{
		return (new Query())
			->select(['id'])
			->from([Table::ELEMENTS])
			->where(['draftId' => null, 'revisionId' => null])
			->orderBy(['id' => SORT_ASC]);
	}

	/**
	 * Reference tags by ID in the content of an element on a site, along with the field holding them.
	 *
	 * @param mixed $content the content column, values by the UID of their field's layout element
	 * @return array<int,array{0:int,1:int}> field ID and target ID of each tag
	 */
	public function findTags(mixed $content, ?int $fieldLayoutId): array
	{
		$content = is_string($content) ? Json::decodeIfJson($content) : $content;
		$layout = $fieldLayoutId !== null ? Craft::$app->getFields()->getLayoutById($fieldLayoutId) : null;

		if (!is_array($content) || $layout === null) {
			return [];
		}

		$elements = Craft::$app->getElements();
		$tags = [];

		foreach ($content as $uid => $value) {
			$field = $this->contentField($layout, (string)$uid);
			$text = is_string($value) ? $value : Json::encode($value);

			if ($field === null || $field instanceof RelationalFieldInterface || !str_contains($text, '{')) {
				continue;
			}

			preg_match_all(self::TAG_PATTERN, $text, $matches, PREG_SET_ORDER);

			foreach ($matches as $match) {
				if (ctype_digit($match['ref']) && $elements->getElementTypeByRefHandle($match['elementType']) !== null) {
					$tags[] = [(int)$field->id, (int)$match['ref']];
				}
			}
		}

		return $tags;
	}

	/**
	 * Queries of the live elements referencing the targets through relations and through reference tags, selecting the
	 * source element's `id`, `type` and the `fieldId` they reference them through.
	 *
	 * @param int|int[]|Query<array-key,mixed> $targetIds
	 * @return array{0:Query,1:Query}
	 */
	private function sourceQueries(int|array|Query $targetIds): array
	{
		$relations = (new Query())
			->select([
				'id' => 'relations.sourceId',
				'type' => 'sources.type',
				'fieldId' => 'relations.fieldId',
			])
			->distinct()
			->from(['relations' => Table::RELATIONS])
			->innerJoin(['sources' => Table::ELEMENTS], '[[sources.id]] = [[relations.sourceId]]')
			->where(['relations.targetId' => $targetIds])
			->andWhere([
				'sources.dateDeleted' => null,
				'sources.draftId' => null,
				'sources.revisionId' => null,
			]);

		$tags = (new Query())
			->select([
				'id' => 'hubReferences.sourceId',
				'type' => 'sources.type',
				'fieldId' => 'hubReferences.fieldId',
			])
			->distinct()
			->from(['hubReferences' => self::TABLE])
			->innerJoin(['sources' => Table::ELEMENTS], '[[sources.id]] = [[hubReferences.sourceId]]')
			->where(['hubReferences.targetId' => $targetIds])
			->andWhere(['sources.dateDeleted' => null]);

		return [$relations, $tags];
	}

	/**
	 * Rows of the live users whose photo the asset is, like those of the queries of sourceQueries(), without a field.
	 *
	 * @return array<int,array{id:int,type:class-string<User>,fieldId:null}>
	 */
	private function photoRows(int $assetId): array
	{
		$ids = self::photosQuery(':assetId')->select(['hubUsers.id'])->addParams([':assetId' => $assetId])->column();

		return array_map(fn ($id) => ['id' => (int)$id, 'type' => User::class, 'fieldId' => null], $ids);
	}

	/**
	 * Rows of the live users whose photo any of the assets is, like those of photoRows(), along with the asset.
	 *
	 * @param int[] $assetIds
	 * @return array<int,array{id:int,type:class-string<User>,fieldId:null,targetId:int}>
	 */
	private function photoRowsOf(array $assetIds): array
	{
		$rows = self::photoIdsQuery()->addSelect(['hubUsers.id'])->andWhere(['hubUsers.photoId' => $assetIds])->all();

		return array_map(fn (array $row) => ['id' => (int)$row['id'], 'type' => User::class, 'fieldId' => null, 'targetId' => (int)$row['photoId']], $rows);
	}

	/**
	 * Names of the fields leading to the live nested entries of an element, at any depth, by their ID, along with the
	 * element itself, which none lead to.
	 *
	 * @return array<int,string[]>
	 */
	private function nestedPaths(int $id): array
	{
		$paths = [$id => []];
		$owners = [$id];

		while ($owners !== []) {
			$rows = (new Query())
				->select(['id' => 'owners.elementId', 'ownerId' => 'owners.ownerId', 'fieldId' => 'entries.fieldId'])
				->from(['owners' => Table::ELEMENTS_OWNERS])
				->innerJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[owners.elementId]]')
				->innerJoin(['nested' => Table::ELEMENTS], '[[nested.id]] = [[owners.elementId]]')
				->where([
					'owners.ownerId' => $owners,
					'nested.dateDeleted' => null,
					'nested.draftId' => null,
					'nested.revisionId' => null,
				])
				->all();

			$owners = [];

			foreach ($rows as $row) {
				$nestedId = (int)$row['id'];

				// An entry can be owned by several elements, and is only followed once.
				if (!isset($paths[$nestedId])) {
					$field = $row['fieldId'] !== null ? Craft::$app->getFields()->getFieldById((int)$row['fieldId']) : null;
					$paths[$nestedId] = [...$paths[(int)$row['ownerId']], $this->pathName($field)];
					$owners[] = $nestedId;
				}
			}
		}

		return $paths;
	}

	/**
	 * Live elements the sources reference, through relations and reference tags, by the target's `id` and `type`, the
	 * `fieldId` they reference it through and the `sourceId`.
	 *
	 * @param int[] $sourceIds
	 * @return array<int,array<string,mixed>>
	 */
	private function targetRows(array $sourceIds): array
	{
		$live = ['targets.dateDeleted' => null, 'targets.draftId' => null, 'targets.revisionId' => null];

		$relations = (new Query())
			->select(['id' => 'relations.targetId', 'type' => 'targets.type', 'fieldId' => 'relations.fieldId', 'sourceId' => 'relations.sourceId'])
			->distinct()
			->from(['relations' => Table::RELATIONS])
			->innerJoin(['targets' => Table::ELEMENTS], '[[targets.id]] = [[relations.targetId]]')
			->where(['relations.sourceId' => $sourceIds])
			->andWhere($live)
			->all();

		$tags = (new Query())
			->select(['id' => 'hubReferences.targetId', 'type' => 'targets.type', 'fieldId' => 'hubReferences.fieldId', 'sourceId' => 'hubReferences.sourceId'])
			->distinct()
			->from(['hubReferences' => self::TABLE])
			->innerJoin(['targets' => Table::ELEMENTS], '[[targets.id]] = [[hubReferences.targetId]]')
			->where(['hubReferences.sourceId' => $sourceIds])
			->andWhere($live)
			->all();

		return [...$relations, ...$tags];
	}

	/**
	 * Name of a field on the path to a reference; references without a field are those of the photo of a user.
	 */
	private function pathName(?FieldInterface $field, bool $hasField = true): string
	{
		return match (true) {
			$field !== null => Craft::t('site', (string)$field->name),
			$hasField => Craft::t('hub', 'Unknown field'),
			default => Craft::t('hub', 'User photo'),
		};
	}

	/**
	 * The field of a layout element, or null for layout elements that aren't fields or whose field no longer exists.
	 */
	private function contentField(FieldLayout $layout, string $uid): ?FieldInterface
	{
		$element = $layout->getElementByUid($uid);

		if (!$element instanceof CustomField) {
			return null;
		}

		try {
			return $element->getField();
		} catch (FieldNotFoundException) {
			return null;
		}
	}

	/**
	 * Loads the referencing elements and groups them by root owner.
	 *
	 * @param array<int,array<string,mixed>> $rows with the source element's `id`, `type` and `fieldId`
	 * @param callable(ElementInterface):bool $excluded roots left out, like the referenced element itself
	 * @param array<int,ElementInterface>|null $sources the source elements by ID, if they're loaded already
	 * @return EntryReference[]
	 */
	private function collect(array $rows, int $siteId, callable $excluded, ?array $sources = null): array
	{
		$sources ??= $this->loadElements($rows, $siteId);
		$references = [];

		foreach ($rows as $row) {
			$source = $sources[(int)$row['id']] ?? null;

			if ($source === null) {
				continue;
			}

			$path = [];
			$root = $this->resolveRoot($source, $path);

			if ($root === null || $excluded($root) || !Craft::$app->getElements()->canView($root)) {
				continue;
			}

			$fieldId = $row['fieldId'] !== null ? (int)$row['fieldId'] : null;
			$field = $fieldId !== null ? $source->getFieldLayout()?->getFieldById($fieldId) ?? Craft::$app->getFields()->getFieldById($fieldId) : null;
			$path[] = $this->pathName($field, $fieldId !== null);

			$references[$root->id] ??= new EntryReference($root);
			$references[$root->id]->addPath(implode(' → ', $path), $field);
		}

		return $this->sorted($references);
	}

	/**
	 * @param EntryReference[] $references
	 * @return EntryReference[]
	 */
	private function sorted(array $references): array
	{
		usort($references, fn (EntryReference $a, EntryReference $b) => strnatcasecmp((string)$a->element, (string)$b->element));

		return $references;
	}

	/**
	 * Walks up the owners of a nested element, collecting the names of the fields it is nested in.
	 *
	 * Returns null for elements owned by drafts or revisions, or whose owner no longer exists.
	 *
	 * @param string[] $path
	 */
	private function resolveRoot(ElementInterface $element, array &$path): ?ElementInterface
	{
		while ($element instanceof NestedElementInterface && $element->getPrimaryOwnerId() !== null) {
			$owner = $element->getOwner();

			if ($owner === null || $owner->getIsDraft() || $owner->getIsRevision()) {
				return null;
			}

			$field = $element->getField();
			array_unshift($path, $field !== null ? Craft::t('site', $field->name) : Craft::t('hub', 'Unknown field'));
			$element = $owner;
		}

		return $element;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,ElementInterface> indexed by ID
	 */
	private function loadElements(array $rows, int $siteId): array
	{
		$ids = [];
		foreach ($rows as $row) {
			$ids[$row['type']][] = (int)$row['id'];
		}

		$elements = [];
		foreach ($ids as $type => $typeIds) {
			if (!is_subclass_of($type, ElementInterface::class)) {
				continue;
			}

			$elements += $type::find()
				->id(array_unique($typeIds))
				->site('*')
				->unique()
				->preferSites([$siteId])
				->status(null)
				->indexBy('id')
				->all();
		}

		return $elements;
	}
}
