<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Label;
use yii\base\Component;

/**
 * Labels editors create to label the items of the lists with, the same labels for all lists. Items are labeled by the list they
 * are in (see ColumnService::LIST_*) and their ID, and can be labeled with several labels; lists can be filtered by them.
 * Labels saved, deleted and cleared, and items labeled, are recorded in the activity log.
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
	 * Whether labels are shown in the lists, which can then be labeled and filtered by them (see Settings::$enableLabels).
	 */
	public function isEnabled(): bool
	{
		return Hub::getInstance()->settings->enableLabels;
	}

	/**
	 * Whether the current user may label the items of a list, while labels are enabled, as they may label the items of
	 * its page (see Page).
	 */
	public function canApply(string $list): bool
	{
		return $this->isEnabled()
			&& isset(self::LISTS[$list])
			&& ($page = Page::fromList($list)) !== null
			&& Hub::getInstance()->access->canLabel($page);
	}

	/**
	 * Whether the current user may create, change, reorder and delete labels; labeling items is up to canApply().
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
		$isNew = $label->id === null;

		if ($isNew) {
			$label->sortOrder = (int)(new Query())->from(self::TABLE_LABELS)->max('[[sortOrder]]') + 1;
			$db = Craft::$app->getDb();
			$db->createCommand()->insert(self::TABLE_LABELS, [...$columns, 'sortOrder' => $label->sortOrder])->execute();
			$label->id = (int)$db->getLastInsertID(self::TABLE_LABELS);
		} else {
			Craft::$app->getDb()->createCommand()->update(self::TABLE_LABELS, $columns, ['id' => $label->id])->execute();
		}

		$this->labels = null;
		$this->record($isNew ? ActivityAction::Created : ActivityAction::Updated, ActivitySubject::Label, $label->id, $label->name);

		return true;
	}

	/**
	 * Deletes a label, unlabeling all items labeled with it.
	 */
	public function deleteLabelById(int $id): bool
	{
		$name = $this->getLabelById($id)?->name;
		$deleted = Craft::$app->getDb()->createCommand()->delete(self::TABLE_LABELS, ['id' => $id])->execute() > 0;
		$this->labels = null;

		if ($deleted) {
			$this->record(ActivityAction::Deleted, ActivitySubject::Label, $id, $name);
		}

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
	 * Removes a label from all items labeled with it, keeping the label.
	 *
	 * @return int number of items it was removed from
	 */
	public function clearLabelById(int $id): int
	{
		$count = Craft::$app->getDb()->createCommand()->delete(self::TABLE_LABELED, ['labelId' => $id])->execute();

		if ($count > 0) {
			$this->record(ActivityAction::Cleared, ActivitySubject::Label, $id, $this->getLabelById($id)?->name, ['count' => $count]);
		}

		return $count;
	}

	/**
	 * Number of the items of each list labeled with each label, by the ID of the label and the name of the list, in the order
	 * of the lists (see {@see LISTS}); lists without any of its items are left out.
	 *
	 * @return array<int,array<string,int>>
	 */
	public function getUsageByList(): array
	{
		$rows = (new Query())
			->select(['labelId', 'list', 'count' => 'COUNT(*)'])
			->from(self::TABLE_LABELED)
			->groupBy(['labelId', 'list'])
			->all();

		$usage = [];
		foreach ($rows as $row) {
			$usage[(int)$row['labelId']][(string)$row['list']] = (int)$row['count'];
		}

		$lists = array_fill_keys(array_keys(self::LISTS), 0);

		return array_map(fn (array $counts) => array_filter(array_intersect_key(array_replace($lists, $counts), $lists)), $usage);
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

		if (($added !== [] || $removed !== []) && ($subject = ActivitySubject::fromList($list)) !== null) {
			$this->record(ActivityAction::Labeled, $subject, $itemId, $this->itemTitle($list, $itemId), $this->labelChanges($added, $removed));
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
		$labeled = $this->labeledIds($list, $itemIds);

		if ($remove !== []) {
			$command->delete(self::TABLE_LABELED, ['list' => $list, 'itemId' => $itemIds, 'labelId' => $remove])->execute();
		}

		$rows = [];
		$changes = [];
		foreach ($itemIds as $itemId) {
			$added = array_diff($add, $labeled[$itemId] ?? []);
			$removed = array_intersect($remove, $labeled[$itemId] ?? []);

			foreach ($added as $labelId) {
				$rows[] = [$labelId, $list, $itemId];
			}

			if ($added !== [] || $removed !== []) {
				$changes[$itemId] = [$added, $removed];
			}
		}

		if ($rows !== []) {
			$command->batchInsert(self::TABLE_LABELED, ['labelId', 'list', 'itemId'], $rows)->execute();
		}

		// Each item changed is recorded on its own, like when labeled alone; a bulk action records them as its batch.
		if ($changes !== [] && ($subject = ActivitySubject::fromList($list)) !== null) {
			$titles = $this->itemTitles($list, array_keys($changes));

			foreach ($changes as $itemId => [$added, $removed]) {
				$this->record(ActivityAction::Labeled, $subject, $itemId, $titles[$itemId] ?? null, $this->labelChanges($added, $removed));
			}
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
	 * Values the labels filter of a list can take: the IDs of the labels, and `none` for unlabeled items; none without labels
	 * or while labels are disabled, as the filter isn't offered then.
	 *
	 * @return string[]
	 */
	public function getFilterValues(): array
	{
		if (!$this->isEnabled()) {
			return [];
		}

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
	 * Names of the labels added and removed, as kept along with the activity of labeling.
	 *
	 * @param int[] $added
	 * @param int[] $removed
	 * @return array{added?:string[],removed?:string[]}
	 */
	private function labelChanges(array $added, array $removed): array
	{
		$names = fn (array $ids) => array_values(array_map(fn (Label $label) => $label->name, $this->labelsIn($ids)));

		return array_filter(['added' => $names($added), 'removed' => $names($removed)]);
	}

	/**
	 * Name of an item of a list, as the activity log names it.
	 */
	private function itemTitle(string $list, int $id): ?string
	{
		return $this->itemTitles($list, [$id])[$id] ?? null;
	}

	/**
	 * Names of the items of a list with the IDs, by their ID, as the activity log names them; elements are queried at once.
	 *
	 * @param int[] $ids
	 * @return array<int,string|null>
	 */
	private function itemTitles(string $list, array $ids): array
	{
		$entries = Craft::$app->getEntries();
		$elements = match ($list) {
			ColumnService::LIST_ENTRIES => Entry::find()->id($ids)->site('*')->unique()->status(null)->all(),
			ColumnService::LIST_FILES => Asset::find()->id($ids)->site('*')->unique()->all(),
			ColumnService::LIST_USERS => User::find()->id($ids)->status(null)->all(),
			default => null,
		};

		if ($elements !== null) {
			$titles = [];
			foreach ($elements as $element) {
				$titles[(int)$element->id] = $element instanceof User ? $element->getName() : $element->getUiLabel();
			}

			return $titles;
		}

		return array_combine($ids, array_map(fn (int $id) => match ($list) {
			ColumnService::LIST_SECTIONS => $entries->getSectionById($id)?->name,
			ColumnService::LIST_ENTRY_TYPES => $entries->getEntryTypeById($id)?->name,
			ColumnService::LIST_FIELDS => Craft::$app->getFields()->getFieldById($id)?->name,
			ColumnService::LIST_VOLUMES => Craft::$app->getVolumes()->getVolumeById($id)?->name,
			ColumnService::LIST_USER_GROUPS => Craft::$app->getUserGroups()->getGroupById($id)?->name,
			default => null,
		}, $ids));
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function record(ActivityAction $action, ActivitySubject $subject, ?int $id = null, ?string $title = null, array $data = []): void
	{
		$activity = Hub::getInstance()->activity;
		$activity->record($action, $subject, $id, $title, $data);
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
