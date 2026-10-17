<?php

use craft\base\Element;
use craft\elements\Entry;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\Request;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\User as WebUser;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\services\SnapshotService;
use SomehowDigital\Craft\Hub\services\StructureService;
use SomehowDigital\Craft\Hub\services\TrashService;
use yii\base\Event;

beforeEach(function () {
	$this->site(2, 'de');
	$this->site(3, 'fr');
});

it('registers its services', function () {
	expect(Hub::config()['components'])->toHaveKeys(['checks', 'entries', 'files', 'lists', 'references', 'review', 'statistics', 'structure', 'labels', 'users', 'volumes'])
		->and($this->hub()->structure)->toBeInstanceOf(StructureService::class)
		->and($this->hub()->checks)->toBeInstanceOf(CheckService::class);
});

it('scopes to all editable sites without a selection', function () {
	$this->editableSiteIds = [3, 1];
	$this->request();

	expect($this->hub()->siteScope->getSites())->toBe([])
		->and($this->hub()->siteScope->getSiteParam())->toBeNull()
		->and($this->hub()->siteScope->getSiteIds())->toBe([1, 3]);
});

it('scopes to the selected sites in the order of the sites', function () {
	$this->request([Hub::SITE_PARAM => ' fr, en ,fr,,unknown']);

	expect(array_map(fn ($site) => $site->handle, $this->hub()->siteScope->getSites()))->toBe(['en', 'fr'])
		->and($this->hub()->siteScope->getSiteParam())->toBe('en,fr')
		->and($this->hub()->siteScope->getSiteIds())->toBe([1, 3]);
});

it('leaves out selected sites the user cannot edit', function () {
	$this->editableSiteIds = [1, 2];
	$this->request([Hub::SITE_PARAM => 'de,fr']);

	expect($this->hub()->siteScope->getSiteIds())->toBe([2]);
});

it('ignores a site selection that is not a string', function () {
	$this->request([Hub::SITE_PARAM => ['en']]);

	expect($this->hub()->siteScope->getSites())->toBe([]);
});

it('ignores the site selection of console requests', function () {
	$request = Mockery::mock(Request::class)->makePartial();
	$request->shouldReceive('getIsConsoleRequest')->andReturnTrue();
	$request->shouldNotReceive('getQueryParam');
	Craft::$app->set('request', $request);

	expect($this->hub()->siteScope->getSites())->toBe([]);
});

it('remembers the site scope', function () {
	$this->request([Hub::SITE_PARAM => 'de']);
	expect($this->hub()->siteScope->getSiteIds())->toBe([2]);

	$this->request([Hub::SITE_PARAM => 'fr']);
	expect($this->hub()->siteScope->getSiteIds())->toBe([2])
		->and($this->hub()->siteScope->getSiteParam())->toBe('de');
});

it('registers user permissions', function () {
	$event = new craft\events\RegisterUserPermissionsEvent();
	Event::trigger(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, $event);

	expect($event->permissions)->toHaveCount(1)
		->and($event->permissions[0]['heading'])->toBe('Hub')
		->and(array_keys($event->permissions[0]['permissions']))->toBe([
			Hub::PERMISSION_ACCESS_DATA,
			Hub::PERMISSION_ACCESS_STRUCTURE,
			Hub::PERMISSION_MANAGE_LABELS,
			Hub::PERMISSION_MANAGE_MAINTAINERS,
			Hub::PERMISSION_MANAGE_DIAGNOSTICS,
			Hub::PERMISSION_MANAGE_FILTERS,
			Hub::PERMISSION_VIEW_ACTIVITY,
		])
		->and(array_keys($event->permissions[0]['permissions'][Hub::PERMISSION_ACCESS_DATA]['nested']))->toBe(['hub-viewEntries', 'hub-viewAssets', 'hub-viewUsers'])
		->and(array_keys($event->permissions[0]['permissions'][Hub::PERMISSION_ACCESS_STRUCTURE]['nested']))->toBe(['hub-viewEntryTypes', 'hub-viewSections', 'hub-viewFields', 'hub-viewVolumes'])
		->and(array_keys($event->permissions[0]['permissions'][Hub::PERMISSION_ACCESS_DATA]['nested']['hub-viewAssets']['nested']))->toBe([
			'hub-labelAssets',
			'hub-bulkEditAssets',
			Hub::PERMISSION_EXPORT_ASSETS,
			Hub::PERMISSION_DOWNLOAD_ASSETS,
			Hub::PERMISSION_MOVE_ASSETS,
			Hub::PERMISSION_GENERATE_TRANSFORMS,
			Hub::PERMISSION_DELETE_TRANSFORMS,
			Hub::PERMISSION_DELETE_ASSETS,
			Hub::PERMISSION_VIEW_TRASHED_ASSETS,
		])
		->and(array_keys($event->permissions[0]['permissions'][Hub::PERMISSION_ACCESS_DATA]['nested']['hub-viewAssets']['nested'][Hub::PERMISSION_VIEW_TRASHED_ASSETS]['nested']))->toBe([
			Hub::PERMISSION_EXPORT_TRASHED_ASSETS,
			Hub::PERMISSION_RESTORE_ASSETS,
			Hub::PERMISSION_PURGE_ASSETS,
		])
		->and(array_keys($event->permissions[0]['permissions'][Hub::PERMISSION_ACCESS_DATA]['nested']['hub-viewEntries']['nested'][Hub::PERMISSION_VIEW_TRASHED_ENTRIES]['nested']))->toBe([
			Hub::PERMISSION_EXPORT_TRASHED_ENTRIES,
			Hub::PERMISSION_RESTORE_ENTRIES,
			Hub::PERMISSION_PURGE_ENTRIES,
		])
		->and(array_keys($event->permissions[0]['permissions'][Hub::PERMISSION_ACCESS_DATA]['nested']['hub-viewEntries']['nested']))->toBe([
			'hub-labelEntries',
			'hub-bulkEditEntries',
			Hub::PERMISSION_EXPORT_ENTRIES,
			Hub::PERMISSION_ENABLE_ENTRIES,
			Hub::PERMISSION_CHANGE_AUTHORS,
			Hub::PERMISSION_MOVE_ENTRIES,
			Hub::PERMISSION_DELETE_DRAFTS,
			Hub::PERMISSION_DELETE_ENTRIES,
			Hub::PERMISSION_VIEW_TRASHED_ENTRIES,
		])
		->and(array_keys($event->permissions[0]['permissions'][Hub::PERMISSION_ACCESS_DATA]['nested']['hub-viewUsers']['nested']))->toBe(['hub-labelUsers', 'hub-bulkEditUsers', Hub::PERMISSION_EXPORT_USERS, Hub::PERMISSION_ASSIGN_USER_GROUPS, Hub::PERMISSION_SUSPEND_USERS, Hub::PERMISSION_DELETE_USERS])
		->and(array_keys($event->permissions[0]['permissions'][Hub::PERMISSION_ACCESS_STRUCTURE]['nested']['hub-viewFields']['nested']))->toBe(['hub-labelFields', 'hub-bulkEditFields']);
});

it('removes the labels of deleted items, the expired activities and snapshots and the purged kept files along with the garbage', function () {
	$this->mockComponent('hub.labels', LabelService::class)->shouldReceive('deleteOrphans')->once();
	$this->mockComponent('hub.activity', ActivityService::class)->shouldReceive('deleteExpired')->once();
	$this->mockComponent('hub.snapshots', SnapshotService::class)->shouldReceive('deleteExpired')->once();
	$this->mockComponent('hub.trash', TrashService::class)->shouldReceive('deletePurgedFiles')->once();

	Event::trigger(Gc::class, Gc::EVENT_RUN);
});

it('keeps the files of trashed assets while it is enabled', function (bool $enabled) {
	Event::offAll();
	$hub = $this->createPlugin();
	$hub->getSettings()->keepTrashedFiles = $enabled;
	$hub->getSettings()->enableActivity = false;
	Event::offAll();
	$hub->init();

	expect(Event::hasHandlers(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT))->toBe($enabled)
		->and(Event::hasHandlers(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT))->toBe($enabled);
})->with([true, false]);

it('tracks the activity of users while the activity log is enabled', function (bool $enabled) {
	Event::offAll();
	$hub = $this->createPlugin();
	$hub->getSettings()->enableActivity = $enabled;
	Event::offAll();
	$hub->init();

	expect(Event::hasHandlers(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT))->toBe($enabled)
		->and(Event::hasHandlers(WebUser::class, WebUser::EVENT_AFTER_LOGIN))->toBe($enabled);
})->with([true, false]);

it('registers routes, the navigation and the entry sidebar on control panel requests', function () {
	$this->request(cp: true);
	$this->createPlugin();

	expect(Event::hasHandlers(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES))->toBeTrue()
		->and(Event::hasHandlers(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS))->toBeTrue()
		->and(Event::hasHandlers(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML))->toBeTrue()
		->and(Event::hasHandlers(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST))->toBeTrue();
});

it('pushes a snapshot once one is due after control panel requests', function () {
	$this->request(cp: true);
	$this->createPlugin();
	$this->mockComponent('hub.snapshots', SnapshotService::class)->shouldReceive('pushIfDue')->once();

	Event::trigger(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST);
});

it('registers neither routes, the navigation nor the entry sidebar on site requests', function () {
	Event::offAll();
	$this->request(cp: false);
	$this->createPlugin();

	expect(Event::hasHandlers(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES))->toBeFalse()
		->and(Event::hasHandlers(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS))->toBeFalse()
		->and(Event::hasHandlers(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML))->toBeFalse()
		->and(Event::hasHandlers(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST))->toBeFalse()
		->and(Event::hasHandlers(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS))->toBeTrue();
});

it('hides the navigation item from users without access', function () {
	$this->actingAs(permissions: []);

	expect($this->hub()->getCpNavItem())->toBeNull();
});

it('has the first navigation item the user may open as the plugin\'s own', function (array $permissions, ?string $url) {
	$this->request();
	$this->actingAs(permissions: $permissions);

	expect($this->hub()->getCpNavItem()['url'] ?? null)->toBe($url);
})->with([
	'data' => [[Hub::PERMISSION_ACCESS_DATA, Page::Assets->viewPermission(), Hub::PERMISSION_ACCESS_STRUCTURE, Page::Fields->viewPermission()], 'hub/data'],
	'structure' => [[Hub::PERMISSION_ACCESS_STRUCTURE, Page::Fields->viewPermission(), Hub::PERMISSION_MANAGE_LABELS], 'hub/structure'],
	'hub' => [[Hub::PERMISSION_ACCESS_DATA, Hub::PERMISSION_MANAGE_LABELS], 'hub/manage'],
	'a page without its area' => [[Page::Entries->viewPermission()], null],
]);

it('links the navigation items to the Hub pages', function () {
	$this->request([Hub::SITE_PARAM => 'de'], path: 'entries');

	$data = $this->hub()->navigation->getDataNavItem();
	$structure = $this->hub()->navigation->getStructureNavItem();

	expect($data['label'])->toBe('Data')
		->and($data['url'])->toBe('hub/data')
		->and(array_keys($data['subnav']))->toBe(['entries', 'assets', 'users'])
		->and($data['subnav']['entries']['url'])->toBe('hub/data/entries')
		->and($data['subnav']['entries']['linkAttributes']['href'])->toContain('hub/data/entries')->not->toContain(Hub::SITE_PARAM)
		->and($structure['label'])->toBe('Structure')
		->and($structure['url'])->toBe('hub/structure')
		->and(array_keys($structure['subnav']))->toBe(['entry-types', 'sections', 'fields', 'volumes'])
		->and($structure['subnav']['volumes']['url'])->toBe('hub/structure/volumes');
});

it('leaves out the pages the user may not view', function () {
	$this->request();
	$this->actingAs(permissions: [Hub::PERMISSION_ACCESS_DATA, Page::Entries->viewPermission(), Page::Assets->viewPermission(), Page::Volumes->viewPermission()]);

	expect(array_keys($this->hub()->navigation->getDataNavItem()['subnav']))->toBe(['entries', 'assets'])
		->and($this->hub()->navigation->getStructureNavItem())->toBeNull();

	$this->actingAs(permissions: [Hub::PERMISSION_ACCESS_STRUCTURE, Page::EntryTypes->viewPermission(), Page::Volumes->viewPermission()]);

	expect($this->hub()->navigation->getDataNavItem())->toBeNull()
		->and(array_keys($this->hub()->navigation->getStructureNavItem()['subnav']))->toBe(['entry-types', 'volumes']);
});

it('lets users label, bulk-edit and act on the items of a page only while they may view it', function () {
	$this->actingAs(permissions: [Page::Entries->labelPermission(), Page::Entries->bulkEditPermission(), Hub::PERMISSION_DELETE_ENTRIES]);

	expect($this->hub()->access->canLabel(Page::Entries))->toBeFalse()
		->and($this->hub()->access->canBulkEdit(Page::Entries))->toBeFalse()
		->and($this->hub()->access->canAct(Page::Entries, Hub::PERMISSION_DELETE_ENTRIES))->toBeFalse();

	$this->actingAs(permissions: [Hub::PERMISSION_ACCESS_DATA, Page::Entries->viewPermission(), Page::Entries->labelPermission(), Page::Entries->bulkEditPermission(), Hub::PERMISSION_DELETE_ENTRIES, Hub::PERMISSION_DELETE_ASSETS]);

	expect($this->hub()->access->canLabel(Page::Entries))->toBeTrue()
		->and($this->hub()->access->canBulkEdit(Page::Entries))->toBeTrue()
		->and($this->hub()->access->canAct(Page::Entries, Hub::PERMISSION_DELETE_ENTRIES))->toBeTrue()
		->and($this->hub()->access->canAct(Page::Entries, Hub::PERMISSION_DELETE_ASSETS))->toBeFalse()
		->and($this->hub()->access->canView('entries'))->toBeTrue()
		->and($this->hub()->access->canView('history'))->toBeFalse();
});

it('keeps the selected sites in the navigation on Hub pages', function () {
	$this->request([Hub::SITE_PARAM => 'de'], path: 'hub/data/entries');

	expect($this->hub()->navigation->getDataNavItem()['subnav']['assets']['linkAttributes']['href'])->toContain(Hub::SITE_PARAM . '=de')
		->and($this->hub()->navigation->getStructureNavItem()['subnav']['fields']['linkAttributes']['href'])->toContain(Hub::SITE_PARAM . '=de');
});

it('has settings with defaults', function () {
	$settings = $this->hub()->getSettings();

	expect($settings)->toBeInstanceOf(Settings::class)
		->and($settings->enableDiagnostics)->toBeTrue()
		->and($settings->activityRetentionDays)->toBe(90);
});

it('leads Craft\'s settings page of the plugin to its own settings page', function () {
	$this->request();

	expect($this->hub()->getSettingsResponse()->getHeaders()->get('Location'))->toContain('hub/manage/settings');
});

it('has the activity log and a settings page in the Hub navigation item', function () {
	$this->request();

	expect(array_keys($this->hub()->navigation->getManageNavItem()['subnav']))->toBe(['activity', 'settings'])
		->and($this->hub()->navigation->getManageNavItem()['subnav']['activity']['url'])->toBe('hub/manage/activity')
		->and($this->hub()->navigation->getManageNavItem()['subnav']['settings']['url'])->toBe('hub/manage/settings');
});

it('has only the pages of the Hub navigation item the user may open', function (array $permissions, bool $activity, ?array $pages) {
	$this->request();
	$this->actingAs(permissions: [Hub::PERMISSION_ACCESS_DATA, ...$permissions]);
	$this->hub()->getSettings()->enableActivity = $activity;

	expect($pages === null ? $this->hub()->navigation->getManageNavItem() : array_keys($this->hub()->navigation->getManageNavItem()['subnav']))->toBe($pages);
})->with([
	'activity only' => [[Hub::PERMISSION_VIEW_ACTIVITY], true, ['activity']],
	'settings only' => [[Hub::PERMISSION_MANAGE_LABELS], true, ['settings']],
	'filters only' => [[Hub::PERMISSION_MANAGE_FILTERS], true, ['settings']],
	'activity disabled' => [[Hub::PERMISSION_VIEW_ACTIVITY, Hub::PERMISSION_MANAGE_DIAGNOSTICS], false, ['settings']],
	'neither' => [[Hub::PERMISSION_VIEW_ACTIVITY], false, null],
]);

it('gives the predefined filters and default checks their rows once installed', function () {
	$filters = count($this->hub()->filters->getFilters());
	$checks = count($this->hub()->diagnostics->getDefinitions());

	(fn () => $this->afterInstall())->call($this->hub());

	expect($this->db()->queriesMatching('INSERT INTO {{%hub_filters}}'))->toHaveCount($filters)
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_checks}}'))->toHaveCount($checks);
});
