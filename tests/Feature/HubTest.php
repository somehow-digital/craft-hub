<?php

use craft\base\Element;
use craft\elements\Entry;
use craft\services\UserPermissions;
use craft\web\Request;
use craft\web\UrlManager;
use craft\web\View;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\StructureService;
use yii\base\Event;

beforeEach(function () {
	$this->site(2, 'de');
	$this->site(3, 'fr');
});

it('registers its services', function () {
	expect(Hub::config()['components'])->toHaveKeys(['checks', 'entries', 'files', 'lists', 'references', 'review', 'statistics', 'structure', 'volumes'])
		->and($this->hub()->structure)->toBeInstanceOf(StructureService::class)
		->and($this->hub()->checks)->toBeInstanceOf(CheckService::class);
});

it('scopes to all editable sites without a selection', function () {
	$this->editableSiteIds = [3, 1];
	$this->request();

	expect($this->hub()->getSites())->toBe([])
		->and($this->hub()->getSiteParam())->toBeNull()
		->and($this->hub()->getSiteIds())->toBe([1, 3]);
});

it('scopes to the selected sites in the order of the sites', function () {
	$this->request([Hub::SITE_PARAM => ' fr, en ,fr,,unknown']);

	expect(array_map(fn ($site) => $site->handle, $this->hub()->getSites()))->toBe(['en', 'fr'])
		->and($this->hub()->getSiteParam())->toBe('en,fr')
		->and($this->hub()->getSiteIds())->toBe([1, 3]);
});

it('leaves out selected sites the user cannot edit', function () {
	$this->editableSiteIds = [1, 2];
	$this->request([Hub::SITE_PARAM => 'de,fr']);

	expect($this->hub()->getSiteIds())->toBe([2]);
});

it('ignores a site selection that is not a string', function () {
	$this->request([Hub::SITE_PARAM => ['en']]);

	expect($this->hub()->getSites())->toBe([]);
});

it('ignores the site selection of console requests', function () {
	$request = Mockery::mock(Request::class)->makePartial();
	$request->shouldReceive('getIsConsoleRequest')->andReturnTrue();
	$request->shouldNotReceive('getQueryParam');
	Craft::$app->set('request', $request);

	expect($this->hub()->getSites())->toBe([]);
});

it('remembers the site scope', function () {
	$this->request([Hub::SITE_PARAM => 'de']);
	expect($this->hub()->getSiteIds())->toBe([2]);

	$this->request([Hub::SITE_PARAM => 'fr']);
	expect($this->hub()->getSiteIds())->toBe([2])
		->and($this->hub()->getSiteParam())->toBe('de');
});

it('registers user permissions', function () {
	$event = new craft\events\RegisterUserPermissionsEvent();
	Event::trigger(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, $event);

	expect($event->permissions)->toHaveCount(1)
		->and($event->permissions[0]['heading'])->toBe('Hub')
		->and($event->permissions[0]['permissions'])->toHaveKey(Hub::PERMISSION_ACCESS);
});

it('registers routes and the entry sidebar on control panel requests', function () {
	$this->request(cp: true);
	$this->createPlugin();

	expect(Event::hasHandlers(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES))->toBeTrue()
		->and(Event::hasHandlers(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML))->toBeTrue();
});

it('registers neither routes nor the entry sidebar on site requests', function () {
	Event::offAll();
	$this->request(cp: false);
	$this->createPlugin();

	expect(Event::hasHandlers(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES))->toBeFalse()
		->and(Event::hasHandlers(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML))->toBeFalse()
		->and(Event::hasHandlers(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS))->toBeTrue();
});

it('hides the navigation item from users without access', function () {
	$this->actingAs(permissions: []);

	expect($this->hub()->getCpNavItem())->toBeNull();
});

it('links the navigation item to the Hub pages', function () {
	$this->request([Hub::SITE_PARAM => 'de'], path: 'entries');

	$item = $this->hub()->getCpNavItem();

	expect($item['label'])->toBe('Hub')
		->and($item['url'])->toBe('hub')
		->and(array_keys($item['subnav']))->toBe(['overview', 'entries', 'assets'])
		->and($item['subnav']['entries']['url'])->not->toContain(Hub::SITE_PARAM);
});

it('keeps the selected sites in the navigation on Hub pages', function () {
	$this->request([Hub::SITE_PARAM => 'de'], path: 'hub/entries');

	expect($this->hub()->getCpNavItem()['subnav']['assets']['url'])->toContain(Hub::SITE_PARAM . '=de');
});

it('has settings with defaults', function () {
	$settings = $this->hub()->getSettings();

	expect($settings)->toBeInstanceOf(Settings::class)
		->and($settings->staleThresholdMonths)->toBe(12)
		->and($settings->largeFileThresholdMb)->toBe(5)
		->and($settings->largeImageThresholdPx)->toBe(4000);
});

it('renders the settings along with the ones overridden by the config file', function () {
	$file = Craft::getAlias('@config/hub.php');
	file_put_contents($file, '<?php return ["staleThresholdMonths" => 6];');

	$view = $this->mockComponent('view', View::class);
	$view->shouldReceive('renderTemplate')
		->once()
		->with('hub/settings/plugin', Mockery::on(fn (array $variables) => $variables['settings'] === $this->hub()->getSettings() && $variables['overrides'] === ['staleThresholdMonths']))
		->andReturn('<settings>');

	try {
		expect((fn () => $this->settingsHtml())->call($this->hub()))->toBe('<settings>');
	} finally {
		unlink($file);
	}
});
