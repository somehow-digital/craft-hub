<?php

use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\View;
use SomehowDigital\Craft\Hub\assets\SidebarAsset;
use SomehowDigital\Craft\Hub\handlers\DefineEntrySidebarHtmlHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpRoutesHandler;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryReference;
use SomehowDigital\Craft\Hub\services\ReferenceService;

it('routes the Hub pages to their controllers', function () {
	$event = new RegisterUrlRulesEvent();
	(new RegisterCpRoutesHandler())($event);

	expect($event->rules)->toMatchArray([
		'hub' => 'hub/overview/index',
		'hub/entries' => 'hub/entries/index',
		'hub/entries/entries/<id:\d+>' => 'hub/entries/view',
		'hub/entries/sections/<id:\d+>' => 'hub/sections/view',
		'hub/entries/types/<id:\d+>' => 'hub/types/view',
		'hub/entries/fields/<id:\d+>' => 'hub/fields/view',
		'hub/assets' => 'hub/files/index',
		'hub/assets/files/<id:\d+>' => 'hub/files/view',
		'hub/assets/volumes/<id:\d+>' => 'hub/volumes/view',
	])->and($event->rules)->toHaveCount(15);

	foreach (array_unique($event->rules) as $route) {
		[, $controller, $action] = explode('/', $route);
		$class = 'SomehowDigital\\Craft\\Hub\\controllers\\' . ucfirst($controller) . 'Controller';

		expect(method_exists($class, 'action' . ucfirst($action)))->toBeTrue("$route has no action");
	}
});

function sidebarEvent(mixed $sender): DefineHtmlEvent
{
	$event = new DefineHtmlEvent(['html' => '<existing>']);
	$event->sender = $sender;

	return $event;
}

it('adds the elements relating to an entry to its sidebar', function () {
	$entry = new Entry(['id' => 5, 'siteId' => 1]);
	$relations = [new EntryReference(new Entry(['id' => 6]))];

	$references = $this->mockComponent('hub.references', ReferenceService::class);
	$references->shouldReceive('getRelations')->once()->with($entry)->andReturn($relations);

	$view = $this->mockComponent('view', View::class);
	$view->shouldReceive('registerAssetBundle')->once()->with(SidebarAsset::class);
	$view->shouldReceive('renderTemplate')->once()->with('hub/_sidebar/references', ['relations' => $relations])->andReturn('<references>');

	$event = sidebarEvent($entry);
	(new DefineEntrySidebarHtmlHandler())($event);

	expect($event->html)->toBe('<existing><references>');
});

it('leaves the sidebar of other elements, new entries and unpublished drafts alone', function (Closure $sender) {
	$this->mockComponent('hub.references', ReferenceService::class)->shouldNotReceive('getRelations');

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
	$this->mockComponent('hub.references', ReferenceService::class)->shouldNotReceive('getRelations');

	$event = sidebarEvent(new Entry(['id' => 5]));
	(new DefineEntrySidebarHtmlHandler())($event);

	expect($event->html)->toBe('<existing>')
		->and(Craft::$app->getUser()->checkPermission(Hub::PERMISSION_ACCESS))->toBeFalse();
});
