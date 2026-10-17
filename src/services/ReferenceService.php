<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\NestedElementInterface;
use craft\db\Query;
use craft\db\Table;
use SomehowDigital\Craft\Hub\models\EntryReference;
use yii\base\Component;

/**
 * Finds the elements that reference a given entry or asset.
 *
 * Only relations are taken into account; reference tags in rich text aren't.
 * References from nested entries are attributed to their root owner, so the list shows the elements editors know.
 */
class ReferenceService extends Component
{
	/**
	 * Elements that relate to the element through any relation field (Entries, Assets, Link, …).
	 *
	 * @return EntryReference[]
	 */
	public function getRelations(ElementInterface $entry): array
	{
		$rows = (new Query())
			->select([
				'id' => 'relations.sourceId',
				'type' => 'sources.type',
				'fieldId' => 'relations.fieldId',
			])
			->distinct()
			->from(['relations' => Table::RELATIONS])
			->innerJoin(['sources' => Table::ELEMENTS], '[[sources.id]] = [[relations.sourceId]]')
			->where(['relations.targetId' => $entry->getCanonicalId()])
			->andWhere([
				'sources.dateDeleted' => null,
				'sources.draftId' => null,
				'sources.revisionId' => null,
			])
			->all();

		return $this->collect($entry, $rows);
	}

	/**
	 * Number of live relations targeting each of the given elements.
	 *
	 * @param int[] $elementIds
	 * @return array<int,int> element ID => relations
	 */
	public function getRelationCounts(array $elementIds): array
	{
		if ($elementIds === []) {
			return [];
		}

		$rows = (new Query())
			->select(['elementId' => 'relations.targetId', 'relations' => 'COUNT(*)'])
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

		$counts = [];
		foreach ($rows as $row) {
			$counts[(int)$row['elementId']] = (int)$row['relations'];
		}

		return $counts;
	}

	/**
	 * Loads the referencing elements and groups them by root owner.
	 *
	 * @param array<int,array<string,mixed>> $rows with the source element's `id`, `type` and `fieldId`
	 * @return EntryReference[]
	 */
	private function collect(ElementInterface $entry, array $rows): array
	{
		$sources = $this->loadElements($rows, $entry->siteId);
		$references = [];

		foreach ($rows as $row) {
			$source = $sources[(int)$row['id']] ?? null;

			if ($source === null) {
				continue;
			}

			$path = [];
			$root = $this->resolveRoot($source, $path);

			if ($root === null || $root->id === $entry->getCanonicalId()) {
				continue;
			}

			$fieldId = (int)$row['fieldId'];
			$field = $source->getFieldLayout()?->getFieldById($fieldId) ?? Craft::$app->getFields()->getFieldById($fieldId);
			$path[] = $field !== null ? Craft::t('site', $field->name) : Craft::t('hub', 'Unknown field');

			$references[$root->id] ??= new EntryReference($root);
			$references[$root->id]->addPath(implode(' → ', $path), $field);
		}

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
