<?php

declare(strict_types=1);

use craft\elements\User;
use craft\web\Controller;
use LogicException;
use SomehowDigital\Craft\Hub\controllers\BaseController;
use SomehowDigital\Craft\Hub\controllers\EntriesController;
use SomehowDigital\Craft\Hub\controllers\FieldsController;
use SomehowDigital\Craft\Hub\controllers\FilesController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\controllers\TypesController;
use SomehowDigital\Craft\Hub\controllers\UsersController;
use SomehowDigital\Craft\Hub\controllers\VolumesController;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\Tests\TestCase;

beforeEach(function (): void {
	$this->createContentModel();
});

/**
 * Runs the index action of a list, returning the URL it redirects to, if it does.
 *
 * @param array<string,mixed> $query
 */
function openList(TestCase $test, string $class, array $query = [], bool $inPlace = false): ?string
{
	(fn() => $this->request($query))->call($test);
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

	if ($inPlace) {
		webRequest()->getHeaders()->set(BaseController::FILTERS_HEADER, '1');
	}

	if (!is_subclass_of($class, Controller::class)) {
		throw new LogicException('Not a controller.');
	}

	$controller = Craft::createObject($class, ['test', Hub::getInstance()]);
	$controller->runAction('index');

	$response = webResponse();

	return $response->getIsRedirection() ? $response->getHeaders()->get('Location') : null;
}

/**
 * Filters kept in the session, leaving out those kept for the bulk selection.
 *
 * @return array<string,mixed>
 */
function keptFilters(TestCase $test): array
{
	return array_filter((fn(): array => $this->session)->call($test), fn(string $key): bool => str_starts_with($key, 'hub:filters:'), ARRAY_FILTER_USE_KEY);
}

it('keeps the filters of each list in the session', function (string $class, string $path): void {
	expect(openList($this, $class, ['q' => 'news', 'sort' => 'name', 'hubSite' => 'en', 'site' => 'en', 'page' => '2']))->toBeNull()
		->and(keptFilters($this))->toBe(["hub:filters:{$path}" => ['q' => 'news', 'sort' => 'name']]);
})->with([
	[EntriesController::class, 'hub/data/entries'],
	[FilesController::class, 'hub/data/assets'],
	[SectionsController::class, 'hub/structure/sections'],
	[TypesController::class, 'hub/structure/entry-types'],
	[FieldsController::class, 'hub/structure/fields'],
	[VolumesController::class, 'hub/structure/volumes'],
]);

it('redirects to the kept filters when a list is opened without any', function (): void {
	$this->session['hub:filters:hub/structure/sections'] = ['type' => 'channel', 'urls' => 'yes'];

	// The CP adds its site to the URLs of the nav.
	$location = openList($this, SectionsController::class, ['hubSite' => 'en', 'site' => 'en']);

	expect($location)->toContain('hub/structure/sections&')
		->and($location)->toContain('hubSite=en')
		->and($location)->toContain('type=channel')
		->and($location)->toContain('urls=yes');
});

it('shows the unfiltered list when no filters are kept', function (): void {
	expect(openList($this, SectionsController::class))->toBeNull()
		->and(keptFilters($this))->toBe([]);
});

it('does not redirect to kept params that are not filters', function (): void {
	$this->session['hub:filters:hub/structure/sections'] = ['site' => 'en', 'hubSite' => 'en'];

	expect(openList($this, SectionsController::class, ['site' => 'en']))->toBeNull();
});

it('forgets the filters when they are cleared in place', function (): void {
	$this->session['hub:filters:hub/structure/sections'] = ['type' => 'channel'];

	expect(openList($this, SectionsController::class, ['hubSite' => 'en'], inPlace: true))->toBeNull()
		->and(keptFilters($this))->toBe([]);
});

it('replaces the kept filters by those of a link', function (): void {
	$this->session['hub:filters:hub/data/entries'] = ['status' => 'live'];

	expect(openList($this, EntriesController::class, ['stale' => '1']))->toBeNull()
		->and(keptFilters($this))->toBe(['hub:filters:hub/data/entries' => ['stale' => '1']]);
});

it('keeps the filters while reviewing the selection, which ignores them', function (): void {
	$this->session['hub:filters:hub/structure/sections'] = ['type' => 'channel'];

	expect(openList($this, SectionsController::class, ['selection' => '1']))->toBeNull()
		->and(keptFilters($this))->toBe(['hub:filters:hub/structure/sections' => ['type' => 'channel']]);
});

it('redirects each list to its kept filters', function (string $class, string $path): void {
	$this->session["hub:filters:{$path}"] = ['q' => 'news'];

	expect(openList($this, $class))->toContain("{$path}&q=news");
})->with([
	[EntriesController::class, 'hub/data/entries'],
	[FilesController::class, 'hub/data/assets'],
	[UsersController::class, 'hub/data/users'],
	[TypesController::class, 'hub/structure/entry-types'],
	[FieldsController::class, 'hub/structure/fields'],
	[VolumesController::class, 'hub/structure/volumes'],
]);

it('redirects the user groups to their kept filters', function (): void {
	$this->userGroup(1, 'editors');
	$this->session['hub:filters:hub/data/users/groups'] = ['q' => 'edit'];
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

	Craft::createObject(UsersController::class, ['test', $this->hub()])->runAction('groups');

	expect(webResponse()->getHeaders()->get('Location'))->toContain('hub/data/users/groups&q=edit');
});
