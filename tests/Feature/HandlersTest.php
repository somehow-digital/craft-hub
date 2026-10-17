<?php

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\View;
use SomehowDigital\Craft\Hub\assets\SidebarAsset;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\handlers\DefineSidebarHtmlHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpNavItemsHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpRoutesHandler;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\EntryReference;
use SomehowDigital\Craft\Hub\models\Label;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\EditService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\services\ReferenceService;

it('routes the Hub pages to their controllers', function () {
	$event = new RegisterUrlRulesEvent();
	(new RegisterCpRoutesHandler())($event);

	expect($event->rules)->toMatchArray([
		'hub/data' => 'hub/nav/data',
		'hub/data/entries' => 'hub/entries/index',
		'hub/data/entries/diagnostics' => 'hub/entries/diagnostics',
		'hub/data/entries/statistics' => 'hub/entries/statistics',
		'hub/data/entries/<id:\d+>' => 'hub/entries/view',
		'hub/data/entries/<id:\d+>/<tab:history|diagnostics>' => 'hub/entries/view',
		'hub/data/assets' => 'hub/files/index',
		'hub/data/assets/diagnostics' => 'hub/files/diagnostics',
		'hub/data/assets/trash' => 'hub/files/trash',
		'hub/data/assets/<id:\d+>' => 'hub/files/view',
		'hub/data/assets/<id:\d+>/<tab:transforms|diagnostics>' => 'hub/files/view',
		'hub/data/users' => 'hub/users/index',
		'hub/data/users/diagnostics' => 'hub/users/diagnostics',
		'hub/data/users/groups' => 'hub/users/groups',
		'hub/data/entries/trash' => 'hub/entries/trash',
		'hub/data/users/statistics' => 'hub/users/statistics',
		'hub/data/users/<id:\d+>' => 'hub/users/view',
		'hub/data/users/<id:\d+>/<tab:entries|assets|diagnostics>' => 'hub/users/view',
		'hub/structure' => 'hub/nav/structure',
		'hub/structure/entry-types/<id:\d+>' => 'hub/types/view',
		'hub/structure/sections/diagnostics' => 'hub/sections/diagnostics',
		'hub/structure/sections/<id:\d+>' => 'hub/sections/view',
		'hub/structure/sections/<id:\d+>/<tab:entries|statistics|diagnostics>' => 'hub/sections/view',
		'hub/structure/fields/<id:\d+>' => 'hub/fields/view',
		'hub/structure/volumes/<id:\d+>' => 'hub/volumes/view',
		'hub/structure/volumes/<id:\d+>/<tab:assets|statistics|diagnostics>' => 'hub/volumes/view',
		'hub/manage' => 'hub/nav/manage',
		'hub/manage/activity' => 'hub/activity/index',
		'hub/manage/settings' => 'hub/settings/index',
		'hub/manage/settings/labels' => 'hub/labels/index',
		'hub/manage/settings/labels/new' => 'hub/labels/edit',
		'hub/manage/settings/labels/<labelId:\d+>' => 'hub/labels/edit',
		'hub/manage/settings/diagnostics' => 'hub/checks/index',
		'hub/manage/settings/diagnostics/new/<kind:[a-zA-Z]+>' => 'hub/checks/edit',
		'hub/manage/settings/diagnostics/<checkId:\d+>' => 'hub/checks/edit',
		'hub/manage/settings/filters' => 'hub/filters/index',
		'hub/manage/settings/filters/new/<kind:[a-zA-Z]+>' => 'hub/filters/edit',
		'hub/manage/settings/filters/<filterId:\d+>' => 'hub/filters/edit',
		'hub/manage/settings/statistics' => 'hub/settings/statistics',
		'hub/manage/settings/activity' => 'hub/settings/activity',
		'hub/manage/settings/trash' => 'hub/settings/trash',
	])->and($event->rules)->toHaveCount(55);

	foreach (array_unique($event->rules) as $route) {
		[, $controller, $action] = explode('/', $route);
		$class = 'SomehowDigital\\Craft\\Hub\\controllers\\' . ucfirst($controller) . 'Controller';

		expect(method_exists($class, 'action' . ucfirst($action)))->toBeTrue("$route has no action");
	}
});

it('adds the hub navigation item right before the data one and the structure one right after it', function () {
	$event = new RegisterCpNavItemsEvent(['navItems' => [
		['label' => 'Entries', 'url' => 'entries'],
		['label' => 'Data', 'url' => 'hub/data'],
		['label' => 'Settings', 'url' => 'settings'],
	]]);
	(new RegisterCpNavItemsHandler())($event);

	expect(array_column($event->navItems, 'url'))->toBe(['entries', 'hub/manage', 'hub/data', 'hub/structure', 'settings']);
});

it('puts the navigation items in place of the structure one, as the plugin\'s own, for users who may not open the data', function () {
	$this->request();
	$this->actingAs(permissions: [Hub::PERMISSION_ACCESS_STRUCTURE, Page::Fields->viewPermission(), Hub::PERMISSION_MANAGE_LABELS]);
	$event = new RegisterCpNavItemsEvent(['navItems' => [
		['label' => 'Entries', 'url' => 'entries'],
		['label' => 'Structure', 'url' => 'hub/structure'],
		['label' => 'Settings', 'url' => 'settings'],
	]]);
	(new RegisterCpNavItemsHandler())($event);

	expect(array_column($event->navItems, 'url'))->toBe(['entries', 'hub/manage', 'hub/structure', 'settings']);
});

it('adds no structure navigation item without the data one', function () {
	$event = new RegisterCpNavItemsEvent(['navItems' => [['label' => 'Entries', 'url' => 'entries']]]);
	(new RegisterCpNavItemsHandler())($event);

	expect($event->navItems)->toHaveCount(1);
});

function sidebarEvent(mixed $sender): DefineHtmlEvent
{
	$event = new DefineHtmlEvent(['html' => '<existing>']);
	$event->sender = $sender;

	return $event;
}

/**
 * Mocks the services the sidebar reads, with labels enabled but none existing, unless expected otherwise.
 *
 * @return array{labels:Mockery\MockInterface,checks:Mockery\MockInterface,edits:Mockery\MockInterface,references:Mockery\MockInterface,view:Mockery\MockInterface}
 */
function sidebarServices(object $test): array
{
	$mock = fn (string $id, string $class) => (fn () => $this->mockComponent($id, $class))->call($test);

	$labels = $mock('hub.labels', LabelService::class);
	$labels->shouldReceive('isEnabled')->andReturnTrue()->byDefault();
	$labels->shouldReceive('canManage')->andReturnFalse()->byDefault();
	$labels->shouldReceive('canApply')->andReturnTrue()->byDefault();
	$labels->shouldReceive('getAllLabels')->andReturn([])->byDefault();
	$labels->shouldReceive('getLabelsOf')->andReturn([])->byDefault();

	$view = $mock('view', View::class);
	$view->shouldReceive('registerAssetBundle')->once()->with(SidebarAsset::class);

	return [
		'labels' => $labels,
		'checks' => $mock('hub.checks', CheckService::class),
		'edits' => $mock('hub.edits', EditService::class),
		'references' => $mock('hub.references', ReferenceService::class),
		'view' => $view,
	];
}

it('adds a panel with the labels, checks, last edit and references of an entry to its sidebar', function () {
	$entry = new Entry(['id' => 5, 'siteId' => 1]);
	$label = new Label(['id' => 1, 'name' => 'Review']);
	$other = new Label(['id' => 2, 'name' => 'Keep']);
	$checks = [new Check('Stale', 'Edit it.', 'Fresh.', Severity::Warning, 'clock', false)];
	$lastEdit = ['date' => new DateTime('2026-01-01'), 'user' => null];
	$referencing = [new EntryReference(new Entry(['id' => 6]))];

	$services = sidebarServices($this);
	$services['labels']->shouldReceive('canManage')->andReturnTrue();
	$services['labels']->shouldReceive('getAllLabels')->andReturn([1 => $label, 2 => $other]);
	$services['labels']->shouldReceive('canApply')->with(ColumnService::LIST_ENTRIES)->andReturnTrue();
	$services['labels']->shouldReceive('getLabelsOf')->with(ColumnService::LIST_ENTRIES, [5])->andReturn([5 => [$label]]);
	$services['checks']->shouldReceive('forEntry')->once()->with($entry)->andReturn($checks);
	$services['edits']->shouldReceive('getLastEdit')->once()->with($entry)->andReturn($lastEdit);
	$services['references']->shouldReceive('getReferences')->once()->with($entry)->andReturn($referencing);
	$services['view']->shouldReceive('renderTemplate')->once()->with('hub/_sidebar/hub', [
		'element' => $entry,
		'list' => ColumnService::LIST_ENTRIES,
		'labels' => [$label],
		'allLabels' => [$label, $other],
		'canManageLabels' => true,
		'checks' => $checks,
		'lastEdit' => $lastEdit,
		'references' => $referencing,
	])->andReturn('<hub>');

	$event = sidebarEvent($entry);
	(new DefineSidebarHtmlHandler())($event);

	expect($event->html)->toBe('<existing><hub>');
});

it('adds the panel to the sidebar of assets and users, with their own list and checks', function (Closure $sender, string $list, string $checks) {
	$element = $sender();
	$failed = [new Check('Check', 'Fix it.', 'Fine.', Severity::Notice, 'flag', false)];

	$services = sidebarServices($this);
	$services['checks']->shouldReceive($checks)->once()->with($element)->andReturn($failed);
	$services['edits']->shouldNotReceive('getLastEdit');
	$services['references']->shouldReceive('getReferences')->once()->with($element)->andReturn([]);
	$services['view']->shouldReceive('renderTemplate')->once()->with('hub/_sidebar/hub', [
		'element' => $element,
		'list' => $list,
		'labels' => [],
		'allLabels' => null,
		'canManageLabels' => false,
		'checks' => $failed,
		'lastEdit' => null,
		'references' => [],
	])->andReturn('<hub>');

	$event = sidebarEvent($element);
	(new DefineSidebarHtmlHandler())($event);

	expect($event->html)->toBe('<existing><hub>');
})->with([
	'asset' => [fn () => new Asset(['id' => 5]), ColumnService::LIST_FILES, 'forFile'],
	'user' => [fn () => new User(['id' => 5]), ColumnService::LIST_USERS, 'forUser'],
]);

it('shows the labels and checks of the canonical entry on its drafts, with its references', function () {
	$canonical = new Entry(['id' => 5]);
	$draft = Mockery::mock(Entry::class)->makePartial();
	$draft->id = 7;
	$draft->shouldReceive('getIsUnpublishedDraft')->andReturnFalse();
	$draft->shouldReceive('getCanonical')->with(true)->andReturn($canonical);

	$services = sidebarServices($this);
	$services['labels']->shouldReceive('getLabelsOf')->once()->with(ColumnService::LIST_ENTRIES, [5])->andReturn([]);
	$services['checks']->shouldReceive('forEntry')->once()->with($canonical)->andReturn([]);
	$services['edits']->shouldReceive('getLastEdit')->once()->with($canonical)->andReturn(['date' => null, 'user' => null]);
	$services['references']->shouldReceive('getReferences')->once()->with($draft)->andReturn([]);
	$services['view']->shouldReceive('renderTemplate')->once()->with('hub/_sidebar/hub', Mockery::on(fn (array $variables) => $variables['element'] === $canonical && $variables['references'] === []))->andReturn('<hub>');

	$event = sidebarEvent($draft);
	(new DefineSidebarHtmlHandler())($event);

	expect($event->html)->toBe('<existing><hub>');
});

it('offers no labels to pick on revisions, nor to users who may not label the element', function (Closure $sender, bool $canApply) {
	$services = sidebarServices($this);
	$services['labels']->shouldReceive('getAllLabels')->andReturn([1 => new Label(['id' => 1, 'name' => 'Review'])]);
	$services['labels']->shouldReceive('canApply')->andReturn($canApply);
	$services['checks']->shouldReceive('forEntry')->andReturn([]);
	$services['edits']->shouldReceive('getLastEdit')->andReturn(['date' => null, 'user' => null]);
	$services['references']->shouldReceive('getReferences')->andReturn([]);
	$services['view']->shouldReceive('renderTemplate')->once()->with('hub/_sidebar/hub', Mockery::on(fn (array $variables) => $variables['labels'] === [] && $variables['allLabels'] === null))->andReturn('<hub>');

	(new DefineSidebarHtmlHandler())(sidebarEvent($sender()));
})->with([
	'revision' => [function () {
		$revision = Mockery::mock(Entry::class)->makePartial();
		$revision->id = 7;
		$revision->shouldReceive('getIsUnpublishedDraft')->andReturnFalse();
		$revision->shouldReceive('getIsRevision')->andReturnTrue();
		$revision->shouldReceive('getCanonical')->andReturn(new Entry(['id' => 5]));

		return $revision;
	}, true],
	'without the permission' => [fn () => new Entry(['id' => 5]), false],
]);

it('leaves out the labels and checks while they are disabled', function () {
	$this->hub()->getSettings()->enableDiagnostics = false;

	try {
		$services = sidebarServices($this);
		$services['labels']->shouldReceive('isEnabled')->andReturnFalse();
		$services['labels']->shouldNotReceive('getLabelsOf');
		$services['checks']->shouldNotReceive('forFile');
		$services['references']->shouldReceive('getReferences')->andReturn([]);
		$services['view']->shouldReceive('renderTemplate')->once()->with('hub/_sidebar/hub', Mockery::on(fn (array $variables) => $variables['labels'] === null && $variables['allLabels'] === null && $variables['checks'] === null))->andReturn('<hub>');

		(new DefineSidebarHtmlHandler())(sidebarEvent(new Asset(['id' => 5])));
	} finally {
		$this->hub()->getSettings()->enableDiagnostics = true;
	}
});

it('leaves the sidebar of other elements, new entries and unpublished drafts alone', function (Closure $sender) {
	$this->mockComponent('hub.references', ReferenceService::class)->shouldNotReceive('getReferences');

	$event = sidebarEvent($sender());
	(new DefineSidebarHtmlHandler())($event);

	expect($event->html)->toBe('<existing>');
})->with([
	'category' => fn () => new Category(['id' => 5]),
	'new entry' => fn () => new Entry(),
	'unpublished draft' => function () {
		$draft = Mockery::mock(Entry::class)->makePartial();
		$draft->id = 5;
		$draft->shouldReceive('getIsUnpublishedDraft')->andReturnTrue();

		return $draft;
	},
]);

it('leaves the sidebar alone for users without access', function (Closure $sender, Page $page) {
	$this->actingAs(permissions: ['accessCp']);
	$this->mockComponent('hub.references', ReferenceService::class)->shouldNotReceive('getReferences');

	$event = sidebarEvent($sender());
	(new DefineSidebarHtmlHandler())($event);

	expect($event->html)->toBe('<existing>')
		->and(Craft::$app->getUser()->checkPermission($page->viewPermission()))->toBeFalse();
})->with([
	'entry' => [fn () => new Entry(['id' => 5]), Page::Entries],
	'asset' => [fn () => new Asset(['id' => 5]), Page::Assets],
	'user' => [fn () => new User(['id' => 5]), Page::Users],
]);
