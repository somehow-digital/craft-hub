<?php

use craft\elements\Entry;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\Label;
use SomehowDigital\Craft\Hub\models\UserGroupFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->labels = new LabelService();

	// Review, then Keep, as they are ordered; Keep has neither an icon nor a color.
	$this->db()->respond('FROM {{%hub_labels}}', [
		['id' => 2, 'name' => 'Review', 'icon' => 'eye', 'color' => 'red', 'sortOrder' => 1, 'uid' => 'label-review'],
		['id' => 1, 'name' => 'Keep', 'icon' => null, 'color' => null, 'sortOrder' => 2, 'uid' => 'label-keep'],
	]);
});

/**
 * Labels the items of lists, as `list => [itemId => labelIds]`, for the queries reading them.
 *
 * @param array<string,array<int,int[]>> $lists
 */
function labeled(array $lists): void
{
	test()->db()->respond('FROM {{%hub_labeled}}', function (string $sql) use ($lists) {
		$rows = [];
		foreach ($lists as $list => $labeled) {
			foreach (str_contains($sql, "'$list'") ? $labeled : [] as $itemId => $labelIds) {
				foreach ($labelIds as $labelId) {
					$rows[] = ['itemId' => $itemId, 'labelId' => $labelId];
				}
			}
		}

		return $rows;
	});
}

it('reads the labels in their order, by their ID', function () {
	$labels = $this->labels->getAllLabels();

	expect(array_keys($labels))->toBe([2, 1])
		->and($labels[2]->name)->toBe('Review')
		->and($labels[2]->getIcon())->toBe('eye')
		->and($labels[2]->getColor())->toBe('red')
		->and($labels[1]->getIcon())->toBe('label')
		->and($labels[1]->getColor())->toBe('gray')
		->and($this->labels->getLabelById(1)?->uid)->toBe('label-keep')
		->and($this->labels->getLabelById(9))->toBeNull()
		->and($this->db()->queriesMatching('{{%hub_labels}}'))->toHaveCount(1);
});

it('reads the labels of the items of a list in the order of the labels', function () {
	labeled([ColumnService::LIST_ENTRIES => [5 => [1, 2], 6 => [1], 7 => [9]]]);

	$labels = $this->labels->getLabelsOf(ColumnService::LIST_ENTRIES, [5, 6, 7]);

	expect(array_keys($labels))->toBe([5, 6])
		->and(array_map(fn (Label $label) => $label->name, array_values($labels[5])))->toBe(['Review', 'Keep'])
		->and($this->db()->queriesMatching("`list`='entries'")[0])->toContain('`itemId` IN (5, 6, 7)')
		->and($this->labels->getLabelsOf(ColumnService::LIST_ENTRIES, []))->toBe([]);
});

it('reads no labeled items while there are no labels', function () {
	$this->db()->respond('FROM {{%hub_labels}}', []);

	expect($this->labels->getLabelsOf(ColumnService::LIST_ENTRIES, [5]))->toBe([])
		->and($this->labels->getLabeledIds(ColumnService::LIST_SECTIONS))->toBe([])
		->and($this->db()->queriesMatching('{{%hub_labeled}}'))->toBe([]);
});

it('offers the labels and unlabeled items as filter values', function () {
	expect($this->labels->getFilterValues())->toBe(['2', '1', 'none']);
});

it('offers no filter values without labels', function () {
	$this->db()->respond('FROM {{%hub_labels}}', []);

	expect($this->labels->getFilterValues())->toBe([]);
});

it('matches items by any of their labels, or unlabeled ones', function (array $values, array $labelIds, bool $matches) {
	expect($this->labels->matches($values, $labelIds))->toBe($matches);
})->with([
	'no filter' => [[], [1], true],
	'any label' => [['1', '2'], [2], true],
	'other label' => [['1'], [2], false],
	'unlabeled' => [['none'], [], true],
	'labeled, not unlabeled' => [['none'], [1], false],
	'unlabeled or label' => [['none', '1'], [1], true],
]);

it('builds a condition matching items by their labels, or unlabeled ones', function () {
	[$or, [$exists, $labeled], [$notExists, $unlabeled]] = $this->labels->condition(ColumnService::LIST_FILES, ['2', 'none']);

	expect($or)->toBe('or')
		->and($exists)->toBe('exists')
		->and($notExists)->toBe('not exists')
		->and(rawSql($labeled))->toContain("`hubLabeled`.`list`='files'")
		->and(rawSql($labeled))->toContain('`hubLabeled`.`itemId` = `elements`.`id`')
		->and(rawSql($labeled))->toContain('`hubLabeled`.`labelId`=2')
		->and(rawSql($unlabeled))->not->toContain('labelId')
		->and($this->labels->condition(ColumnService::LIST_FILES, ['1']))->toHaveCount(2);
});

it('filters the entries by their labels', function () {
	$this->site(1, 'en', primary: true);
	$filter = new EntryFilter();
	$filter->labels = ['1'];
	[, [$or, [$exists, $labeled]]] = conditions((new EntryService())->getQuery($filter));

	expect($or)->toBe('or')
		->and($exists)->toBe('exists')
		->and(rawSql($labeled))->toContain("`hubLabeled`.`list`='entries'");
});

it('filters the lists filtered in memory by their labels', function () {
	$this->createContentModel();
	$this->userGroup(1, 'editors');
	$this->userGroup(2, 'authors');
	labeled([ColumnService::LIST_SECTIONS => [1 => [2], 3 => [1]], ColumnService::LIST_USER_GROUPS => [2 => [1]]]);
	Hub::getInstance()->set('labels', $this->labels);
	$lists = new ListService();

	$sections = function (array $labels) use ($lists) {
		$filter = new SectionFilter();
		$filter->labels = $labels;

		return array_keys($lists->getSections($filter));
	};
	$filter = new UserGroupFilter();
	$filter->labels = ['1'];
	$groups = array_keys($lists->getUserGroups(Hub::getInstance()->users->getGroups(), $filter));

	expect($sections(['2']))->toBe([1])
		->and($sections(['1', '2']))->toEqualCanonicalizing([1, 3])
		->and($sections(['none']))->toEqualCanonicalizing([2, 4])
		->and($groups)->toBe([2]);
});

it('only reads the labeled items of lists filtered by labels', function () {
	$this->createContentModel();
	Hub::getInstance()->set('labels', $this->labels);

	(new ListService())->getSections(new SectionFilter());

	expect($this->db()->queriesMatching('{{%hub_labeled}}'))->toBe([]);
});

it('labels an item with the known labels only, adding and removing the changed ones', function () {
	labeled([ColumnService::LIST_SECTIONS => [3 => [1]]]);

	$labels = $this->labels->setLabels(ColumnService::LIST_SECTIONS, 3, [2, 9]);

	expect($labels)->toBe([2])
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_labeled}}')[0])->toContain('`labelId`=1')
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_labeled}}')[0])->toContain("(2, 'sections', 3");
});

it('leaves an item with the same labels alone', function () {
	labeled([ColumnService::LIST_SECTIONS => [3 => [1]]]);

	expect($this->labels->setLabels(ColumnService::LIST_SECTIONS, 3, [1]))->toBe([1])
		->and($this->db()->queriesMatching('DELETE FROM'))->toBe([])
		->and($this->db()->queriesMatching('INSERT INTO'))->toBe([]);
});

it('adds and removes labels of items, leaving their other labels alone', function () {
	labeled([ColumnService::LIST_SECTIONS => [3 => [1], 4 => []]]);

	$this->labels->changeLabels(ColumnService::LIST_SECTIONS, [3, 4], [1, 9], [2, 1]);
	$delete = $this->db()->queriesMatching('DELETE FROM {{%hub_labeled}}')[0];
	$insert = $this->db()->queriesMatching('INSERT INTO {{%hub_labeled}}')[0];

	expect($delete)->toContain('`labelId`=2')
		->and($delete)->toContain('`itemId` IN (3, 4)')
		->and($insert)->toContain("(1, 'sections', 4)")
		->and($insert)->not->toContain("(1, 'sections', 3)");
});

it('changes no labels without items or labels to change', function () {
	$this->labels->changeLabels(ColumnService::LIST_SECTIONS, [], [1], []);
	$this->labels->changeLabels(ColumnService::LIST_SECTIONS, [3], [9], []);

	expect($this->db()->queriesMatching('{{%hub_labeled}}'))->toBe([]);
});

it('counts the items of a list labeled with each label', function () {
	$this->db()->respond('COUNT(*)', [['labelId' => 1, 'count' => '2']]);

	expect($this->labels->countLabelsOf(ColumnService::LIST_ENTRIES, [5, 6]))->toBe([1 => 2])
		->and($this->db()->queriesMatching('COUNT(*)')[0])->toContain('`itemId` IN (5, 6)')
		->and($this->labels->countLabelsOf(ColumnService::LIST_ENTRIES, []))->toBe([]);
});

it('saves a new label last, and the changes to one', function () {
	$this->db()->respond('MAX([[sortOrder]])', 4);
	$label = new Label(['name' => ' Later ', 'icon' => '', 'color' => 'blue']);

	expect($this->labels->saveLabel($label))->toBeTrue()
		->and($label->name)->toBe('Later')
		->and($label->icon)->toBeNull()
		->and($label->sortOrder)->toBe(5)
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_labels}}')[0])->toContain("'Later', NULL, 'blue', 5");

	$label->id = 3;
	$label->name = 'Soon';

	expect($this->labels->saveLabel($label))->toBeTrue()
		->and($this->db()->queriesMatching('UPDATE {{%hub_labels}}')[0])->toContain("`name`='Soon'");
});

it('rejects labels without a name or with an unknown color', function (array $values, string $attribute) {
	$label = new Label($values);

	expect($this->labels->saveLabel($label))->toBeFalse()
		->and($label->hasErrors($attribute))->toBeTrue()
		->and($this->db()->queriesMatching('INSERT INTO'))->toBe([]);
})->with([
	'no name' => [['name' => '  '], 'name'],
	'unknown color' => [['label' => 'Keep', 'color' => 'plaid'], 'color'],
]);

it('deletes and reorders labels', function () {
	$this->labels->deleteLabelById(2);
	$this->labels->reorderLabels([1, 2]);

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_labels}}')[0])->toContain('`id`=2')
		->and($this->db()->queriesMatching('UPDATE {{%hub_labels}}'))->toHaveCount(2)
		->and($this->db()->queriesMatching('UPDATE {{%hub_labels}}')[0])->toContain('`sortOrder`=1')
		->and($this->db()->queriesMatching('UPDATE {{%hub_labels}}')[0])->toContain('`id`=1');
});

it('counts the items labeled with each label', function () {
	$this->db()->respond('GROUP BY [[labelId]]', [['labelId' => 2, 'count' => '3']]);

	expect($this->labels->getUsageCounts())->toBe([2 => 3]);
});

it('removes the labels of items that no longer exist', function () {
	$this->labels->deleteOrphans();
	$deletes = $this->db()->queriesMatching('DELETE FROM {{%hub_labeled}}');

	expect($deletes)->toHaveCount(count(LabelService::LISTS))
		->and($deletes[0])->toContain("`list`='entries'")
		->and($deletes[0])->toContain('NOT EXISTS (SELECT *')
		->and($deletes[0])->toContain('`items`.`id` = `hub_labeled`.`itemId`')
		->and($deletes[3])->toContain('FROM `sections` `items`');
});

it('tells whether an item of a list exists', function () {
	$this->createContentModel();
	$this->userGroup(1, 'editors');
	Craft::$app->getUserGroups()->shouldReceive('getGroupById')->andReturnUsing(fn (int $id) => $this->userGroups[$id] ?? null);
	ElementQueries::respond(Entry::class, fn ($query) => (array)$query->id === [5] ? [new Entry(['id' => 5])] : []);

	expect($this->labels->itemExists(ColumnService::LIST_ENTRIES, 5))->toBeTrue()
		->and($this->labels->itemExists(ColumnService::LIST_ENTRIES, 6))->toBeFalse()
		->and($this->labels->itemExists(ColumnService::LIST_FILES, 6))->toBeFalse()
		->and($this->labels->itemExists(ColumnService::LIST_USERS, 6))->toBeFalse()
		->and($this->labels->itemExists(ColumnService::LIST_SECTIONS, 1))->toBeTrue()
		->and($this->labels->itemExists(ColumnService::LIST_ENTRY_TYPES, 10))->toBeTrue()
		->and($this->labels->itemExists(ColumnService::LIST_FIELDS, 99))->toBeFalse()
		->and($this->labels->itemExists(ColumnService::LIST_VOLUMES, 1))->toBeTrue()
		->and($this->labels->itemExists(ColumnService::LIST_USER_GROUPS, 1))->toBeTrue()
		->and($this->labels->itemExists('bogus', 1))->toBeFalse()
		->and($this->labels->existingIds(ColumnService::LIST_USER_GROUPS, [1, 2]))->toBe([1])
		->and($this->labels->existingIds(ColumnService::LIST_ENTRIES, []))->toBe([]);
});

it('lets users with the permission manage the labels', function () {
	expect($this->labels->canManage())->toBeTrue();

	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS]);

	expect($this->labels->canManage())->toBeFalse();
});
