<?php

declare(strict_types=1);

use craft\behaviors\DraftBehavior;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use Mockery\MockInterface;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function (): void {
	$this->request();
	Craft::$app->getCache()?->flush();
	mocked(Craft::$app->getUser())->shouldReceive('getId')->andReturn(7)->byDefault();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 7, 'username' => 'jane']))->byDefault();

	// The conditions of the queries of a kind of element, as SQL.
	$this->sql = fn(ElementQuery $query): string => conditionSql($query->where);
});

function workDefinition(CheckKind $kind, string $handle, Severity $severity = Severity::Notice): CheckDefinition
{
	$definition = Hub::getInstance()->diagnostics->createDefinition(['kind' => $kind, 'handle' => $handle, 'filter' => $handle, 'name' => $handle]);
	$definition->defaultSeverity = $severity;

	return $definition;
}

it('needs both accessing the plugin and its permission to view the work of a user', function (bool $plugin, array $permissions, bool $allowed): void {
	$this->hasPluginAccess = $plugin;
	$this->actingAs(permissions: strings($permissions));

	expect($this->hub()->work->canView())->toBe($allowed);
})->with([
	'both' => [true, [Permission::ViewWork->value], true],
	'plugin only' => [true, [], false],
	'permission only' => [false, [Permission::ViewWork->value], false],
]);

it('counts nothing for users who may not view their work or without a user', function (): void {
	$this->actingAs(permissions: ['accessPlugin-hub']);

	expect($this->hub()->work->getCounts())->toBe(['drafts' => 0, 'diagnostics' => 0, 'comingUp' => 0, 'checks' => []])
		->and(ElementQueries::$executed)->toBe([]);
});

it("lists the checks flagging the user's entries and assets, the most urgent first, without those flagging nothing", function (): void {
	onlyChecks(['entries.stale' => null, 'entries.disabled' => null, 'assets.large' => Severity::Error]);

	// The disabled entries are the ones the check of disabled entries flags.
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => $query->status === ['disabled'] ? [] : [new Entry(['id' => 1]), new Entry(['id' => 2])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 3])]);

	$checks = $this->hub()->work->getDiagnostics(10);

	expect(array_map(fn(mixed $check): mixed => at($check, 'key'), $checks))->toBe(['assets.large', 'entries.stale'])
		->and(array_map(fn(mixed $check): mixed => at($check, 'count'), $checks))->toBe([1, 2])
		->and(array_map(fn(mixed $item): mixed => at($item, 'id'), $checks[1]->items))->toBe([1, 2])
		->and($checks[0]->url)->toContain('hub/manage/work/assets')
		->and($checks[1]->url)->toContain('hub/manage/work/entries');
});

it("narrows the checks to what the user authored or uploaded and hasn't snoozed", function (): void {
	onlyChecks(['entries.stale' => null, 'assets.large' => null]);

	ElementQueries::respond(Entry::class, [new Entry(['id' => 1])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 2])]);

	$this->hub()->work->getDiagnostics(1);

	$executed = fn(string $type): ElementQuery => array_values(array_filter(ElementQueries::$executed, fn(ElementQuery $query): bool => $query->elementType === $type))[0];
	$entrySql = ($this->sql)($executed(Entry::class));
	$assetSql = ($this->sql)($executed(Asset::class));

	expect($entrySql)->toContain('`hubWorkAuthors`.`authorId`=7')
		->and($entrySql)->toContain('NOT EXISTS')
		->and($entrySql)->toContain("`hubSnoozes`.`checkKey`='entries.stale'")
		->and($entrySql)->toContain('`hubSnoozes`.`userId`=7')
		->and($assetSql)->toContain('`assets`.`uploaderId`=7')
		->and($assetSql)->toContain("`hubSnoozes`.`checkKey`='assets.large'");
});

it('leaves out the checks of pages the user may not view and everything while diagnostics are disabled', function (array $permissions, bool $diagnostics): void {
	$this->actingAs(permissions: strings($permissions));
	$this->hub()->settings->enableDiagnostics = $diagnostics;

	try {
		expect($this->hub()->work->getDiagnostics())->toBe([])
			->and(ElementQueries::$executed)->toBe([]);
	} finally {
		$this->hub()->settings->enableDiagnostics = true;
	}
})->with([
	'no pages' => [['accessPlugin-hub'], true],
	'diagnostics disabled' => [['accessPlugin-hub', Permission::AccessData->value, 'hub-viewEntries'], false],
]);

it('lists the drafts themselves, saved or not, of new and existing entries, saying what each is', function (): void {
	/** @var Entry&MockInterface $new */
	$new = Mockery::mock(Entry::class)->makePartial();
	$new->shouldReceive('getIsUnpublishedDraft')->andReturn(true);
	/** @var Entry&MockInterface $named */
	$named = Mockery::mock(Entry::class)->makePartial();
	$named->shouldReceive('getIsUnpublishedDraft')->andReturn(false);
	$named->attachBehavior('draft', ['class' => DraftBehavior::class, 'draftName' => 'Spring update']);
	/** @var Entry&MockInterface $unnamed */
	$unnamed = Mockery::mock(Entry::class)->makePartial();
	$unnamed->shouldReceive('getIsUnpublishedDraft')->andReturn(false);
	/** @var Entry&MockInterface $unsaved */
	$unsaved = Mockery::mock(Entry::class)->makePartial();
	$unsaved->isProvisionalDraft = true;
	ElementQueries::respond(Entry::class, [$new, $named, $unnamed, $unsaved]);

	$drafts = $this->hub()->work->getDrafts();
	$query = ElementQueries::$executed[0];

	expect($drafts['total'])->toBe(4)
		->and(array_column(arrayAt($drafts, 'items'), 'note'))->toBe(['New entry', 'Spring update', 'Draft', 'Unsaved changes'])
		->and($drafts['items'][0]['element'])->toBe($new)
		->and($query->drafts)->toBeTrue()
		->and($query->provisionalDrafts)->toBeNull()
		->and($query->draftCreator)->toBe(7)
		->and($query->draftOf)->toBeNull()
		->and($query->savedDraftsOnly)->toBeTrue();
});

it("names the sections alike everywhere, leading to their tab or panel, and those of the user's work with a number", function (): void {
	$sections = $this->hub()->work->getSections();

	expect(array_keys($sections))->toBe(['diagnostics', 'drafts', 'coming-up', 'maintained'])
		->and($sections['diagnostics']['label'])->toBe('Diagnostics')
		->and($sections['diagnostics']['url'])->toContain('hub/manage/work/diagnostics')
		->and($sections['drafts']['url'])->toEndWith('#hub-work-drafts')
		->and($sections['coming-up']['url'])->toEndWith('#hub-work-coming-up')
		->and($sections['drafts']['count'])->toBe(0)
		->and($sections['maintained']['count'])->toBeNull();
});

it('leaves out the sections of what the user may not view or is disabled', function (): void {
	$this->actingAs(permissions: ['accessPlugin-hub', Permission::ViewWork->value]);
	$this->hub()->settings->enableDiagnostics = false;

	try {
		expect(array_keys($this->hub()->work->getSections()))->toBe(['maintained']);
	} finally {
		$this->hub()->settings->enableDiagnostics = true;
	}
});

it('has no entries for users who may not view them', function (): void {
	$this->actingAs(permissions: ['accessPlugin-hub']);

	expect($this->hub()->work->getDrafts())->toBe(['items' => [], 'total' => 0])
		->and($this->hub()->work->getComingUp())->toBe(['items' => [], 'total' => 0]);
});

it('lists the enabled entries of the user being published or expiring soon, soonest first', function (): void {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1])]);

	$coming = $this->hub()->work->getComingUp();
	$query = ElementQueries::$executed[0];
	$sql = ($this->sql)($query);

	expect($coming['total'])->toBe(1)
		->and(at($query, 'authorId'))->toBe([7])
		->and($sql)->toContain('`entries`.`postDate` BETWEEN')
		->and($sql)->toContain('`entries`.`expiryDate` BETWEEN')
		->and($sql)->toContain('`elements`.`enabled`=TRUE')
		->and(array_key_first(listOf($query->orderBy)))->toStartWith('CASE WHEN [[entries.postDate]]');
});

it('lists what the user maintains on pages they may view, with the checks failing there', function (): void {
	$this->createContentModel();
	$this->hub()->settings->enableMaintainers = true;
	$this->db()->respond(sqlFrom(Tables::MAINTAINERS), [
		['list' => ColumnService::LIST_SECTIONS, 'itemId' => 1, 'userId' => 7, 'groupId' => null],
		['list' => ColumnService::LIST_VOLUMES, 'itemId' => 1, 'userId' => 7, 'groupId' => null],
	]);
	$this->actingAs(permissions: ['accessPlugin-hub', Permission::AccessStructure->value, 'hub-viewSections']);
	$this->hub()->settings->enableDiagnostics = false;

	try {
		$maintained = $this->hub()->work->getMaintained();
	} finally {
		$this->hub()->settings->enableDiagnostics = true;
		$this->hub()->settings->enableMaintainers = false;
	}

	expect($maintained)->toHaveCount(1)
		->and($maintained[0]['source']->list)->toBe(ColumnService::LIST_SECTIONS)
		->and($maintained[0]['failed'])->toBe(0)
		->and($maintained[0]['severity'])->toBeNull();
});

it('lists what the user snoozed, what returns first ahead of what they dismissed, leaving out checks and items gone', function (): void {
	$this->db()->respond('FROM {{%hub_snoozes}}', [
		['elementId' => 1, 'checkKey' => 'entries.stale', 'dateUntil' => null],
		['elementId' => 2, 'checkKey' => 'entries.stale', 'dateUntil' => '2030-01-01 00:00:00'],
		['elementId' => 2, 'checkKey' => 'gone.check', 'dateUntil' => null],
		['elementId' => 3, 'checkKey' => 'entries.drafts', 'dateUntil' => null],
	]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1]), new Entry(['id' => 2])]);

	$snoozed = $this->hub()->work->getSnoozed();

	expect(array_map(fn(array $row) => $row['element']->id, $snoozed))->toBe([2, 1])
		->and($snoozed[0]['until']?->format('Y'))->toBe('2030')
		->and($snoozed[1]['until'])->toBeNull();
});

it('snoozes for the days given, or for good, and counts again', function (): void {
	$check = workDefinition(CheckKind::Entries, 'stale');

	$this->hub()->work->snooze($check, 5, 7);
	$this->hub()->work->snooze($check, 6, null);

	$queries = $this->db()->queriesMatching('INTO {{%hub_snoozes}}');

	expect($queries)->toHaveCount(2)
		->and($queries[0])->toContain('entries.stale')
		->and($queries[0])->toContain((new DateTime('+7 days', new DateTimeZone('UTC')))->format('Y-m-d'))
		->and($queries[1])->toContain('NULL');
});

it('counts again once the user snoozes something, keeping the counts until then', function (): void {
	$this->hub()->settings->enableDiagnostics = false;
	$check = workDefinition(CheckKind::Entries, 'stale');
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1])]);

	try {
		$this->hub()->work->getCounts();
		$first = count(ElementQueries::$executed);
		$this->hub()->work->getCounts();

		expect(count(ElementQueries::$executed))->toBe($first);

		$this->hub()->work->snooze($check, 5, 7);
		$this->hub()->work->getCounts();

		expect(count(ElementQueries::$executed))->toBeGreaterThan($first);
	} finally {
		$this->hub()->settings->enableDiagnostics = true;
	}
});

it('wakes what a user snoozed, for them alone', function (): void {
	$this->hub()->work->wake('entries.stale', 5);

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_snoozes}}')[0])
		->toContain('`userId`=7')
		->toContain('`elementId`=5')
		->toContain("`checkKey`='entries.stale'");
});

it('removes the snoozes that ran out and those of checks that no longer exist', function (): void {
	$this->hub()->work->deleteExpired();

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_snoozes}}')[0])
		->toContain('`dateUntil` <=')
		->toContain('NOT IN')
		->toContain('entries.stale');
});

it("narrows the checks to the user's items first, so databases find those before evaluating the checks", function (): void {
	onlyChecks(['entries.stale' => null, 'assets.large' => null]);

	$this->hub()->work->getCounts();

	$entrySql = ($this->sql)(ElementQueries::executed(Entry::class)[0]);
	$assetSql = ($this->sql)(ElementQueries::executed(Asset::class)[0]);

	expect(positionOf($entrySql, '`hubWorkAuthors`'))->toBeLessThan(positionOf($entrySql, '`hubEdits`'))
		->and(positionOf($assetSql, '`assets`.`uploaderId`=7'))->toBeLessThan(positionOf($assetSql, '`assets`.`size`'))
		->and(positionOf($entrySql, '`hubEdits`'))->toBeLessThan(positionOf($entrySql, '`hubSnoozes`'));
});

it('counts the checks once for the navigation and the page, keeping the counts of each', function (): void {
	onlyChecks(['entries.stale' => null, 'assets.large' => Severity::Error]);

	ElementQueries::respond(Entry::class, [new Entry(['id' => 1]), new Entry(['id' => 2])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 3])]);

	$counts = $this->hub()->work->getCounts();
	$executed = count(ElementQueries::$executed);
	$checks = $this->hub()->work->getDiagnostics();

	expect($counts['checks'])->toBe(['entries.stale' => 2, 'assets.large' => 1])
		->and($counts['diagnostics'])->toBe(3)
		->and(array_map(fn(Check $check): ?int => $check->count, $checks))->toBe([1, 2])
		->and(count(ElementQueries::$executed))->toBe($executed);
});

it('counts again once the user changed their entries or assets', function (): void {
	$this->hub()->settings->enableDiagnostics = false;
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1])]);

	try {
		$this->hub()->work->getCounts();
		$first = count(ElementQueries::$executed);
		$this->hub()->work->forgetCounts();
		$this->hub()->work->getCounts();
		$second = count(ElementQueries::$executed);

		// Forgotten again once counted again.
		$this->hub()->work->forgetCounts();
		$this->hub()->work->getCounts();

		expect($second)->toBeGreaterThan($first)
			->and(count(ElementQueries::$executed))->toBeGreaterThan($second);
	} finally {
		$this->hub()->settings->enableDiagnostics = true;
	}
});

it('only tells whether what the user maintains fails the checks of what it holds, without counting those', function (): void {
	$this->createContentModel();
	$this->hub()->settings->enableMaintainers = true;
	onlyChecks(['entries.disabled' => Severity::Warning]);
	$this->db()->respond(sqlFrom(Tables::MAINTAINERS), [
		['list' => ColumnService::LIST_SECTIONS, 'itemId' => 1, 'userId' => 7, 'groupId' => null],
		['list' => ColumnService::LIST_VOLUMES, 'itemId' => 1, 'userId' => 7, 'groupId' => null],
	]);
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => $query->status === ['disabled'] && str_contains(($this->sql)($query), '`entries`.`sectionId`=1') ? [new Entry(['id' => 1])] : []);
	$this->actingAs(permissions: ['accessPlugin-hub', Permission::AccessStructure->value, 'hub-viewSections', 'hub-viewVolumes']);

	try {
		$maintained = $this->hub()->work->getMaintained();
	} finally {
		$this->hub()->settings->enableMaintainers = false;
	}

	expect(array_column($maintained, 'failed'))->toBe([1, 0])
		->and($maintained[0]['severity'])->toBe(Severity::Warning);
});
