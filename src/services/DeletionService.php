<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use Closure;
use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\i18n\Formatter;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\models\DeletionItem;
use SomehowDigital\Craft\Hub\models\DeletionPreview;
use SomehowDigital\Craft\Hub\models\EntryReference;
use yii\base\Component;

/**
 * Previews what deleting the items of a list takes along, before they're deleted (see BulkActions::apply()).
 *
 * Deleting entries also deletes their nested entries and drafts, and moves their children in a structure up a level.
 * Deleting files also deletes their transforms and unsets them as user photos. The elements referencing what is
 * deleted, apart from those deleted along with it, lose those references. The structure, like sections and fields, is
 * never deleted from the Hub, so nothing is previewed for it.
 *
 * The totals cover all items; the items of a page (see Paginator::PAGE_SIZE) are detailed one by one, listing
 * what deleting each takes along.
 *
 * @internal
 */
final class DeletionService extends Component
{
	/** levels of nested entries counted, as nested entries may hold nested entries themselves */
	private const NESTING_DEPTH = 5;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly ReferenceService $references,
		private readonly FileService $files,
		private readonly UserService $users,
		private readonly Elements $craftElements,
		private readonly Formatter $formatter,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * @param int[] $ids
	 * @param int $page of the items detailed, kept within the pages there are
	 */
	public function getPreview(string $list, array $ids, int $page = 1): DeletionPreview
	{
		$preview = new DeletionPreview();
		$preview->page = $page;

		if ($list === ColumnService::LIST_ENTRIES) {
			$this->previewEntries($preview, $ids);
		} elseif ($list === ColumnService::LIST_FILES) {
			$this->previewFiles($preview, $ids);
		}

		$preview->skipped = count(array_unique($ids)) - $preview->total;

		return $preview;
	}

	/**
	 * @param int[] $ids
	 */
	private function previewEntries(DeletionPreview $preview, array $ids): void
	{
		$service = $this->craftElements;
		$entries = array_values(array_filter(
			$ids !== [] ? Entry::find()->id($ids)->site('*')->unique()->status(null)->all() : [],
			fn(Entry $entry): bool => $service->canDelete($entry),
		));
		$ids = array_map(fn(Entry $entry): int => (int) $entry->id, $entries);

		$preview->setItems($entries, '{count, plural, =1{One entry} other{# entries}}', 'newspaper');

		if ($ids === []) {
			return;
		}

		$references = $this->references;
		$deleted = fn(ElementInterface $root): bool => in_array($root->id, $ids, true);

		$this->addEntryEffects($preview, $ids, ['elements.canonicalId' => $ids]);
		$this->addChildrenEffect($preview, $ids);
		$this->addReferencesEffect($preview, $references->getReferencesTo($ids, $deleted));

		// What the items shown take along, loaded at once.
		$shown = $preview->getItemIds();
		$children = $this->childrenOf($shown, $ids);
		$referencing = $references->getReferencesOfEach($shown, $deleted);
		$nested = $this->groupBy(Entry::find()->primaryOwnerId($shown)->site('*')->unique()->status(null)->all(), static fn(Entry $entry): ?int => $entry->primaryOwnerId);
		$drafts = $this->groupBy(Entry::find()->drafts()->draftOf($shown)->site('*')->unique()->status(null)->all(), static fn(Entry $entry): ?int => $entry->getCanonicalId());

		foreach ($preview->items as $item) {
			/** @var Entry $entry */
			$entry = $item->model;
			$id = $item->getId();

			$item->addGroup(DeletionPreview::TONE_DELETED, 'layer-group', Craft::t('hub', '{count, plural, =1{Nested entry} other{Nested entries}}', ['count' => count($nested[$id] ?? [])]), count($nested[$id] ?? []), $nested[$id] ?? []);
			$item->addGroup(DeletionPreview::TONE_DELETED, 'pen-to-square', Craft::t('hub', '{count, plural, =1{Draft} other{Drafts}}', ['count' => count($drafts[$id] ?? [])]), count($drafts[$id] ?? []), $drafts[$id] ?? []);

			if ($entry->structureId !== null) {
				$moved = $children[$id] ?? [];
				$item->addGroup(DeletionPreview::TONE_CHANGED, 'turn-up', Craft::t('hub', '{count, plural, =1{Child entry moves} other{Child entries move}} up a level', ['count' => count($moved)]), count($moved), $moved);
			}

			$this->addReferencesGroup($item, $referencing[$id] ?? []);
		}
	}

	/**
	 * @param int[] $ids
	 */
	private function previewFiles(DeletionPreview $preview, array $ids): void
	{
		$service = $this->craftElements;
		/** @var Asset[] $assets */
		$assets = array_values(array_filter(
			$ids !== [] ? Asset::find()->id($ids)->site('*')->unique()->all() : [],
			fn(Asset $asset): bool => $service->canDelete($asset),
		));
		$ids = array_map(fn(Asset $asset): int => (int) $asset->id, $assets);

		$preview->setItems($assets, '{count, plural, =1{One file} other{# files}}', 'image');

		if ($ids === []) {
			return;
		}

		$size = array_sum(array_map(fn(Asset $asset): int => (int) $asset->size, $assets));
		$transforms = $this->files->getTransformsOf($assets);
		$transformCount = array_sum(array_map(count(...), $transforms));
		$photos = $this->photosOf($ids);

		$preview->addEffect(DeletionPreview::TONE_DELETED, 'hard-drive', $size, $this->size($size), Craft::t('hub', 'File size'));
		$preview->addEffect(DeletionPreview::TONE_DELETED, 'crop', $transformCount, $this->number($transformCount), Craft::t('hub', '{count, plural, =1{Transform} other{Transforms}}', ['count' => $transformCount]));
		$preview->addEffect(DeletionPreview::TONE_CHANGED, 'user', count($photos), $this->number(count($photos)), Craft::t('hub', '{count, plural, =1{User photo} other{User photos}}', ['count' => count($photos)]));
		$this->addReferencesEffect($preview, $this->references->getReferencesTo($ids, fn(): false => false));

		$users = $this->users->getUsers(array_keys($photos));
		$referencing = $this->references->getReferencesOfEach($preview->getItemIds(), fn(): false => false);

		foreach ($preview->items as $item) {
			$id = $item->getId();
			$own = $transforms[$id] ?? [];
			$photoOf = array_values(array_filter(array_map(fn(int $userId) => $users[$userId] ?? null, array_keys($photos, $id, true))));

			$item->addGroup(DeletionPreview::TONE_DELETED, 'crop', Craft::t('hub', '{count, plural, =1{Transform} other{Transforms}}', ['count' => count($own)]), count($own), array_map(fn(array $transform): string => Row::string($transform, 'transformString'), $own));
			$item->addGroup(DeletionPreview::TONE_CHANGED, 'user', Craft::t('hub', '{count, plural, =1{User loses their photo} other{Users lose their photo}}', ['count' => count($photoOf)]), count($photoOf), $photoOf);
			$this->addReferencesGroup($item, $referencing[$id] ?? []);
		}
	}

	/**
	 * Adds the totals of the nested entries and drafts deleted along with the entries.
	 *
	 * @param int[] $entryIds the entries deleted
	 * @param array<string,mixed> $draftCondition on the `entries` or `elements` table of the drafts
	 */
	private function addEntryEffects(DeletionPreview $preview, array $entryIds, array $draftCondition): void
	{
		$nestedCount = $this->countNested($entryIds);
		$preview->addEffect(DeletionPreview::TONE_DELETED, 'layer-group', $nestedCount, $this->number($nestedCount), Craft::t('hub', '{count, plural, =1{Nested entry} other{Nested entries}}', ['count' => $nestedCount]));

		$drafts = $this->countDrafts($draftCondition);
		$preview->addEffect(DeletionPreview::TONE_DELETED, 'pen-to-square', $drafts, $this->number($drafts), Craft::t('hub', '{count, plural, =1{Draft} other{Drafts}}', ['count' => $drafts]));
	}

	/**
	 * Adds the total of the entries moving up a level in their structure, as their parent is deleted.
	 *
	 * @param int[] $ids
	 */
	private function addChildrenEffect(DeletionPreview $preview, array $ids): void
	{
		$children = (int) (new Query())
			->from(['children' => Table::STRUCTUREELEMENTS])
			->innerJoin(
				['parents' => Table::STRUCTUREELEMENTS],
				'[[parents.structureId]] = [[children.structureId]] AND [[children.lft]] > [[parents.lft]] AND [[children.rgt]] < [[parents.rgt]] AND [[children.level]] = [[parents.level]] + 1',
			)
			->where(['parents.elementId' => $ids])
			->andWhere(['not', ['children.elementId' => $ids]])
			->count('DISTINCT [[children.elementId]]');

		$preview->addEffect(DeletionPreview::TONE_CHANGED, 'turn-up', $children, $this->number($children), Craft::t('hub', '{count, plural, =1{Child entry} other{Child entries}} moved up', ['count' => $children]));
	}

	/**
	 * The children of entries in their structure that aren't deleted along with them.
	 *
	 * @param int[] $parentIds
	 * @param int[] $deletedIds
	 * @return array<int,Entry[]> by the ID of the parent
	 */
	private function childrenOf(array $parentIds, array $deletedIds): array
	{
		$rows = (new Query())
			->select(['parentId' => 'parents.elementId', 'childId' => 'children.elementId'])
			->from(['children' => Table::STRUCTUREELEMENTS])
			->innerJoin(
				['parents' => Table::STRUCTUREELEMENTS],
				'[[parents.structureId]] = [[children.structureId]] AND [[children.lft]] > [[parents.lft]] AND [[children.rgt]] < [[parents.rgt]] AND [[children.level]] = [[parents.level]] + 1',
			)
			->where(['parents.elementId' => $parentIds])
			->andWhere(['not', ['children.elementId' => $deletedIds]])
			->orderBy(['children.lft' => SORT_ASC])
			->all();

		$ids = array_map(fn(array $row): int => Row::int($row, 'childId'), $rows);
		$entries = $ids !== [] ? Entry::find()->id($ids)->site('*')->unique()->status(null)->indexBy('id')->all() : [];

		$children = [];
		foreach ($rows as $row) {
			if (isset($entries[Row::int($row, 'childId')])) {
				$children[Row::int($row, 'parentId')][] = $entries[Row::int($row, 'childId')];
			}
		}

		return $children;
	}

	/**
	 * @param array{references:EntryReference[],capped:bool} $references see ReferenceService::getReferencesTo()
	 */
	private function addReferencesEffect(DeletionPreview $preview, array $references): void
	{
		$count = count($references['references']);
		$preview->addEffect(DeletionPreview::TONE_CHANGED, 'link-slash', $count, $this->number($count) . ($references['capped'] ? '+' : ''), Craft::t('hub', '{count, plural, =1{Referencing element} other{Referencing elements}}', ['count' => $count]));
	}

	/**
	 * @param EntryReference[] $references
	 */
	private function addReferencesGroup(DeletionItem $item, array $references): void
	{
		$item->addGroup(DeletionPreview::TONE_CHANGED, 'link-slash', Craft::t('hub', '{count, plural, =1{Element loses its reference} other{Elements lose their references}}', ['count' => count($references)]), count($references), $references);
	}

	/**
	 * Number of the nested entries owned by the entries, and of those owned by them in turn.
	 *
	 * @param int[]|Query<array-key,mixed> $ownerIds
	 */
	private function countNested(array|Query $ownerIds): int
	{
		$total = 0;

		for ($depth = 0; $depth < self::NESTING_DEPTH; $depth++) {
			$nested = $this->liveEntries(['entries.primaryOwnerId' => $ownerIds]);
			$count = (int) (clone $nested)->count();

			if ($count === 0) {
				break;
			}

			$total += $count;
			$ownerIds = $nested;
		}

		return $total;
	}

	/**
	 * Number of the drafts of the entries matching the condition. Like Craft's element indexes, unsaved drafts of new
	 * entries are left out.
	 *
	 * @param array<string,mixed> $condition on the `entries` or `elements` table of the drafts
	 */
	private function countDrafts(array $condition): int
	{
		return (int) (new Query())
			->from(['elements' => Table::ELEMENTS])
			->innerJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[elements.id]]')
			->innerJoin(['drafts' => Table::DRAFTS], '[[drafts.id]] = [[elements.draftId]]')
			->where($condition)
			->andWhere(['elements.dateDeleted' => null, 'drafts.provisional' => false])
			->andWhere(['or', ['not', ['elements.canonicalId' => null]], ['drafts.saved' => true]])
			->count();
	}

	/**
	 * Photos of users among the assets.
	 *
	 * @param int[] $assetIds
	 * @return array<int,int> asset ID by the ID of the user whose photo it is
	 */
	private function photosOf(array $assetIds): array
	{
		return Fill::counts((new Query())
			->select(['id', 'photoId'])
			->from([Table::USERS])
			->where(['photoId' => $assetIds])
			->pairs());
	}

	/**
	 * @template T of ElementInterface
	 * @param array<T> $elements
	 * @param Closure(T):?int $key
	 * @return array<int,list<T>> by the key
	 */
	private function groupBy(array $elements, Closure $key): array
	{
		$grouped = [];

		foreach ($elements as $element) {
			$grouped[$key($element) ?? 0][] = $element;
		}

		return $grouped;
	}

	/**
	 * IDs of the live entries matching the condition, those that aren't deleted, drafts or revisions.
	 *
	 * @param array<string,mixed> $condition on the `entries` table
	 *
	 * @return Query<array-key,mixed>
	 */
	private function liveEntries(array $condition): Query
	{
		return (new Query())
			->select(['entries.id'])
			->from(['entries' => Table::ENTRIES])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
			->where($condition)
			->andWhere(['elements.dateDeleted' => null, 'elements.draftId' => null, 'elements.revisionId' => null]);
	}

	private function number(int $value): string
	{
		return $this->formatter->asInteger($value);
	}

	private function size(int $bytes): string
	{
		return $this->formatter->asShortSize($bytes, 1);
	}
}
