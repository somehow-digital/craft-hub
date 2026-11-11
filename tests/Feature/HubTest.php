<?php

declare(strict_types=1);

use craft\base\Element;
use craft\elements\Entry;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\queue\Queue;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\Request;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\User as WebUser;
use Mockery\MockInterface;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\jobs\TakeSnapshotJob;
use SomehowDigital\Craft\Hub\models\FilterParams;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\StructureService;
use SomehowDigital\Craft\Hub\widgets\MyWorkWidget;
use yii\base\Event;

beforeEach(function (): void {
	$this->site(2, 'de');
	$this->site(3, 'fr');
});

it('registers its services', function (): void {
	expect(Hub::config()['components'])->toHaveKeys(['checks', 'entries', 'files', 'lists', 'references', 'review', 'statistics', 'structure', 'labels', 'users', 'volumes'])
		->and($this->hub()->structure)->toBeInstanceOf(StructureService::class)
		->and($this->hub()->checks)->toBeInstanceOf(CheckService::class);
});

it('scopes to all editable sites without a selection', function (): void {
	$this->editableSiteIds = [3, 1];
	$this->request();

	expect($this->hub()->siteScope->getSites())->toBe([])
		->and($this->hub()->siteScope->getSiteParam())->toBeNull()
		->and($this->hub()->siteScope->getSiteIds())->toBe([1, 3]);
});

it('scopes to the selected sites in the order of the sites', function (): void {
	$this->request([FilterParams::SITE_PARAM => ' fr, en ,fr,,unknown']);

	expect(array_map(fn(mixed $site): mixed => at($site, 'handle'), $this->hub()->siteScope->getSites()))->toBe(['en', 'fr'])
		->and($this->hub()->siteScope->getSiteParam())->toBe('en,fr')
		->and($this->hub()->siteScope->getSiteIds())->toBe([1, 3]);
});

it('leaves out selected sites the user cannot edit', function (): void {
	$this->editableSiteIds = [1, 2];
	$this->request([FilterParams::SITE_PARAM => 'de,fr']);

	expect($this->hub()->siteScope->getSiteIds())->toBe([2]);
});

it('ignores a site selection that is not a string', function (): void {
	$this->request([FilterParams::SITE_PARAM => ['en']]);

	expect($this->hub()->siteScope->getSites())->toBe([]);
});

it('ignores the site selection of console requests', function (): void {
	/** @var Request&MockInterface $request */
	$request = Mockery::mock(Request::class)->makePartial();
	$request->shouldReceive('getIsConsoleRequest')->andReturnTrue();
	$request->shouldNotReceive('getQueryParam');
	Craft::$app->set('request', $request);

	expect($this->hub()->siteScope->getSites())->toBe([]);
});

it('remembers the site scope', function (): void {
	$this->request([FilterParams::SITE_PARAM => 'de']);
	expect($this->hub()->siteScope->getSiteIds())->toBe([2]);

	$this->request([FilterParams::SITE_PARAM => 'fr']);
	expect($this->hub()->siteScope->getSiteIds())->toBe([2])
		->and($this->hub()->siteScope->getSiteParam())->toBe('de');
});

it('registers user permissions', function (): void {
	$event = new RegisterUserPermissionsEvent();
	Event::trigger(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, $event);

	expect($event->permissions)->toHaveCount(1)
		->and(at($event->permissions, 0, 'heading'))->toBe('Hub')
		->and(array_keys(arrayAt($event->permissions, 0, 'permissions')))->toBe([
			Permission::ViewWork->value,
			Permission::AccessData->value,
			Permission::AccessStructure->value,
			Permission::ManageLabels->value,
			Permission::ManageMaintainers->value,
			Permission::ManageDiagnostics->value,
			Permission::ManageFilters->value,
			Permission::ViewActivity->value,
		])
		->and(array_keys(arrayAt($event->permissions, 0, 'permissions', Permission::AccessData->value, 'nested')))->toBe(['hub-viewEntries', 'hub-viewAssets', 'hub-viewUsers'])
		->and(array_keys(arrayAt($event->permissions, 0, 'permissions', Permission::AccessStructure->value, 'nested')))->toBe(['hub-viewEntryTypes', 'hub-viewSections', 'hub-viewFields', 'hub-viewVolumes'])
		->and(array_keys(arrayAt($event->permissions, 0, 'permissions', Permission::AccessData->value, 'nested', 'hub-viewAssets', 'nested')))->toBe([
			'hub-labelAssets',
			'hub-bulkEditAssets',
			Permission::ExportAssets->value,
			Permission::DownloadAssets->value,
			Permission::MoveAssets->value,
			Permission::GenerateTransforms->value,
			Permission::DeleteTransforms->value,
			Permission::DeleteAssets->value,
			Permission::ViewTrashedAssets->value,
		])
		->and(array_keys(arrayAt($event->permissions, 0, 'permissions', Permission::AccessData->value, 'nested', 'hub-viewAssets', 'nested', Permission::ViewTrashedAssets->value, 'nested')))->toBe([
			Permission::ExportTrashedAssets->value,
			Permission::RestoreAssets->value,
			Permission::PurgeAssets->value,
		])
		->and(array_keys(arrayAt($event->permissions, 0, 'permissions', Permission::AccessData->value, 'nested', 'hub-viewEntries', 'nested', Permission::ViewTrashedEntries->value, 'nested')))->toBe([
			Permission::ExportTrashedEntries->value,
			Permission::RestoreEntries->value,
			Permission::PurgeEntries->value,
		])
		->and(array_keys(arrayAt($event->permissions, 0, 'permissions', Permission::AccessData->value, 'nested', 'hub-viewEntries', 'nested')))->toBe([
			'hub-labelEntries',
			'hub-bulkEditEntries',
			Permission::ExportEntries->value,
			Permission::EnableEntries->value,
			Permission::ChangeAuthors->value,
			Permission::MoveEntries->value,
			Permission::DeleteDrafts->value,
			Permission::DeleteEntries->value,
			Permission::ViewTrashedEntries->value,
		])
		->and(array_keys(arrayAt($event->permissions, 0, 'permissions', Permission::AccessData->value, 'nested', 'hub-viewUsers', 'nested')))->toBe(['hub-labelUsers', 'hub-bulkEditUsers', Permission::ExportUsers->value, Permission::AssignUserGroups->value, Permission::SuspendUsers->value, Permission::DeleteUsers->value])
		->and(array_keys(arrayAt($event->permissions, 0, 'permissions', Permission::AccessStructure->value, 'nested', 'hub-viewFields', 'nested')))->toBe(['hub-labelFields', 'hub-bulkEditFields']);
});

it('removes the labels of deleted items, the expired activities and snapshots and the purged kept files along with the garbage', function (): void {
	$this->db()->tables = [Tables::ACTIVITY, Tables::SNAPSHOTS, Tables::KEPT_FILES];
	$this->hub()->settings->activityRetentionDays = 30;
	$this->hub()->settings->snapshotRetentionDays = 30;

	Event::trigger(Gc::class, Gc::EVENT_RUN);

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_labeled}}'))->not->toBe([])
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_activity}}'))->toHaveCount(1)
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_snapshots}}'))->toHaveCount(1)
		->and($this->db()->queriesMatching('FROM {{%hub_kept_files}} `kept`'))->toHaveCount(1);
});

it('keeps the files of trashed assets while it is enabled', function (bool $enabled): void {
	Event::offAll();
	$hub = $this->createPlugin();
	$hub->settings->keepTrashedFiles = $enabled;
	$hub->settings->enableActivity = false;
	Event::offAll();
	$hub->init();

	expect(Event::hasHandlers(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT))->toBe($enabled)
		->and(Event::hasHandlers(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT))->toBe($enabled);
})->with([true, false]);

it('tracks the activity of users while the activity log is enabled', function (bool $enabled): void {
	Event::offAll();
	$hub = $this->createPlugin();
	$hub->settings->enableActivity = $enabled;
	Event::offAll();
	$hub->init();

	expect(Event::hasHandlers(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT))->toBe($enabled)
		->and(Event::hasHandlers(WebUser::class, WebUser::EVENT_AFTER_LOGIN))->toBe($enabled);
})->with([true, false]);

it('registers routes, the navigation and the entry sidebar on control panel requests', function (): void {
	$this->request(cp: true);
	$this->createPlugin();

	expect(Event::hasHandlers(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES))->toBeTrue()
		->and(Event::hasHandlers(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS))->toBeTrue()
		->and(Event::hasHandlers(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML))->toBeTrue()
		->and(Event::hasHandlers(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST))->toBeTrue();
});

it('pushes a snapshot once one is due after control panel requests', function (): void {
	$this->request(cp: true);
	$this->createPlugin();
	$this->db()->tables = [Tables::SNAPSHOTS];
	$this->db()->respond('MAX(`date`)', '2026-10-20');
	$this->mockComponent('queue', Queue::class)->makePartial()->shouldReceive('push')->once()->with(Mockery::type(TakeSnapshotJob::class));

	Event::trigger(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST);
});

it('registers neither routes, the navigation nor the entry sidebar on site requests', function (): void {
	Event::offAll();
	$this->request(cp: false);
	$this->createPlugin();

	expect(Event::hasHandlers(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES))->toBeFalse()
		->and(Event::hasHandlers(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS))->toBeFalse()
		->and(Event::hasHandlers(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML))->toBeFalse()
		->and(Event::hasHandlers(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST))->toBeFalse()
		->and(Event::hasHandlers(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS))->toBeTrue();
});

it('hides the navigation item from users without access', function (): void {
	$this->actingAs(permissions: []);

	expect($this->hub()->getCpNavItem())->toBeNull();
});

it("has the first navigation item the user may open as the plugin's own", function (array $permissions, ?string $url): void {
	$this->request();
	$this->actingAs(permissions: strings($permissions));

	expect($this->hub()->getCpNavItem()['url'] ?? null)->toBe($url);
})->with([
	'data' => [[Permission::AccessData->value, Page::Assets->viewPermission(), Permission::AccessStructure->value, Page::Fields->viewPermission()], 'hub/data'],
	'structure' => [[Permission::AccessStructure->value, Page::Fields->viewPermission(), Permission::ManageLabels->value], 'hub/structure'],
	'hub' => [[Permission::AccessData->value, Permission::ManageLabels->value], 'hub/manage'],
	'a page without its area' => [[Page::Entries->viewPermission()], null],
]);

it('links the navigation items to the Hub pages', function (): void {
	$this->request([FilterParams::SITE_PARAM => 'de'], path: 'entries');

	$data = $this->hub()->navigation->getDataNavItem();
	$structure = $this->hub()->navigation->getStructureNavItem();

	expect(at($data, 'label'))->toBe('Data')
		->and(at($data, 'url'))->toBe('hub/data')
		->and(array_keys(arrayAt($data, 'subnav')))->toBe(['entries', 'assets', 'users'])
		->and(at($data, 'subnav', 'entries', 'url'))->toBe('hub/data/entries')
		->and(at($data, 'subnav', 'entries', 'linkAttributes', 'href'))->toContain('hub/data/entries')->not->toContain(FilterParams::SITE_PARAM)
		->and(at($structure, 'label'))->toBe('Structure')
		->and(at($structure, 'url'))->toBe('hub/structure')
		->and(array_keys(arrayAt($structure, 'subnav')))->toBe(['entry-types', 'sections', 'fields', 'volumes'])
		->and(at($structure, 'subnav', 'volumes', 'url'))->toBe('hub/structure/volumes');
});

it('leaves out the pages the user may not view', function (): void {
	$this->request();
	$this->actingAs(permissions: [Permission::AccessData->value, Page::Entries->viewPermission(), Page::Assets->viewPermission(), Page::Volumes->viewPermission()]);

	expect(array_keys(arrayAt($this->hub()->navigation->getDataNavItem(), 'subnav')))->toBe(['entries', 'assets'])
		->and($this->hub()->navigation->getStructureNavItem())->toBeNull();

	$this->actingAs(permissions: [Permission::AccessStructure->value, Page::EntryTypes->viewPermission(), Page::Volumes->viewPermission()]);

	expect($this->hub()->navigation->getDataNavItem())->toBeNull()
		->and(array_keys(arrayAt($this->hub()->navigation->getStructureNavItem(), 'subnav')))->toBe(['entry-types', 'volumes']);
});

it('lets users label, bulk-edit and act on the items of a page only while they may view it', function (): void {
	$this->actingAs(permissions: [Page::Entries->labelPermission(), Page::Entries->bulkEditPermission(), Permission::DeleteEntries->value]);

	expect($this->hub()->access->canLabel(Page::Entries))->toBeFalse()
		->and($this->hub()->access->canBulkEdit(Page::Entries))->toBeFalse()
		->and($this->hub()->access->canAct(Page::Entries, Permission::DeleteEntries->value))->toBeFalse();

	$this->actingAs(permissions: [Permission::AccessData->value, Page::Entries->viewPermission(), Page::Entries->labelPermission(), Page::Entries->bulkEditPermission(), Permission::DeleteEntries->value, Permission::DeleteAssets->value]);

	expect($this->hub()->access->canLabel(Page::Entries))->toBeTrue()
		->and($this->hub()->access->canBulkEdit(Page::Entries))->toBeTrue()
		->and($this->hub()->access->canAct(Page::Entries, Permission::DeleteEntries->value))->toBeTrue()
		->and($this->hub()->access->canAct(Page::Entries, Permission::DeleteAssets->value))->toBeFalse()
		->and($this->hub()->access->canView('entries'))->toBeTrue()
		->and($this->hub()->access->canView('history'))->toBeFalse();
});

it('keeps the selected sites in the navigation on Hub pages', function (): void {
	$this->request([FilterParams::SITE_PARAM => 'de'], path: 'hub/data/entries');

	expect(at($this->hub()->navigation->getDataNavItem(), 'subnav', 'assets', 'linkAttributes', 'href'))->toContain(FilterParams::SITE_PARAM . '=de')
		->and(at($this->hub()->navigation->getStructureNavItem(), 'subnav', 'fields', 'linkAttributes', 'href'))->toContain(FilterParams::SITE_PARAM . '=de');
});

it('has settings with defaults', function (): void {
	$settings = $this->hub()->settings;

	expect($settings)->toBeInstanceOf(Settings::class)
		->and($settings->enableDiagnostics)->toBeTrue()
		->and($settings->activityRetentionDays)->toBe(90);
});

it("leads Craft's settings page of the plugin to its own settings page", function (): void {
	$this->request();

	expect($this->hub()->getSettingsResponse()->getHeaders()->get('Location'))->toContain('hub/manage/settings');
});

it('has the work of the user, the activity log and a settings page in the Hub navigation item', function (): void {
	$this->request();

	expect(array_keys(arrayAt($this->hub()->navigation->getManageNavItem(), 'subnav')))->toBe(['work', 'activity', 'settings'])
		->and(at($this->hub()->navigation->getManageNavItem(), 'subnav', 'work', 'url'))->toBe('hub/manage/work')
		->and(at($this->hub()->navigation->getManageNavItem(), 'subnav', 'activity', 'url'))->toBe('hub/manage/activity')
		->and(at($this->hub()->navigation->getManageNavItem(), 'subnav', 'settings', 'url'))->toBe('hub/manage/settings');
});

it('has only the pages of the Hub navigation item the user may open', function (array $permissions, bool $activity, ?array $pages): void {
	$this->request();
	$this->actingAs(permissions: [Permission::AccessData->value, ...strings($permissions)]);
	$this->hub()->settings->enableActivity = $activity;

	expect($pages === null ? $this->hub()->navigation->getManageNavItem() : array_keys(arrayAt($this->hub()->navigation->getManageNavItem(), 'subnav')))->toBe($pages);
})->with([
	'activity only' => [[Permission::ViewActivity->value], true, ['activity']],
	'settings only' => [[Permission::ManageLabels->value], true, ['settings']],
	'filters only' => [[Permission::ManageFilters->value], true, ['settings']],
	'activity disabled' => [[Permission::ViewActivity->value, Permission::ManageDiagnostics->value], false, ['settings']],
	'neither' => [[Permission::ViewActivity->value], false, null],
	'work and activity' => [['accessPlugin-hub', Permission::ViewWork->value, Permission::ViewActivity->value], true, ['work', 'activity']],
]);

it('badges the Hub navigation item with the number of issues, for users who may view their work', function (bool $canView, int $badge): void {
	$this->request();
	$this->actingAs(permissions: $canView ? [Permission::PLUGIN, Permission::ViewWork->value, Permission::ViewActivity->value] : [Permission::PLUGIN, Permission::ViewActivity->value]);
	mocked(Craft::$app->getUser())->shouldReceive('getId')->andReturn(7);
	Craft::$app->getCache()?->set('hub:work:counts:7:' . implode(',', $this->hub()->siteScope->getSiteIds()), ['drafts' => 1, 'diagnostics' => 4, 'comingUp' => 0, 'checks' => []]);

	expect(at($this->hub()->navigation->getManageNavItem(), 'badgeCount'))->toBe($badge);
})->with([
	'may view' => [true, 4],
	'may not view' => [false, 0],
]);

it('offers the dashboard widget', function (): void {
	$this->request();
	$event = new RegisterComponentTypesEvent();
	(fn() => $this->registerWidgets())->call($this->hub());

	Event::trigger(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, $event);

	expect($event->types)->toContain(MyWorkWidget::class);
});

it('gives the predefined filters and default checks their rows once installed', function (): void {
	$filters = count($this->hub()->filters->getFilters());
	$checks = count($this->hub()->diagnostics->getDefinitions());

	(fn() => $this->afterInstall())->call($this->hub());

	expect($this->db()->queriesMatching('INSERT INTO {{%hub_filters}}'))->toHaveCount($filters)
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_checks}}'))->toHaveCount($checks);
});
