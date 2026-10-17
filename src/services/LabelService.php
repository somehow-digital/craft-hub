<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Label;
use yii\base\Component;

/**
 * Labels editors create to label the items of the lists with, the same labels for all lists. Items are labeled by the list they
 * are in (see ColumnService::LIST_*) and their ID, and can be labeled with several labels; lists can be filtered by them.
 */
class LabelService extends Component
{
	public const TABLE_LABELS = '{{%hub_labels}}';
	public const TABLE_LABELED = '{{%hub_labeled}}';

	/** items without any label, as a value of the labels filter */
	public const LABEL_NONE = 'none';

	/** tables holding the items of each list, by its name, which labels of items no longer in them are removed for */
	public const LISTS = [
		ColumnService::LIST_ENTRIES => Table::ELEMENTS,
		ColumnService::LIST_FILES => Table::ELEMENTS,
		ColumnService::LIST_USERS => Table::ELEMENTS,
		ColumnService::LIST_SECTIONS => Table::SECTIONS,
		ColumnService::LIST_ENTRY_TYPES => Table::ENTRYTYPES,
		ColumnService::LIST_FIELDS => Table::FIELDS,
		ColumnService::LIST_VOLUMES => Table::VOLUMES,
		ColumnService::LIST_USER_GROUPS => Table::USERGROUPS,
	];

	/** @var array<int,Label>|null */
	private ?array $labels = null;

	/**
	 * All labels in their order, by their ID.
	 *
	 * @return array<int,Label>
	 */
	public function getAllLabels(): array
	{
		if ($this->labels !== null) {
			return $this->labels;
		}

		$rows = (new Query())
			->select(['id', 'name', 'icon', 'color', 'sortOrder', 'uid'])
			->from(self::TABLE_LABELS)
			->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
			->all();

		$labels = [];
		foreach ($rows as $row) {
			$labels[(int)$row['id']] = new Label([
				'id' => (int)$row['id'],
				'name' => (string)$row['name'],
				'icon' => $row['icon'] ?: null,
				'color' => $row['color'] ?: null,
				'sortOrder' => (int)$row['sortOrder'],
				'uid' => $row['uid'],
			]);
		}

		return $this->labels = $labels;
	}

	public function getLabelById(int $id): ?Label
	{
		return $this->getAllLabels()[$id] ?? null;
	}

	/**
	 * Whether the current user may create, change, reorder and delete labels; all users with access may label items.
	 */
	public function canManage(): bool
	{
		return Craft::$app->getUser()->checkPermission(Hub::PERMISSION_MANAGE_LABELS);
	}

	/**
	 * Saves a new label, last in the order, or the changes to one.
	 */
	public function saveLabel(Label $label): bool
	{
		$label->icon = $label->icon ?: null;
		$label->color = $label->color ?: null;

		if (!$label->validate()) {
			return false;
		}

		$columns = ['name' => $label->name, 'icon' => $label->icon, 'color' => $label->color];

		if ($label->id === null) {
			$label->sortOrder = (int)(new Query())->from(self::TABLE_LABELS)->max('[[sortOrder]]') + 1;
			$db = Craft::$app->getDb();
			$db->createCommand()->insert(self::TABLE_LABELS, [...$columns, 'sortOrder' => $label->sortOrder])->execute();
			$label->id = (int)$db->getLastInsertID(self::TABLE_LABELS);
		} else {
			Craft::$app->getDb()->createCommand()->update(self::TABLE_LABELS, $columns, ['id' => $label->id])->execute();
		}

		$this->labels = null;

		return true;
	}

	/**
	 * Deletes a label, unlabeling all items labeled with it.
	 */
	public function deleteLabelById(int $id): bool
	{
		$deleted = Craft::$app->getDb()->createCommand()->delete(self::TABLE_LABELS, ['id' => $id])->execute() > 0;
		$this->labels = null;

		return $deleted;
	}

	/**
	 * Orders the labels as the IDs are given.
	 *
	 * @param int[] $ids
	 */
	public function reorderLabels(array $ids): void
	{
		$command = Craft::$app->getDb()->createCommand();

		foreach (array_values($ids) as $order => $id) {
			$command->update(self::TABLE_LABELS, ['sortOrder' => $order + 1], ['id' => (int)$id], updateTimestamp: false)->execute();
		}

		$this->labels = null;
	}

	/**
	 * Number of items labeled with each label, by its ID.
	 *
	 * @return array<int,int>
	 */
	public function getUsageCounts(): array
	{
		return array_map('intval', (new Query())
			->select(['labelId', 'count' => 'COUNT(*)'])
			->from(self::TABLE_LABELED)
			->groupBy(['labelId'])
			->pairs());
	}

	/**
	 * Labels of the items of a list with the IDs, in the order of the labels, by the ID of the item; unlabeled items are left out.
	 *
	 * @param int[] $ids
	 * @return array<int,Label[]>
	 */
	public function getLabelsOf(string $list, array $ids): array
	{
		if ($ids === [] || $this->getAllLabels() === []) {
			return [];
		}

		$labeled = $this->labeledIds($list, $ids);

		return array_filter(array_map(fn (array $labelIds) => $this->labelsIn($labelIds), $labeled));
	}

	/**
	 * IDs of the labels of all labeled items of a list, by the ID of the item, for lists filtered in memory.
	 *
	 * @return array<int,int[]>
	 */
	public function getLabeledIds(string $list): array
	{
		return $this->getAllLabels() !== [] ? $this->labeledIds($list) : [];
	}

	/**
	 * Labels an item of a list with the labels with the IDs, and only with them; unknown labels are left out.
	 *
	 * @param int[] $labelIds
	 * @return int[] IDs of the labels the item is labeled with, in their order
	 */
	public function setLabels(string $list, int $itemId, array $labelIds): array
	{
		$labelIds = array_keys($this->labelsIn($labelIds, true));
		$current = $this->labeledIds($list, [$itemId])[$itemId] ?? [];
		$removed = array_diff($current, $labelIds);
		$added = array_diff($labelIds, $current);

		$command = Craft::$app->getDb()->createCommand();

		if ($removed !== []) {
			$command->delete(self::TABLE_LABELED, ['list' => $list, 'itemId' => $itemId, 'labelId' => array_values($removed)])->execute();
		}

		if ($added !== []) {
			$command->batchInsert(self::TABLE_LABELED, ['labelId', 'list', 'itemId'], array_map(fn (int $labelId) => [$labelId, $list, $itemId], array_values($added)))->execute();
		}

		return $labelIds;
	}

	/**
	 * Adds the labels with the IDs `$add` to the items of a list with the IDs, and removes those with the IDs `$remove`, leaving
	 * their other labels alone; unknown labels are left out, and labels to add aren't removed.
	 *
	 * @param int[] $itemIds
	 * @param int[] $add
	 * @param int[] $remove
	 */
	public function changeLabels(string $list, array $itemIds, array $add, array $remove): void
	{
		$add = array_keys($this->labelsIn($add, true));
		$remove = array_values(array_diff(array_keys($this->labelsIn($remove)), $add));

		if ($itemIds === [] || ($add === [] && $remove === [])) {
			return;
		}

		$command = Craft::$app->getDb()->createCommand();

		if ($remove !== []) {
			$command->delete(self::TABLE_LABELED, ['list' => $list, 'itemId' => $itemIds, 'labelId' => $remove])->execute();
		}

		if ($add === []) {
			return;
		}

		$labeled = $this->labeledIds($list, $itemIds);
		$rows = [];
		foreach ($itemIds as $itemId) {
			foreach (array_diff($add, $labeled[$itemId] ?? []) as $labelId) {
				$rows[] = [$labelId, $list, $itemId];
			}
		}

		if ($rows !== []) {
			$command->batchInsert(self::TABLE_LABELED, ['labelId', 'list', 'itemId'], $rows)->execute();
		}
	}

	/**
	 * Number of the items of a list with the IDs labeled with each label, by its ID; labels of none of them are left out.
	 *
	 * @param int[] $itemIds
	 * @return array<int,int>
	 */
	public function countLabelsOf(string $list, array $itemIds): array
	{
		if ($itemIds === []) {
			return [];
		}

		return array_map('intval', (new Query())
			->select(['labelId', 'count' => 'COUNT(*)'])
			->from(self::TABLE_LABELED)
			->where(['list' => $list, 'itemId' => $itemIds])
			->groupBy(['labelId'])
			->pairs());
	}

	/**
	 * Whether an item of a list with the ID exists, so it can be labeled.
	 */
	public function itemExists(string $list, int $id): bool
	{
		return $this->existingIds($list, [$id]) !== [];
	}

	/**
	 * IDs of the items of a list with the IDs that exist, so they can be labeled.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public function existingIds(string $list, array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$entries = Craft::$app->getEntries();
		$exists = fn (callable $find) => array_values(array_filter($ids, fn (int $id) => $find($id) !== null));

		return array_map('intval', match ($list) {
			ColumnService::LIST_ENTRIES => Entry::find()->id($ids)->site('*')->unique()->status(null)->ids(),
			ColumnService::LIST_FILES => Asset::find()->id($ids)->site('*')->unique()->ids(),
			ColumnService::LIST_USERS => User::find()->id($ids)->status(null)->ids(),
			ColumnService::LIST_SECTIONS => $exists(fn (int $id) => $entries->getSectionById($id)),
			ColumnService::LIST_ENTRY_TYPES => $exists(fn (int $id) => $entries->getEntryTypeById($id)),
			ColumnService::LIST_FIELDS => $exists(fn (int $id) => Craft::$app->getFields()->getFieldById($id)),
			ColumnService::LIST_VOLUMES => $exists(fn (int $id) => Craft::$app->getVolumes()->getVolumeById($id)),
			ColumnService::LIST_USER_GROUPS => $exists(fn (int $id) => Craft::$app->getUserGroups()->getGroupById($id)),
			default => [],
		});
	}

	/**
	 * Values the labels filter of a list can take: the IDs of the labels, and `none` for unlabeled items; none without labels,
	 * as the filter isn't offered then.
	 *
	 * @return string[]
	 */
	public function getFilterValues(): array
	{
		$ids = array_map('strval', array_keys($this->getAllLabels()));

		return $ids !== [] ? [...$ids, self::LABEL_NONE] : [];
	}

	/**
	 * Condition of an element query matching the items of a list labeled with any of the labels, or unlabeled with `none`.
	 *
	 * @param string[] $values values of the labels filter, see {@see getFilterValues()}
	 * @param string $column column holding the ID of the items
	 * @return array<int,mixed>
	 */
	public function condition(string $list, array $values, string $column = 'elements.id'): array
	{
		$ids = array_map('intval', array_values(array_diff($values, [self::LABEL_NONE])));
		$labeled = fn (?array $labelIds) => (new Query())
			->from(['hubLabeled' => self::TABLE_LABELED])
			->where(['hubLabeled.list' => $list])
			->andWhere("[[hubLabeled.itemId]] = [[$column]]")
			->andFilterWhere(['hubLabeled.labelId' => $labelIds]);

		$condition = ['or'];

		if ($ids !== []) {
			$condition[] = ['exists', $labeled($ids)];
		}

		if (in_array(self::LABEL_NONE, $values, true)) {
			$condition[] = ['not exists', $labeled(null)];
		}

		return $condition;
	}

	/**
	 * Whether an item labeled with the labels matches the labels filter, for lists filtered in memory.
	 *
	 * @param string[] $values values of the labels filter, see {@see getFilterValues()}
	 * @param int[] $labelIds IDs of the item's labels
	 */
	public function matches(array $values, array $labelIds): bool
	{
		return $values === []
			|| array_intersect($values, array_map('strval', $labelIds)) !== []
			|| ($labelIds === [] && in_array(self::LABEL_NONE, $values, true));
	}

	/**
	 * Removes the labels of items that no longer exist, as Craft's garbage collection deletes them for good.
	 */
	public function deleteOrphans(): void
	{
		$command = Craft::$app->getDb()->createCommand();

		foreach (self::LISTS as $list => $table) {
			$command->delete(self::TABLE_LABELED, [
				'and',
				['list' => $list],
				['not exists', (new Query())->from(['items' => $table])->where('[[items.id]] = ' . self::TABLE_LABELED . '.[[itemId]]')],
			])->execute();
		}
	}

	/**
	 * IDs of the labels of the items of a list, by the ID of the item, optionally of the items with the IDs only.
	 *
	 * @param int[]|null $ids
	 * @return array<int,int[]>
	 */
	private function labeledIds(string $list, ?array $ids = null): array
	{
		$rows = (new Query())
			->select(['itemId', 'labelId'])
			->from(self::TABLE_LABELED)
			->where(['list' => $list])
			->andFilterWhere(['itemId' => $ids])
			->all();

		$labeled = [];
		foreach ($rows as $row) {
			$labeled[(int)$row['itemId']][] = (int)$row['labelId'];
		}

		return $labeled;
	}

	/**
	 * The labels with the IDs, in the order of the labels, leaving out unknown ones.
	 *
	 * @param int[] $ids
	 * @param bool $fresh whether to read the labels again, as they may have changed since they were read
	 * @return array<int,Label>
	 */
	private function labelsIn(array $ids, bool $fresh = false): array
	{
		if ($fresh) {
			$this->labels = null;
		}

		return array_intersect_key($this->getAllLabels(), array_flip(array_map('intval', $ids)));
	}
}
