<?php

use craft\elements\Entry;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->createContentModel();
	$this->countableQueries();
});

it('lists the sections', function () {
	$response = $this->runAction(SectionsController::class, 'index');
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/sections/index')
		->and(array_keys($variables['sections']))->toBe([4, 1, 2, 3])
		->and($variables['total'])->toBe(4)
		->and($variables['activities'])->toBe([4 => 'none', 1 => 'recent', 2 => 'recent', 3 => 'stale'])
		->and(array_keys($variables['diagnostics']))->toBe([4, 1, 2, 3])
		->and($variables['counts']['types'])->toBe(['single' => 1, 'channel' => 2, 'structure' => 1])
		->and($variables['counts']['urls'])->toBe(['yes' => 2, 'no' => 2])
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
		'relationsMax' => '-1',
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
		->and($variables['filter']->relationsMax)->toBeNull()
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
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1, 'title' => 'News', 'sectionId' => 1, 'typeId' => 10])]);

	$response = $this->runAction(SectionsController::class, 'view', ['id' => 2], ['hubSite' => 'en', 'list' => 'stale', 'months' => '0']);
	$variables = $response->variables;
	[$entries, $entryTypes, $references] = $variables['summary'];

	expect($response->template)->toBe('hub/pages/sections/view')
		->and($variables['info']->section->handle)->toBe('pages')
		->and($entries['total'])->toBe(2)
		->and(array_column($entryTypes['segments'], 'value', 'label'))->toBe(['Page' => 2, 'Article' => 0])
		->and($entryTypes['segments'][0]['url'])->toContain('section=pages')
		->and($entryTypes['segments'][0]['url'])->toContain('type=page')
		->and($entryTypes['note'])->toBe('One entry type has no entries here')
		->and($references['note'])->toBe('No Entries field and one Link field can relate to entries from this section')
		->and($variables['listFixed'])->toBe('section')
		->and($variables['listPath'])->toBe('hub/entries/sections/2')
		->and($variables['entries'])->toHaveCount(1)
		->and($variables['pageInfo']->pageTrigger)->toBe('?listPage=')
		->and($variables['reviewList'])->toBe('stale')
		->and($variables['reviewMonths'])->toBe(1)
		->and($variables['hubSite'])->toBe('en')
		->and($variables['siteIds'])->toBe([1]);
});

it('shows the unreferenced entries of a section by default', function () {
	$variables = $this->runAction(SectionsController::class, 'view', ['id' => 1])->variables;

	expect($variables['reviewList'])->toBe('unreferenced')
		->and($variables['reviewMonths'])->toBe(12)
		->and($variables['summary'][1])->not->toHaveKey('note');
});

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
