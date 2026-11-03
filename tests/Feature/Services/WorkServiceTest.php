<?php

use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\UserGroup;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\models\MaintainerSource;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\DiagnosticsService;
use SomehowDigital\Craft\Hub\services\MaintainerService;
use SomehowDigital\Craft\Hub\services\WorkService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->request();
	Craft::$app->getCache()->flush();
	Craft::$app->getUser()->shouldReceive('getId')->andReturn(7)->byDefault();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 7, 'username' => 'jane']))->byDefault();
	$this->work = new WorkService();

	// The conditions of the queries of a kind of element, as SQL.
	$this->sql = fn (ElementQuery $query) => conditionSql($query->where);
});

function workDefinition(CheckKind $kind, string $handle, Severity $severity = Severity::Notice): CheckDefinition
{
	$definition = new CheckDefinition(['kind' => $kind, 'handle' => $handle, 'filter' => $handle, 'name' => $handle]);
	$definition->defaultSeverity = $severity;

	return $definition;
}

it('needs both accessing the plugin and its permission to view the work of a user', function (bool $plugin, array $permissions, bool $allowed) {
	$this->hasPluginAccess = $plugin;
	$this->actingAs(permissions: $permissions);

	expect($this->work->canView())->toBe($allowed);
})->with([
	'both' => [true, [Hub::PERMISSION_VIEW_WORK], true],
	'plugin only' => [true, [], false],
	'permission only' => [false, [Hub::PERMISSION_VIEW_WORK], false],
]);

it('counts nothing for users who may not view their work or without a user', function () {
	$this->actingAs(permissions: ['accessPlugin-hub']);

	expect($this->work->getCounts())->toBe(['drafts' => 0, 'diagnostics' => 0, 'comingUp' => 0, 'checks' => []])
		->and(ElementQueries::$executed)->toBe([]);
});

it('lists the checks flagging the user\'s entries and assets, the most urgent first, without those flagging nothing', function () {
	$notice = workDefinition(CheckKind::Entries, 'stale');
	$error = workDefinition(CheckKind::Assets, 'large', Severity::Error);
	$clean = workDefinition(CheckKind::Entries, 'disabled');

	$diagnostics = Mockery::mock(DiagnosticsService::class)->makePartial();
	$diagnostics->shouldReceive('getEnabledChecks')->andReturnUsing(fn (CheckKind $kind) => $kind === CheckKind::Entries ? [$notice, $clean] : [$error]);
	$diagnostics->shouldReceive('getFailingQuery')->andReturnUsing(fn (CheckDefinition $definition) => match ($definition) {
		$clean => Entry::find()->id(-1),
		$notice => Entry::find(),
		$error => Asset::find(),
	});
	$this->hub()->set('diagnostics', $diagnostics);

	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => $query->id === -1 ? [] : [new Entry(['id' => 1]), new Entry(['id' => 2])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 3])]);

	$checks = $this->work->getDiagnostics(10);

	expect(array_map(fn ($check) => $check->key, $checks))->toBe(['assets.large', 'entries.stale'])
		->and(array_map(fn ($check) => $check->count, $checks))->toBe([1, 2])
		->and(array_map(fn ($item) => $item->id, $checks[1]->items))->toBe([1, 2])
		->and($checks[0]->url)->toContain('hub/manage/work/assets')
		->and($checks[1]->url)->toContain('hub/manage/work/entries');
});

it('narrows the checks to what the user authored or uploaded and hasn\'t snoozed, whatever the filters of the checks ask for', function () {
	$entries = workDefinition(CheckKind::Entries, 'stale');
	$assets = workDefinition(CheckKind::Assets, 'large');

	$diagnostics = Mockery::mock(DiagnosticsService::class)->makePartial();
	$diagnostics->shouldReceive('getEnabledChecks')->andReturnUsing(fn (CheckKind $kind) => $kind === CheckKind::Entries ? [$entries] : [$assets]);
	$diagnostics->shouldReceive('getFailingQuery')->andReturnUsing(fn (CheckDefinition $definition) => $definition === $entries ? Entry::find()->authorId(99) : Asset::find());
	$this->hub()->set('diagnostics', $diagnostics);

	ElementQueries::respond(Entry::class, [new Entry(['id' => 1])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 2])]);

	$this->work->getDiagnostics(1);

	$executed = fn (string $type) => array_values(array_filter(ElementQueries::$executed, fn (ElementQuery $query) => $query->elementType === $type))[0];
	$entrySql = ($this->sql)($executed(Entry::class));
	$assetSql = ($this->sql)($executed(Asset::class));

	expect($entrySql)->toContain('`hubWorkAuthors`.`authorId`=7')
		->and($entrySql)->toContain('NOT EXISTS')
		->and($entrySql)->toContain("`hubSnoozes`.`checkKey`='entries.stale'")
		->and($entrySql)->toContain('`hubSnoozes`.`userId`=7')
		->and($executed(Entry::class)->authorId)->toBe(99)
		->and($assetSql)->toContain('`assets`.`uploaderId`=7')
		->and($assetSql)->toContain("`hubSnoozes`.`checkKey`='assets.large'");
});

it('leaves out the checks of pages the user may not view and everything while diagnostics are disabled', function (array $permissions, bool $diagnostics) {
	$this->actingAs(permissions: $permissions);
	$this->hub()->getSettings()->enableDiagnostics = $diagnostics;

	try {
		expect($this->work->getDiagnostics())->toBe([])
			->and(ElementQueries::$executed)->toBe([]);
	} finally {
		$this->hub()->getSettings()->enableDiagnostics = true;
	}
})->with([
	'no pages' => [['accessPlugin-hub'], true],
	'diagnostics disabled' => [['accessPlugin-hub', Hub::PERMISSION_ACCESS_DATA, 'hub-viewEntries'], false],
]);

it('lists the drafts themselves, saved or not, of new and existing entries, saying what each is', function () {
	$new = Mockery::mock(Entry::class)->makePartial();
	$new->shouldReceive('getIsUnpublishedDraft')->andReturn(true);
	$named = Mockery::mock(Entry::class)->makePartial();
	$named->shouldReceive('getIsUnpublishedDraft')->andReturn(false);
	$named->attachBehavior('draft', new craft\behaviors\DraftBehavior(['draftName' => 'Spring update']));
	$unnamed = Mockery::mock(Entry::class)->makePartial();
	$unnamed->shouldReceive('getIsUnpublishedDraft')->andReturn(false);
	$unsaved = Mockery::mock(Entry::class)->makePartial();
	$unsaved->isProvisionalDraft = true;
	ElementQueries::respond(Entry::class, [$new, $named, $unnamed, $unsaved]);

	$drafts = $this->work->getDrafts();
	$query = ElementQueries::$executed[0];

	expect($drafts['total'])->toBe(4)
		->and(array_column($drafts['items'], 'note'))->toBe(['New entry', 'Spring update', 'Draft', 'Unsaved changes'])
		->and($drafts['items'][0]['element'])->toBe($new)
		->and($query->drafts)->toBeTrue()
		->and($query->provisionalDrafts)->toBeNull()
		->and($query->draftCreator)->toBe(7)
		->and($query->draftOf)->toBeNull()
		->and($query->savedDraftsOnly)->toBeTrue();
});

it('names the sections alike everywhere, leading to their tab or panel, and those of the user\'s work with a number', function () {
	$sections = $this->work->getSections();

	expect(array_keys($sections))->toBe(['diagnostics', 'drafts', 'coming-up', 'maintained'])
		->and($sections['diagnostics']['label'])->toBe('Diagnostics')
		->and($sections['diagnostics']['url'])->toContain('hub/manage/work/diagnostics')
		->and($sections['drafts']['url'])->toEndWith('#hub-work-drafts')
		->and($sections['coming-up']['url'])->toEndWith('#hub-work-coming-up')
		->and($sections['drafts']['count'])->toBe(0)
		->and($sections['maintained']['count'])->toBeNull();
});

it('leaves out the sections of what the user may not view or is disabled', function () {
	$this->actingAs(permissions: ['accessPlugin-hub', Hub::PERMISSION_VIEW_WORK]);
	$this->hub()->getSettings()->enableDiagnostics = false;

	try {
		expect(array_keys($this->work->getSections()))->toBe(['maintained']);
	} finally {
		$this->hub()->getSettings()->enableDiagnostics = true;
	}
});

it('has no entries for users who may not view them', function () {
	$this->actingAs(permissions: ['accessPlugin-hub']);

	expect($this->work->getDrafts())->toBe(['items' => [], 'total' => 0])
		->and($this->work->getComingUp())->toBe(['items' => [], 'total' => 0]);
});

it('lists the enabled entries of the user being published or expiring soon, soonest first', function () {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1])]);

	$coming = $this->work->getComingUp();
	$query = ElementQueries::$executed[0];
	$sql = ($this->sql)($query);

	expect($coming['total'])->toBe(1)
		->and($query->authorId)->toBe([7])
		->and($sql)->toContain('`entries`.`postDate` BETWEEN')
		->and($sql)->toContain('`entries`.`expiryDate` BETWEEN')
		->and($sql)->toContain('`elements`.`enabled`=TRUE')
		->and(array_keys($query->orderBy)[0])->toStartWith('CASE WHEN [[entries.postDate]]');
});

it('lists what the user maintains on pages they may view, with the checks failing there', function () {
	$this->section(1, 'news');
	$this->section(2, 'blog');
	$this->volume(3, 'images');
	$sources = [
		new MaintainerSource(ColumnService::LIST_SECTIONS, $this->section(1, 'news'), [], [new UserGroup(['id' => 4, 'name' => 'Editors'])]),
		new MaintainerSource(ColumnService::LIST_VOLUMES, $this->volume(3, 'images'), [], []),
	];
	$maintainers = Mockery::mock(MaintainerService::class)->makePartial();
	$maintainers->shouldReceive('getMaintainedBy')->andReturn($sources);
	$this->hub()->set('maintainers', $maintainers);
	$this->actingAs(permissions: ['accessPlugin-hub', Hub::PERMISSION_ACCESS_STRUCTURE, 'hub-viewSections']);
	$this->hub()->getSettings()->enableDiagnostics = false;

	try {
		$maintained = $this->work->getMaintained();
	} finally {
		$this->hub()->getSettings()->enableDiagnostics = true;
	}

	expect($maintained)->toHaveCount(1)
		->and($maintained[0]['source'])->toBe($sources[0])
		->and($maintained[0]['failed'])->toBe(0)
		->and($maintained[0]['severity'])->toBeNull();
});

it('lists what the user snoozed, what returns first ahead of what they dismissed, leaving out checks and items gone', function () {
	$diagnostics = Mockery::mock(DiagnosticsService::class)->makePartial();
	$diagnostics->shouldReceive('getDefinition')->andReturnUsing(fn (string $key) => $key === 'gone.check' ? null : workDefinition(CheckKind::Entries, explode('.', $key)[1]));
	$this->hub()->set('diagnostics', $diagnostics);

	$this->db()->respond('FROM {{%hub_snoozes}}', [
		['elementId' => 1, 'checkKey' => 'entries.stale', 'dateUntil' => null],
		['elementId' => 2, 'checkKey' => 'entries.stale', 'dateUntil' => '2030-01-01 00:00:00'],
		['elementId' => 2, 'checkKey' => 'gone.check', 'dateUntil' => null],
		['elementId' => 3, 'checkKey' => 'entries.drafts', 'dateUntil' => null],
	]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1]), new Entry(['id' => 2])]);

	$snoozed = $this->work->getSnoozed();

	expect(array_map(fn (array $row) => $row['element']->id, $snoozed))->toBe([2, 1])
		->and($snoozed[0]['until']->format('Y'))->toBe('2030')
		->and($snoozed[1]['until'])->toBeNull();
});

it('snoozes for the days given, or for good, and counts again', function () {
	$check = workDefinition(CheckKind::Entries, 'stale');

	$this->work->snooze($check, 5, 7);
	$this->work->snooze($check, 6, null);

	$queries = $this->db()->queriesMatching('INTO {{%hub_snoozes}}');

	expect($queries)->toHaveCount(2)
		->and($queries[0])->toContain('entries.stale')
		->and($queries[0])->toContain((new DateTime('+7 days', new DateTimeZone('UTC')))->format('Y-m-d'))
		->and($queries[1])->toContain('NULL');
});

it('counts again once the user snoozes something, keeping the counts until then', function () {
	$this->hub()->getSettings()->enableDiagnostics = false;
	$check = workDefinition(CheckKind::Entries, 'stale');
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1])]);

	try {
		$this->work->getCounts();
		$first = count(ElementQueries::$executed);
		$this->work->getCounts();

		expect(count(ElementQueries::$executed))->toBe($first);

		$this->work->snooze($check, 5, 7);
		$this->work->getCounts();

		expect(count(ElementQueries::$executed))->toBeGreaterThan($first);
	} finally {
		$this->hub()->getSettings()->enableDiagnostics = true;
	}
});

it('wakes what a user snoozed, for them alone', function () {
	$this->work->wake('entries.stale', 5);

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_snoozes}}')[0])
		->toContain('`userId`=7')
		->toContain('`elementId`=5')
		->toContain("`checkKey`='entries.stale'");
});

it('removes the snoozes that ran out and those of checks that no longer exist', function () {
	$this->work->deleteExpired();

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_snoozes}}')[0])
		->toContain('`dateUntil` <=')
		->toContain('NOT IN')
		->toContain('entries.stale');
});

it('narrows the checks to the user\'s items first, so databases find those before evaluating the checks', function () {
	$entries = workDefinition(CheckKind::Entries, 'disabled');
	$assets = workDefinition(CheckKind::Assets, 'large');

	$diagnostics = Mockery::mock(DiagnosticsService::class)->makePartial();
	$diagnostics->shouldReceive('getEnabledChecks')->andReturnUsing(fn (CheckKind $kind) => $kind === CheckKind::Entries ? [$entries] : [$assets]);
	$diagnostics->shouldReceive('getFailingQuery')->andReturnUsing(fn (CheckDefinition $definition) => $definition === $entries
		? Entry::find()->andWhere(['elements.enabled' => false])
		: Asset::find()->andWhere(['>', 'assets.size', 1]));
	$this->hub()->set('diagnostics', $diagnostics);

	$this->work->getCounts();

	$entrySql = ($this->sql)(ElementQueries::executed(Entry::class)[0]);
	$assetSql = ($this->sql)(ElementQueries::executed(Asset::class)[0]);

	expect(strpos($entrySql, '`hubWorkAuthors`'))->toBeLessThan(strpos($entrySql, '`elements`.`enabled`'))
		->and(strpos($assetSql, '`assets`.`uploaderId`=7'))->toBeLessThan(strpos($assetSql, '`assets`.`size`'))
		->and(strpos($entrySql, '`elements`.`enabled`'))->toBeLessThan(strpos($entrySql, '`hubSnoozes`'));
});

it('counts the checks once for the navigation and the page, keeping the counts of each', function () {
	$notice = workDefinition(CheckKind::Entries, 'stale');
	$error = workDefinition(CheckKind::Assets, 'large', Severity::Error);

	$diagnostics = Mockery::mock(DiagnosticsService::class)->makePartial();
	$diagnostics->shouldReceive('getEnabledChecks')->andReturnUsing(fn (CheckKind $kind) => $kind === CheckKind::Entries ? [$notice] : [$error]);
	$diagnostics->shouldReceive('getFailingQuery')->andReturnUsing(fn (CheckDefinition $definition) => $definition === $notice ? Entry::find() : Asset::find());
	$this->hub()->set('diagnostics', $diagnostics);

	ElementQueries::respond(Entry::class, [new Entry(['id' => 1]), new Entry(['id' => 2])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 3])]);

	$counts = $this->work->getCounts();
	$executed = count(ElementQueries::$executed);
	$checks = (new WorkService())->getDiagnostics();

	expect($counts['checks'])->toBe(['entries.stale' => 2, 'assets.large' => 1])
		->and($counts['diagnostics'])->toBe(3)
		->and(array_map(fn ($check) => $check->count, $checks))->toBe([1, 2])
		->and(count(ElementQueries::$executed))->toBe($executed);
});

it('counts again once the user changed their entries or assets', function () {
	$this->hub()->getSettings()->enableDiagnostics = false;
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1])]);

	try {
		$this->work->getCounts();
		$first = count(ElementQueries::$executed);
		$this->work->forgetCounts();
		$this->work->getCounts();
		$second = count(ElementQueries::$executed);

		// Forgotten again once counted again.
		$this->work->forgetCounts();
		$this->work->getCounts();

		expect($second)->toBeGreaterThan($first)
			->and(count(ElementQueries::$executed))->toBeGreaterThan($second);
	} finally {
		$this->hub()->getSettings()->enableDiagnostics = true;
	}
});

it('only tells whether what the user maintains fails the checks of what it holds, without counting those', function () {
	$this->section(1, 'news');
	$this->volume(3, 'images');
	$sources = [
		new MaintainerSource(ColumnService::LIST_SECTIONS, $this->section(1, 'news'), [], []),
		new MaintainerSource(ColumnService::LIST_VOLUMES, $this->volume(3, 'images'), [], []),
	];
	$maintainers = Mockery::mock(MaintainerService::class)->makePartial();
	$maintainers->shouldReceive('getMaintainedBy')->andReturn($sources);
	$this->hub()->set('maintainers', $maintainers);

	$failed = new Check('Drafts', '', '', Severity::Warning, 'file-pen', false);
	$checks = Mockery::mock(CheckService::class);
	$checks->shouldReceive('forSection')->once()->with(Mockery::any(), false)->andReturn([$failed]);
	$checks->shouldReceive('forVolume')->once()->with(Mockery::any(), false)->andReturn([]);
	$this->hub()->set('checks', $checks);
	$this->actingAs(permissions: ['accessPlugin-hub', Hub::PERMISSION_ACCESS_STRUCTURE, 'hub-viewSections', 'hub-viewVolumes']);

	$maintained = $this->work->getMaintained();

	expect(array_column($maintained, 'failed'))->toBe([1, 0])
		->and($maintained[0]['severity'])->toBe(Severity::Warning);
});
