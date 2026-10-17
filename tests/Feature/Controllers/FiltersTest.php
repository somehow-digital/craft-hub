<?php

use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\BaseController;
use SomehowDigital\Craft\Hub\controllers\EntriesController;
use SomehowDigital\Craft\Hub\controllers\FieldsController;
use SomehowDigital\Craft\Hub\controllers\FilesController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\controllers\TypesController;
use SomehowDigital\Craft\Hub\controllers\VolumesController;

beforeEach(function () {
	$this->createContentModel();
});

/**
 * Runs the index action of a list, returning the URL it redirects to, if it does.
 *
 * @param array<string,mixed> $query
 */
function openList(object $test, string $class, array $query = [], bool $inPlace = false): ?string
{
	(fn () => $this->request($query))->call($test);
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

	if ($inPlace) {
		Craft::$app->getRequest()->getHeaders()->set(BaseController::FILTERS_HEADER, '1');
	}

	Craft::createObject($class, ['test', Craft::$app->getModule('hub')])->runAction('index');
	$response = Craft::$app->getResponse();

	return $response->getIsRedirection() ? $response->getHeaders()->get('Location') : null;
}

it('keeps the filters of each list in the session', function (string $class, string $path) {
	expect(openList($this, $class, ['q' => 'news', 'sort' => 'name', 'hubSite' => 'en', 'site' => 'en', 'page' => '2']))->toBeNull()
		->and($this->session)->toBe(["hub:filters:$path" => ['q' => 'news', 'sort' => 'name']]);
})->with([
	[EntriesController::class, 'hub/data/entries'],
	[FilesController::class, 'hub/data/assets'],
	[SectionsController::class, 'hub/structure/sections'],
	[TypesController::class, 'hub/structure/entry-types'],
	[FieldsController::class, 'hub/structure/fields'],
	[VolumesController::class, 'hub/structure/volumes'],
]);

it('redirects to the kept filters when a list is opened without any', function () {
	$this->session['hub:filters:hub/structure/sections'] = ['type' => 'channel', 'urls' => 'yes'];

	// The CP adds its site to the URLs of the nav.
	$location = openList($this, SectionsController::class, ['hubSite' => 'en', 'site' => 'en']);

	expect($location)->toContain('hub/structure/sections&')
		->and($location)->toContain('hubSite=en')
		->and($location)->toContain('type=channel')
		->and($location)->toContain('urls=yes');
});

it('shows the unfiltered list when no filters are kept', function () {
	expect(openList($this, SectionsController::class))->toBeNull()
		->and($this->session)->toBe([]);
});

it('does not redirect to kept params that are not filters', function () {
	$this->session['hub:filters:hub/structure/sections'] = ['site' => 'en', 'hubSite' => 'en'];

	expect(openList($this, SectionsController::class, ['site' => 'en']))->toBeNull();
});

it('forgets the filters when they are cleared in place', function () {
	$this->session['hub:filters:hub/structure/sections'] = ['type' => 'channel'];

	expect(openList($this, SectionsController::class, ['hubSite' => 'en'], inPlace: true))->toBeNull()
		->and($this->session)->toBe([]);
});

it('replaces the kept filters by those of a link', function () {
	$this->session['hub:filters:hub/data/entries'] = ['status' => 'live'];

	expect(openList($this, EntriesController::class, ['stale' => '1']))->toBeNull()
		->and($this->session)->toBe(['hub:filters:hub/data/entries' => ['stale' => '1']]);
});

it('paginates lists filtered in memory, keeping their keys', function (int $page, int $currentPage, array $keys) {
	$this->request();
	(fn () => $this->_pageNum = $page)->call(Craft::$app->getRequest());

	$controller = Craft::createObject(SectionsController::class, ['test', Craft::$app->getModule('hub')]);
	$items = array_combine(range(101, 220), range(1, 120));
	[$pageInfo, $results] = (fn () => $this->paginateItems($items))->call($controller);

	expect(array_keys($results))->toBe($keys)
		->and($pageInfo->currentPage)->toBe($currentPage)
		->and($pageInfo->totalPages)->toBe(3)
		->and($pageInfo->total)->toBe(120)
		->and($pageInfo->first)->toBe(($currentPage - 1) * 50 + 1)
		->and($pageInfo->last)->toBe(($currentPage - 1) * 50 + count($keys));
})->with([
	'first page' => [1, 1, range(101, 150)],
	'last page' => [3, 3, range(201, 220)],
	'beyond the last page' => [9, 3, range(201, 220)],
]);

it('paginates empty lists into a single page', function () {
	$this->request();

	$controller = Craft::createObject(SectionsController::class, ['test', Craft::$app->getModule('hub')]);
	[$pageInfo, $results] = (fn () => $this->paginateItems([]))->call($controller);

	expect($results)->toBe([])
		->and($pageInfo->currentPage)->toBe(1)
		->and($pageInfo->totalPages)->toBe(1)
		->and($pageInfo->total)->toBe(0);
});
