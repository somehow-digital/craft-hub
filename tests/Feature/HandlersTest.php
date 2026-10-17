<?php

use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\View;
use SomehowDigital\Craft\Hub\assets\SidebarAsset;
use SomehowDigital\Craft\Hub\handlers\DefineEntrySidebarHtmlHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpNavItemsHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpRoutesHandler;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryReference;
use SomehowDigital\Craft\Hub\services\ReferenceService;

it('routes the Hub pages to their controllers', function () {
	$event = new RegisterUrlRulesEvent();
	(new RegisterCpRoutesHandler())($event);

	expect($event->rules)->toMatchArray([
		'hub/data' => 'hub/nav/data',
		'hub/data/entries' => 'hub/entries/index',
		'hub/data/entries/diagnostics' => 'hub/entries/diagnostics',
		'hub/data/entries/summary' => 'hub/entries/summary',
		'hub/data/entries/<id:\d+>' => 'hub/entries/view',
		'hub/data/entries/<id:\d+>/<tab:history|diagnostics>' => 'hub/entries/view',
		'hub/data/assets' => 'hub/files/index',
		'hub/data/assets/diagnostics' => 'hub/files/diagnostics',
		'hub/data/assets/<id:\d+>' => 'hub/files/view',
		'hub/data/assets/<id:\d+>/<tab:transforms|diagnostics>' => 'hub/files/view',
		'hub/data/users' => 'hub/users/index',
		'hub/data/users/diagnostics' => 'hub/users/diagnostics',
		'hub/data/users/groups' => 'hub/users/groups',
		'hub/data/users/summary' => 'hub/users/summary',
		'hub/data/users/<id:\d+>' => 'hub/users/view',
		'hub/data/users/<id:\d+>/<tab:entries|assets|diagnostics>' => 'hub/users/view',
		'hub/structure' => 'hub/nav/structure',
		'hub/structure/entry-types/<id:\d+>' => 'hub/types/view',
		'hub/structure/sections/diagnostics' => 'hub/sections/diagnostics',
		'hub/structure/sections/<id:\d+>' => 'hub/sections/view',
		'hub/structure/sections/<id:\d+>/<tab:entries|diagnostics>' => 'hub/sections/view',
		'hub/structure/fields/<id:\d+>' => 'hub/fields/view',
		'hub/structure/volumes/<id:\d+>' => 'hub/volumes/view',
		'hub/structure/volumes/<id:\d+>/<tab:assets|diagnostics>' => 'hub/volumes/view',
	])->and($event->rules)->toHaveCount(38);

	foreach (array_unique($event->rules) as $route) {
		[, $controller, $action] = explode('/', $route);
		$class = 'SomehowDigital\\Craft\\Hub\\controllers\\' . ucfirst($controller) . 'Controller';

		expect(method_exists($class, 'action' . ucfirst($action)))->toBeTrue("$route has no action");
	}
});

it('adds the structure navigation item right after the data one', function () {
	$event = new RegisterCpNavItemsEvent(['navItems' => [
		['label' => 'Entries', 'url' => 'entries'],
		['label' => 'Data', 'url' => 'hub/data'],
		['label' => 'Settings', 'url' => 'settings'],
	]]);
	(new RegisterCpNavItemsHandler())($event);

	expect(array_column($event->navItems, 'url'))->toBe(['entries', 'hub/data', 'hub/structure', 'settings']);
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

it('adds the elements referencing an entry to its sidebar', function () {
	$entry = new Entry(['id' => 5, 'siteId' => 1]);
	$referencing = [new EntryReference(new Entry(['id' => 6]))];

	$references = $this->mockComponent('hub.references', ReferenceService::class);
	$references->shouldReceive('getReferences')->once()->with($entry)->andReturn($referencing);

	$view = $this->mockComponent('view', View::class);
	$view->shouldReceive('registerAssetBundle')->once()->with(SidebarAsset::class);
	$view->shouldReceive('renderTemplate')->once()->with('hub/_sidebar/references', ['references' => $referencing])->andReturn('<references>');

	$event = sidebarEvent($entry);
	(new DefineEntrySidebarHtmlHandler())($event);

	expect($event->html)->toBe('<existing><references>');
});

it('leaves the sidebar of other elements, new entries and unpublished drafts alone', function (Closure $sender) {
	$this->mockComponent('hub.references', ReferenceService::class)->shouldNotReceive('getReferences');

	$event = sidebarEvent($sender());
	(new DefineEntrySidebarHtmlHandler())($event);

	expect($event->html)->toBe('<existing>');
})->with([
	'asset' => fn () => new Asset(['id' => 5]),
	'new entry' => fn () => new Entry(),
	'unpublished draft' => function () {
		$draft = Mockery::mock(Entry::class)->makePartial();
		$draft->id = 5;
		$draft->shouldReceive('getIsUnpublishedDraft')->andReturnTrue();

		return $draft;
	},
]);

it('leaves the sidebar alone for users without access', function () {
	$this->actingAs(permissions: ['accessCp']);
	$this->mockComponent('hub.references', ReferenceService::class)->shouldNotReceive('getReferences');

	$event = sidebarEvent(new Entry(['id' => 5]));
	(new DefineEntrySidebarHtmlHandler())($event);

	expect($event->html)->toBe('<existing>')
		->and(Craft::$app->getUser()->checkPermission(Hub::PERMISSION_ACCESS))->toBeFalse();
});
