<?php

declare(strict_types=1);

use craft\helpers\DateTimeHelper;
use craft\queue\Queue;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\jobs\TakeSnapshotJob;
use SomehowDigital\Craft\Hub\models\Snapshot;

beforeEach(function (): void {
	$this->createContentModel();

	// The tables of the snapshots exist, which the fake connection can't tell.
	$this->db()->tables = [Tables::SNAPSHOTS];

	// The content model's relations aren't counted by the site of their source.
	$this->db()->respond('`targetAssets`.`volumeId`, `relations`.`sourceSiteId`', []);

	DateTimeHelper::pause(new DateTime('2026-10-27 12:00:00', new DateTimeZone(Craft::$app->getTimeZone())));

	$this->sectionCount = fn(Snapshot $snapshot): int => count($snapshot->getSections());
});

afterEach(function (): void {
	DateTimeHelper::resume();
});

it('takes the snapshot of today with the counts of all sites', function (): void {
	$this->db()->respond('GROUP BY `sectionId`, `typeId`, `fieldId`, `sites`, `enabledSites`', [
		['sectionId' => '1', 'typeId' => '10', 'fieldId' => null, 'sites' => '2,1', 'enabledSites' => '1', 'total' => '3', 'enabled' => '2', 'pending' => '0', 'expired' => '0', 'drafts' => '1', 'lastUpdated' => '2026-10-01 10:00:00'],
	]);

	expect($this->hub()->snapshots->take())->toBeTrue();

	$inserts = $this->db()->queriesMatching('INSERT INTO {{%hub_snapshots}}');
	$rows = $this->db()->queriesMatching('INSERT INTO {{%hub_snapshot_entries}}');

	expect($inserts)->toHaveCount(1)
		->and($inserts[0])->toContain("'2026-10-27'")
		->and($inserts[0])->toContain('\\"sections\\":{\\"4\\":{\\"type\\":\\"channel\\"')
		->and($rows)->toHaveCount(1)
		->and($rows[0])->toContain("'1,2'")
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_snapshot_assets}}'))->toBe([])
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_snapshots}}'))->toBe([]);
});

it('keeps the snapshot of today, unless it is replaced', function (bool $replace): void {
	$this->db()->respond("{{%hub_snapshots}}\nWHERE `date`='2026-10-27'", 7);

	expect($this->hub()->snapshots->take($replace))->toBe($replace)
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_snapshots}}'))->toHaveCount($replace ? 1 : 0)
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_snapshots}}'))->toHaveCount($replace ? 1 : 0);
})->with([true, false]);

it('counts what is not scoped to sites along with the snapshot', function (): void {
	$metrics = $this->hub()->snapshots->collectMetrics();

	expect($metrics)->toHaveKeys(['sections', 'entryTypes', 'fields', 'volumes', 'users', 'userGroups'])
		->and($metrics['users'])->toHaveKeys(['total', 'active', 'admins'])
		->and($metrics['sections'])->toHaveCount(4)
		->and(at($metrics, 'sections', 1))->toHaveKeys(['type', 'entryTypes', 'fields'])
		->and($metrics['volumes'])->toHaveCount(count($this->volumes))
		->and($metrics['userGroups'])->toBeArray();
});

it('is due without a snapshot, or once the last one is as old as the interval', function (?string $latest, int $interval, bool $due): void {
	$this->hub()->settings->snapshotInterval = $interval;
	$this->db()->respond('MAX(`date`)', $latest);

	expect($this->hub()->snapshots->isDue())->toBe($due);
})->with([
	[null, 1, true],
	['2026-10-27', 1, false],
	['2026-10-26', 1, true],
	['2026-10-26', 7, false],
	['2026-10-20', 7, true],
]);

it('pushes a snapshot once one is due, checking at most once an hour', function (): void {
	$this->db()->respond('MAX(`date`)', '2026-10-20');
	$this->mockComponent('queue', Queue::class)->makePartial()->shouldReceive('push')->once()->with(Mockery::type(TakeSnapshotJob::class));

	$this->hub()->snapshots->pushIfDue();
	$this->hub()->snapshots->pushIfDue();

	expect($this->db()->queriesMatching('SELECT MAX(`date`)'))->toHaveCount(1);
});

it('pushes no snapshot while none is due, snapshots are disabled or their tables are missing', function (bool $enabled, bool $tables, ?string $latest): void {
	$this->hub()->settings->enableSnapshots = $enabled;
	$this->db()->tables = $tables ? [Tables::SNAPSHOTS] : [];
	$this->db()->respond('MAX(`date`)', $latest);
	$this->mockComponent('queue', Queue::class)->makePartial()->shouldNotReceive('push');

	$this->hub()->snapshots->pushIfDue();
})->with([
	[true, true, '2026-10-27'],
	[false, true, null],
	[true, false, null],
]);

it('reads the snapshots since a day, each with the statistics of its rows', function (): void {
	respondSnapshots($this, [[1, '2026-10-20'], [2, '2026-10-26']]);
	$this->db()->respond('FROM {{%hub_snapshot_entries}}', [
		['snapshotId' => '1', 'sectionId' => '1', 'typeId' => '10', 'fieldId' => null, 'sites' => '1', 'enabledSites' => '1', 'total' => '2', 'enabled' => '2', 'pending' => '0', 'expired' => '0', 'drafts' => '0', 'lastUpdated' => null],
		['snapshotId' => '2', 'sectionId' => '1', 'typeId' => '10', 'fieldId' => null, 'sites' => '1,2', 'enabledSites' => '2', 'total' => '5', 'enabled' => '5', 'pending' => '1', 'expired' => '0', 'drafts' => '0', 'lastUpdated' => null],
	]);

	/** @var list<Snapshot> $snapshots */
	$snapshots = $this->hub()->snapshots->getSnapshots(new DateTime('2026-10-01'));
	$this->hub()->snapshots->getSnapshots(new DateTime('2026-10-01'));

	expect($snapshots)->toHaveCount(2)
		->and($snapshots[0])->toBeInstanceOf(Snapshot::class)
		->and($snapshots[0]->date->format('Y-m-d'))->toBe('2026-10-20')
		->and($snapshots[1]->getSections())->toHaveCount(2)
		->and($snapshots[1]->getUsers()->total)->toBe(4)
		->and(Hub::getInstance()->snapshots->getStatistics($snapshots[0])->sumEntries([1])->total)->toBe(2)
		->and(Hub::getInstance()->snapshots->getStatistics($snapshots[1])->sumEntries([1])->enabled)->toBe(0)
		->and(Hub::getInstance()->snapshots->getStatistics($snapshots[1])->sumEntries([2])->pending)->toBe(1)
		->and($this->db()->queriesMatching('FROM {{%hub_snapshots}}'))->toHaveCount(2)
		->and($this->db()->queriesMatching('FROM {{%hub_snapshot_entries}}'))->toHaveCount(1);
});

it('reads only the parts of the snapshots asked for, for all of them at once', function (): void {
	respondSnapshots($this, [[1, '2026-10-20'], [2, '2026-10-26']]);

	/** @var list<Snapshot> $snapshots */
	$snapshots = $this->hub()->snapshots->getSnapshots(null);

	expect($this->db()->queriesMatching('`metrics`'))->toBe([]);

	$snapshots[0]->getUsers();
	$snapshots[1]->getSections();
	Hub::getInstance()->snapshots->getStatistics($snapshots[1])->sumEntries([1]);

	expect($this->db()->queriesMatching('`metrics`'))->toHaveCount(1)
		->and($this->db()->queriesMatching('FROM {{%hub_snapshot_entries}}'))->toHaveCount(1)
		->and($this->db()->queriesMatching('FROM {{%hub_snapshot_assets}}'))->toBe([])
		->and($this->db()->queriesMatching('FROM {{%hub_snapshot_references}}'))->toBe([]);
});

it('reads no rows without snapshots', function (): void {
	expect($this->hub()->snapshots->getSnapshots(new DateTime('2026-10-01')))->toBe([])
		->and($this->db()->queriesMatching('FROM {{%hub_snapshot_entries}}'))->toBe([]);
});

it('charts a card over the snapshots of a range, ending with its current values', function (): void {
	respondSnapshots($this, [[3, '2026-10-20'], [5, '2026-10-26'], [9, '2026-10-27']]);

	$chart = present($this->hub()->snapshots->chart(fn(Snapshot $snapshot): array => [
		'total' => ($this->sectionCount)($snapshot),
		'segments' => [['label' => 'Empty', 'value' => ($this->sectionCount)($snapshot) - 1]],
	], [
		'label' => 'Sections',
		'total' => 8,
		'segments' => [
			['label' => 'Empty', 'value' => 2, 'color' => 'gray'],
			['label' => 'Drafts', 'value' => 1, 'color' => 'amber', 'bar' => false],
			// Not in any snapshot, so not charted.
			['label' => 'New', 'value' => 1, 'color' => 'blue'],
		],
	]));

	expect($chart['range'])->toBe(30)
		->and($chart['ranges'])->toBe([30])
		->and($chart['dates'])->toBe(['2026-10-20', '2026-10-26', '2026-10-27'])
		->and($chart['change'])->toBe(5)
		->and($chart['since']?->format('Y-m-d'))->toBe('2026-10-20')
		->and(array_column(arrayAt($chart, 'series'), 'key'))->toBe(['total', 'empty'])
		->and($chart['series'][0]['values'])->toBe([3, 5, 8])
		->and($chart['series'][1])->toMatchArray(['label' => 'Empty', 'color' => 'gray', 'dashed' => false, 'values' => [2, 4, 2]])
		->and($this->db()->queriesMatching(">= '2026-09-27'"))->toHaveCount(1);
});

it('charts the values of segments missing from some snapshots as gaps, and those kept out of the bar dashed', function (): void {
	respondSnapshots($this, [[3, '2026-10-20'], [5, '2026-10-26']]);

	$chart = present($this->hub()->snapshots->chart(fn(Snapshot $snapshot): array => [
		'total' => ($this->sectionCount)($snapshot),
		'segments' => $snapshot->id === 5 ? [['label' => 'Drafts', 'value' => 1]] : [],
	], ['label' => 'Entries', 'total' => 6, 'segments' => [['label' => 'Drafts', 'value' => 2, 'color' => 'amber', 'bar' => false]]]));

	expect($chart['series'][1])->toMatchArray(['key' => 'drafts', 'dashed' => true, 'values' => [null, 1, 2]]);
});

it('charts all snapshots, or the default range for unknown ones', function (int $range, int $expected, int $sinceQueries): void {
	respondSnapshots($this, [[3, '2026-10-20']]);

	$chart = present($this->hub()->snapshots->chart(fn(): array => ['total' => 1], ['label' => 'Sections', 'total' => 1], $range));

	expect($chart['range'])->toBe($expected)
		->and($this->db()->queriesMatching("`date` >= '"))->toHaveCount($sinceQueries);
})->with([[0, 0, 0], [7, 30, 1]]);

it('charts the current values alone for a range without snapshots, if there are earlier ones', function (): void {
	respondSnapshots($this, [], '2025-06-01');

	$chart = present($this->hub()->snapshots->chart(fn(): array => ['total' => 1], ['label' => 'Sections', 'total' => 4]));

	expect($chart['dates'])->toBe(['2026-10-27'])
		->and($chart['ranges'])->toBe([30, 90, 365, 0])
		->and($chart['series'][0]['values'])->toBe([4])
		->and($chart['change'])->toBeNull()
		->and($chart['since'])->toBeNull();
});

it('has no chart without snapshots before today, or while snapshots are disabled', function (bool $enabled, array $snapshots): void {
	$this->hub()->settings->enableSnapshots = $enabled;
	respondSnapshots($this, $snapshots);

	expect($this->hub()->snapshots->chart(fn(): array => ['total' => 1], ['label' => 'Sections', 'total' => 1]))->toBeNull();
})->with([
	[true, []],
	[true, [[1, '2026-10-27']]],
	[false, [[1, '2026-10-20']]],
]);

it('offers the ranges up to the first one spanning all snapshots', function (string $earliest, array $ranges): void {
	respondSnapshots($this, [[3, '2026-10-26']], $earliest);

	expect(at($this->hub()->snapshots->chart(fn(): array => ['total' => 1], ['label' => 'Sections', 'total' => 1]), 'ranges'))->toBe($ranges);
})->with([
	['2026-10-26', [30]],
	['2026-07-01', [30, 90, 365]],
	['2025-01-01', [30, 90, 365, 0]],
]);

it('loads at most as many snapshots as asked for, spread evenly from the first to the last', function (): void {
	respondSnapshots($this, array_map(fn(int $id): array => [$id, "2026-10-0{$id}"], range(1, 5)));

	expect(array_map(fn(Snapshot $snapshot): int => $snapshot->id, $this->hub()->snapshots->getSnapshots(null, 3)))->toBe([1, 3, 5])
		->and(array_map(fn(Snapshot $snapshot): int => $snapshot->id, $this->hub()->snapshots->getSnapshots(null)))->toBe([1, 2, 3, 4, 5]);
});

it('deletes the snapshots older than the retention, unless they are kept forever', function (int $days, int $deletes): void {
	$this->hub()->settings->snapshotRetentionDays = $days;

	$this->hub()->snapshots->deleteExpired();

	$queries = $this->db()->queriesMatching('DELETE FROM {{%hub_snapshots}}');

	expect($queries)->toHaveCount($deletes);

	if ($deletes > 0) {
		expect($queries[0])->toContain("`date` < '2026-10-17'");
	}
})->with([[10, 1], [0, 0]]);

it('checks whether the tables of the snapshots exist once', function (): void {
	$this->db()->tables = [];
	$snapshots = $this->hub()->snapshots;

	(function (): void {
		expect($this->tableExists())->toBeFalse()
			->and($this->tableExists())->toBeFalse();
	})->call($snapshots);

	expect($this->db()->checkedTables)->toBe([Tables::SNAPSHOTS]);
});
