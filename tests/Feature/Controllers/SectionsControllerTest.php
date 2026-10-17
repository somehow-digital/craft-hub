<?php

use craft\elements\Entry;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->createContentModel();
});

it('lists the sections', function () {
	$response = $this->runAction(SectionsController::class, 'index');
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/sections/index')
		->and(array_keys($variables['sections']))->toBe([4, 1, 2, 3])
		->and($variables['total'])->toBe(4)
		->and($variables['pageInfo']->total)->toBe(4)
		->and($variables['pageInfo']->totalPages)->toBe(1)
		->and($variables['activities'])->toBe([4 => 'none', 1 => 'recent', 2 => 'recent', 3 => 'stale'])
		->and(array_keys($variables['diagnostics']))->toBe([4, 1, 2, 3])
		->and($variables['sort'])->toBeNull()
		->and($variables['dir'])->toBeNull()
		->and($variables['summary'])->toHaveCount(4)
		->and($variables['selectedSites'])->toBe([])
		->and($variables['siteIds'])->toBe([1, 2])
		->and($variables['hubSite'])->toBeNull();
});

it('filters and sorts the sections by the query params', function () {
	$variables = $this->runAction(SectionsController::class, 'index', query: [
		'q' => ' new ',
		'type' => 'channel,unknown',
		'urls' => 'yes',
		'entriesMin' => '1.7',
		'referencesMax' => '-1',
		'activity' => 'recent',
		'empty' => '0',
		'diagnosis' => 'passed,bogus',
		'sort' => 'entries',
		'dir' => 'asc',
	])->variables;

	expect($variables['filter']->search)->toBe('new')
		->and($variables['filter']->types)->toBe(['channel'])
		->and($variables['filter']->urls)->toBe('yes')
		->and($variables['filter']->entriesMin)->toBe(1)
		->and($variables['filter']->referencesMax)->toBeNull()
		->and($variables['filter']->activities)->toBe(['recent'])
		->and($variables['filter']->empty)->toBeFalse()
		->and($variables['filter']->diagnoses)->toBe(['passed'])
		->and($variables['sort'])->toBe('entries')
		->and($variables['dir'])->toBe('asc')
		->and($variables['sortHeaders']['entries']['direction'])->toBe('asc')
		->and($variables['sortHeaders']['entries']['url'])->not->toContain('sort=entries&dir')
		->and($variables['sortHeaders']['name']['url'])->not->toContain('sort=');
});

it('ignores unknown sort columns and directions', function () {
	$variables = $this->runAction(SectionsController::class, 'index', query: ['sort' => 'bogus', 'dir' => 'sideways'])->variables;

	expect($variables['sort'])->toBeNull()
		->and($variables['dir'])->toBeNull()
		->and($variables['sortHeaders']['name']['direction'])->toBe('asc')
		->and($variables['sortHeaders']['name']['url'])->toContain('dir=desc');
});

it('shows a section', function () {
	$response = $this->runAction(SectionsController::class, 'view', ['id' => 2], ['hubSite' => 'en']);
	$variables = $response->variables;
	[$entries, $entryTypes, $references] = $variables['summary'];

	expect($response->template)->toBe('hub/pages/sections/view')
		->and($variables['selectedTab'])->toBe('overview')
		->and($variables['info']->section->handle)->toBe('pages')
		->and($entries['total'])->toBe(2)
		->and(array_column($entryTypes['segments'], 'value', 'label'))->toBe(['Page' => 2, 'Article' => 0])
		->and($entryTypes['segments'][0]['url'])->toContain('section=pages')
		->and($entryTypes['segments'][0]['url'])->toContain('type=page')
		->and($entryTypes['note'])->toBe('One entry type has no entries here')
		->and($references['note'])->toBe('No Entries field and one Link field can relate to entries from this section')
		->and($variables['checks'])->not->toBeEmpty()
		->and($variables['hubSite'])->toBe('en')
		->and($variables['siteIds'])->toBe([1])
		->and($variables)->not->toHaveKeys(['entries', 'reviewList']);
});

it('notes no entry types without entries in a section', function () {
	expect($this->runAction(SectionsController::class, 'view', ['id' => 1])->variables['summary'][1])->not->toHaveKey('note');
});

it('lists the entries of a section on a tab of its own', function () {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1, 'title' => 'News', 'sectionId' => 1, 'typeId' => 10])]);

	$variables = $this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'entries'])->variables;

	expect($variables['selectedTab'])->toBe('entries')
		->and($variables['listFixed'])->toBe('section')
		->and($variables['listPath'])->toBe('hub/structure/sections/2/entries')
		->and($variables['entries'])->toHaveCount(1)
		->and($variables['checks'])->not->toBeEmpty()
		->and($variables)->not->toHaveKeys(['summary', 'reviewList']);
});

it('shows the review lists of a section on its diagnostics tab', function () {
	$variables = $this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'diagnostics'], ['list' => 'stale', 'months' => '0'])->variables;

	expect($variables['selectedTab'])->toBe('diagnostics')
		->and($variables['reviewList'])->toBe('stale')
		->and($variables['reviewMonths'])->toBe(1)
		->and($variables['checks'])->not->toBeEmpty()
		->and($variables)->not->toHaveKeys(['summary', 'entries']);
});

it('shows the unreferenced entries of a section by default', function () {
	$variables = $this->runAction(SectionsController::class, 'view', ['id' => 1, 'tab' => 'diagnostics'])->variables;

	expect($variables['reviewList'])->toBe('unreferenced')
		->and($variables['reviewMonths'])->toBe(12);
});

it('fails for tabs that sections do not have', function () {
	$this->runAction(SectionsController::class, 'view', ['id' => 1, 'tab' => 'assets']);
})->throws(NotFoundHttpException::class);

it('fails for sections that do not exist', function () {
	$this->runAction(SectionsController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);

it('requires access to Hub', function () {
	$this->actingAs(permissions: []);

	$this->runAction(SectionsController::class, 'index');
})->throws(ForbiddenHttpException::class);

it('requires a CP request', function () {
	$controller = new SectionsController('sections', $this->hub());
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new craft\elements\User(['id' => 1]));
	$this->request(cp: false);

	$controller->runAction('index');
})->throws(BadRequestHttpException::class);

it('keeps the selected sites', function () {
	$this->site(2, 'de');

	expect($this->runAction(SectionsController::class, 'index', query: [Hub::SITE_PARAM => 'de'])->variables['summary'][0]['url'])->toContain('hubSite=de');
});

it('sorts against the natural direction', function () {
	$variables = $this->runAction(SectionsController::class, 'index', query: ['dir' => 'desc'])->variables;

	expect(array_keys($variables['sections']))->toBe([3, 2, 1, 4])
		->and($variables['sort'])->toBe('name')
		->and($variables['dir'])->toBe('desc');
});

it('runs no action that a handler cancels', function () {
	yii\base\Event::on(SectionsController::class, yii\base\Controller::EVENT_BEFORE_ACTION, fn (yii\base\ActionEvent $event) => $event->isValid = false);
	Craft::$app->getResponse()->detachBehavior(craft\web\TemplateResponseBehavior::NAME);

	$controller = new SectionsController('sections', $this->hub());
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new craft\elements\User(['id' => 1]));
	$this->request();

	expect($controller->runAction('index'))->toBeNull()
		->and(Craft::$app->getResponse()->getBehavior(craft\web\TemplateResponseBehavior::NAME))->toBeNull();
});

it('sorts by a column in its natural direction, then reversed, then resets the sorting', function () {
	$headers = fn (array $query) => $this->runAction(SectionsController::class, 'index', query: $query)->variables['sortHeaders'];

	expect($headers([])['entries']['url'])->toContain('sort=entries')->not->toContain('dir=')
		->and($headers(['sort' => 'entries'])['entries']['url'])->toContain('sort=entries&dir=asc')
		->and($headers(['sort' => 'entries', 'dir' => 'asc'])['entries']['url'])->toContain('sort=name')->not->toContain('dir=')
		->and($headers(['sort' => 'entries', 'dir' => 'asc', 'q' => 'news'])['entries']['url'])->toContain('q=news')->not->toContain('sort=');
});

it('reverses the default sort and back', function () {
	$headers = fn (array $query) => $this->runAction(SectionsController::class, 'index', query: $query)->variables['sortHeaders'];

	expect($headers([])['name']['url'])->toContain('dir=desc')
		->and($headers(['dir' => 'desc'])['name']['url'])->toContain('sort=name')->not->toContain('dir=')
		->and($headers(['dir' => 'desc', 'hubSite' => 'en'])['name']['url'])->toContain('sort=name')
		->and($headers(['dir' => 'desc', 'type' => 'channel'])['name']['url'])->not->toContain('sort=');
});

it('leaves out the data of hidden columns', function () {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_SECTIONS => ['order' => [], 'hidden' => ['updated', 'diagnostics']]]];
	$checks = Mockery::mock(CheckService::class)->makePartial();
	$checks->shouldNotReceive('forSection');
	$this->hub()->set('checks', $checks);

	$variables = $this->runAction(SectionsController::class, 'index')->variables;

	expect($variables['activities'])->toBe([])
		->and($variables['diagnostics'])->toBe([])
		->and($variables['sections'])->not->toBeEmpty();
});
