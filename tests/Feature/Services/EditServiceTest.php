<?php

use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\services\EditService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->edits = new EditService();
	Craft::$app->getUser()->shouldReceive('getId')->andReturn(3)->byDefault();

	$this->entry = function (array $dirtyAttributes = [], array $dirtyFields = [], array $config = []): Entry {
		$entry = new Entry(['id' => 5, 'siteId' => 1, 'typeId' => 10, ...$config]);
		$entry->setDirtyAttributes($dirtyAttributes, false);
		$entry->setDirtyFields($dirtyFields, false);

		return $entry;
	};

	$this->recorded = fn () => $this->db()->queriesMatching('INTO {{%hub_edits}}');
});

it('records the edits people make to entries, with the user making them', function () {
	$this->edits->record(($this->entry)(['title']), false);
	$this->edits->record(($this->entry)([], ['body']), false);

	expect(($this->recorded)())->toHaveCount(2)
		->and(($this->recorded)()[0])->toContain('(`elementId`, `userId`, `dateEdited`) VALUES (5, 3, ');
});

it('records entries people create, whatever changed', function () {
	$this->edits->record(($this->entry)(), true);

	expect(($this->recorded)())->toHaveCount(1);
});

it('leaves out saves that only change what Craft sets or the status', function () {
	$this->edits->record(($this->entry)(['dateUpdated', 'uri', 'enabled', 'enabledForSite']), false);

	expect(($this->recorded)())->toBe([]);
});

it('leaves out drafts, revisions, propagated and resaved entries', function (array $config) {
	$this->edits->record(($this->entry)(['title'], [], $config), false);

	expect(($this->recorded)())->toBe([]);
})->with([
	'draft' => [['draftId' => 2]],
	'revision' => [['revisionId' => 2]],
	'propagated' => [['propagating' => true]],
	'resaved' => [['resaving' => true]],
]);

it('leaves out saves made without a user', function () {
	Craft::$app->getUser()->shouldReceive('getId')->andReturnNull();
	$this->edits->record(($this->entry)(['title']), false);

	expect(($this->recorded)())->toBe([]);
});

it('leaves out saves made on the command line', function () {
	Craft::$app->getRequest()->setIsConsoleRequest(true);
	$this->edits->record(($this->entry)(['title']), false);

	expect(($this->recorded)())->toBe([]);
});

it('leaves out saves made while the queue runs, as whoever triggered it', function () {
	(function () {
		$this->_checkedRequestType = true;
		$this->_isActionRequest = true;
		$this->_actionSegments = ['queue', 'run'];
	})->call(Craft::$app->getRequest());

	$this->edits->record(($this->entry)(['title']), false);

	expect(($this->recorded)())->toBe([]);
});

it('reads when entries were last edited, falling back to when they were last updated', function () {
	$this->db()->respond('FROM {{%hub_edits}}', [['elementId' => 5, 'userId' => 3, 'dateEdited' => '2020-01-01 10:00:00']]);
	$edited = new Entry(['id' => 5, 'dateUpdated' => new DateTime('2025-01-01')]);
	$unknown = new Entry(['id' => 6, 'dateUpdated' => new DateTime('2025-02-01')]);

	$lastEdited = $this->edits->getLastEdited([$edited, $unknown]);

	expect($lastEdited[5]->format('Y-m-d H:i'))->toBe('2020-01-01 10:00')
		->and($lastEdited[6])->toBe($unknown->dateUpdated)
		->and($this->edits->getLastEdited([]))->toBe([])
		->and($this->db()->queriesMatching('FROM {{%hub_edits}}'))->toHaveCount(1);
});

it('reads when an entry was last edited, and by whom', function () {
	$this->db()->respond('FROM {{%hub_edits}}', [['elementId' => 5, 'userId' => 3, 'dateEdited' => '2020-01-01 10:00:00']]);
	$user = new User(['id' => 3, 'username' => 'editor']);
	ElementQueries::respond(User::class, [$user]);

	$lastEdit = $this->edits->getLastEdit(new Entry(['id' => 5, 'dateUpdated' => new DateTime('2025-01-01')]));

	expect($lastEdit['date']->format('Y-m-d'))->toBe('2020-01-01')
		->and($lastEdit['user'])->toBe($user)
		->and(ElementQueries::executed(User::class)[0]->id)->toBe(3);
});

it('reads the last edit of entries without a record or user as their last update', function () {
	$this->db()->respond('FROM {{%hub_edits}}', [['elementId' => 6, 'userId' => null, 'dateEdited' => '2020-01-01 10:00:00']]);
	$entry = new Entry(['id' => 5, 'dateUpdated' => new DateTime('2025-01-01')]);

	expect($this->edits->getLastEdit($entry))->toBe(['date' => $entry->dateUpdated, 'user' => null])
		->and(ElementQueries::executed(User::class))->toBe([]);
});

it('matches entries by when they were last edited, falling back to when they were last updated', function () {
	$sql = $this->edits->lastEditedSql();

	expect($sql)->toStartWith('COALESCE((SELECT `hubEdits`.`dateEdited`')
		->and($sql)->toContain('`hubEdits`.`elementId` = `elements`.`id`')
		->and($sql)->toEndWith(', [[elements.dateUpdated]])')
		->and($this->edits->lastEditedCondition('< 2024-01-01T00:00:00+00:00'))->toBe(['<', $sql, '2024-01-01 00:00:00'])
		->and($this->edits->lastEditedCondition(null))->toBeNull();
});

it('records the last edits of existing entries from their revisions and tracked changes', function () {
	$this->db()->respond('FROM {{%revisions}} {{revisions}}', [
		['elementId' => 5, 'userId' => 3, 'date' => '2024-03-01 00:00:00'],
		['elementId' => 5, 'userId' => 4, 'date' => '2024-05-01 00:00:00'],
	]);
	$this->db()->respond('FROM {{%changedattributes}}', [['elementId' => 5, 'userId' => 7, 'date' => '2024-04-01 00:00:00']]);
	$this->db()->respond('FROM {{%changedfields}}', [['elementId' => 6, 'userId' => 7, 'date' => '2024-06-01 00:00:00']]);
	$this->db()->respond('FROM {{%elements}} {{elements}}', [
		['elementId' => 5, 'date' => '2025-01-01 00:00:00'],
		['elementId' => 6, 'date' => '2025-01-01 00:00:00'],
		['elementId' => 8, 'date' => '2023-01-01 00:00:00'],
	]);

	$this->edits->backfill();

	$changed = $this->db()->queriesMatching('FROM {{%changedattributes}}')[0];
	$entries = $this->db()->queriesMatching('FROM {{%elements}} {{elements}}')[0];
	$inserted = $this->db()->queriesMatching('INSERT INTO {{%hub_edits}}');

	expect($this->db()->queriesMatching('FROM {{%revisions}} {{revisions}}')[0])->toContain('NOT (`revisions`.`creatorId` IS NULL)')
		->and($changed)->toContain('NOT (`userId` IS NULL)')
		->and($changed)->toContain("NOT (`attribute` IN ('id', ")
		->and($changed)->toContain("'enabled'")
		->and($entries)->toContain('`elements`.`draftId` IS NULL')
		->and($entries)->toContain('NOT EXISTS (SELECT *')
		->and($inserted)->toHaveCount(1)
		// The newest edit by a user, or the last update of entries Craft knows no edits of.
		->and($inserted[0])->toContain("(5, 4, '2024-05-01 00:00:00'), (6, 7, '2024-06-01 00:00:00'), (8, NULL, '2023-01-01 00:00:00')");
});
