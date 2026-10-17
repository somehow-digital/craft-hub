<?php

use craft\elements\Entry;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
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
		->and($variables['sort'])->toBeNull()
		->and($variables['dir'])->toBeNull()
		->and($variables)->not->toHaveKey('summary')
		->and($variables['selectedSites'])->toBe([])
		->and($variables['siteIds'])->toBe([1, 2])
		->and($variables['hubSite'])->toBeNull();
});

it('summarizes the sections on their statistics tab, loading the cards as they are opened', function () {
	$response = $this->runAction(SectionsController::class, 'statistics');

	expect($response->template)->toBe('hub/pages/statistics')
		->and(array_column($response->variables['cards'], 'key'))->toBe(['sections', 'types', 'entries', 'activity'])
		->and($response->variables['cards'][0])->not->toHaveKey('total');
});

it('loads a card of the statistics tab alone', function () {
	$response = $this->runAction(SectionsController::class, 'statistics', query: ['card' => 'types']);

	expect($response->template)->toBe('hub/_components/statistics-card')
		->and($response->variables['card']['key'])->toBe('types')
		->and($response->variables['card']['total'])->toBe(4);
});

it('has no card of the statistics tab by an unknown key', function () {
	$this->runAction(SectionsController::class, 'statistics', query: ['card' => 'unknown']);
})->throws(NotFoundHttpException::class);

it('loads a card of a section\'s statistics tab alone', function () {
	$response = $this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'statistics'], ['card' => 'entries']);

	expect($response->template)->toBe('hub/_components/statistics-card')
		->and($response->variables['card']['total'])->toBe(2)
		->and($response->variables)->not->toHaveKey('checks');
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
	$response = $this->runAction(SectionsController::class, 'view', ['id' => 2]);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/sections/view')
		->and($variables['selectedTab'])->toBe('overview')
		->and($variables['info']->section->handle)->toBe('pages')
		->and($variables['checks'])->not->toBeEmpty()
		->and($variables)->not->toHaveKeys(['summary', 'entries', 'reviewList']);
});

it('summarizes a section on its statistics tab', function () {
	$variables = $this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'statistics'], ['hubSite' => 'en'])->variables;
	[$entries, $entryTypes, $references, $authors] = loadCards($variables['summary']);

	expect($variables['selectedTab'])->toBe('statistics')
		->and($entries['total'])->toBe(2)
		->and($entries['segments'][0]['url'])->toContain('hub/structure/sections/2/entries')
		->and($entries['segments'][0]['url'])->toContain('status=live')
		->and($authors['label'])->toBe('Authors')
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
	expect(loadCards($this->runAction(SectionsController::class, 'view', ['id' => 1, 'tab' => 'statistics'])->variables['summary'])[1])->not->toHaveKey('note');
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

it('shows the checks of a section on its diagnostics tab', function () {
	$variables = $this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'diagnostics'])->variables;

	expect($variables['selectedTab'])->toBe('diagnostics')
		->and(array_map(fn ($check) => $check->key, $variables['checks']))->toContain('sections.empty', 'entries.drafts')
		->and($variables)->not->toHaveKeys(['summary', 'entries', 'reviewList']);
});

it('fails for the statistics tab while statistics are disabled', function () {
	$this->hub()->getSettings()->enableStatistics = false;

	try {
		$this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'statistics']);
	} finally {
		$this->hub()->getSettings()->enableStatistics = true;
	}
})->throws(NotFoundHttpException::class);

it('fails for tabs that sections do not have', function () {
	$this->runAction(SectionsController::class, 'view', ['id' => 1, 'tab' => 'assets']);
})->throws(NotFoundHttpException::class);

it('fails for sections that do not exist', function () {
	$this->runAction(SectionsController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);

it('requires access to Hub', function () {
	$this->actingAs(permissions: []);

	$this->runAction(SectionsController::class, 'index');
})->with([
	'without the structure' => [[Hub::PERMISSION_ACCESS_DATA, Page::Sections->viewPermission()]],
	'without the sections' => [[Hub::PERMISSION_ACCESS_STRUCTURE, Page::Fields->viewPermission()]],
])->throws(ForbiddenHttpException::class);

it('requires a CP request', function () {
	$controller = new SectionsController('sections', $this->hub());
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new craft\elements\User(['id' => 1]));
	$this->request(cp: false);

	$controller->runAction('index');
})->throws(BadRequestHttpException::class);

it('keeps the selected sites', function () {
	$this->site(2, 'de');

	expect(loadCards($this->runAction(SectionsController::class, 'statistics', query: [Hub::SITE_PARAM => 'de'])->variables['cards'])[0]['url'])->toContain('hubSite=de');
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
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_SECTIONS => ['order' => [], 'hidden' => ['updated']]]];

	$variables = $this->runAction(SectionsController::class, 'index')->variables;

	expect($variables['activities'])->toBe([])
		->and($variables['sections'])->not->toBeEmpty();
});

it('lists only the selected sections while reviewing them, whatever the filters', function () {
	$this->session['hub:bulk:selection:sections:hub/structure/sections'] = [1, 3, 99];

	$variables = $this->runAction(SectionsController::class, 'index', query: ['selection' => '1', 'type' => 'structure'])->variables;

	// Selected sections that no longer exist are unselected.
	expect(array_keys($variables['sections']))->toBe([1, 3])
		->and($variables['filter']->types)->toBe([])
		->and($variables['bulkSelection']['review'])->toBeTrue()
		->and($variables['bulkSelection']['ids'])->toBe([1, 3])
		->and($variables['bulkSelection']['outside'])->toBe(0)
		->and($this->session['hub:bulk:selection:sections:hub/structure/sections'])->toBe([1, 3])
		->and($this->session)->not->toHaveKey('hub:bulk:filter:sections:hub/structure/sections');
});

it('counts the selected sections the filters leave out, keeping the filters to select all matching ones', function () {
	$this->session['hub:bulk:selection:sections:hub/structure/sections'] = [1, 2];

	$variables = $this->runAction(SectionsController::class, 'index', query: ['type' => 'channel'])->variables;

	expect($variables['bulkSelection']['review'])->toBeFalse()
		->and($variables['bulkSelection']['ids'])->toBe([1, 2])
		->and($variables['bulkSelection']['outside'])->toBe(1)
		->and($variables['bulkSelection']['limit'])->toBe(1000)
		->and($variables['bulkSelection']['listUrl'])->toContain('hub/structure/sections')
		->and($variables['bulkSelection']['reviewUrl'])->toContain('selection=1')
		->and($this->session['hub:bulk:filter:sections:hub/structure/sections']->types)->toBe(['channel']);
});

it('offers no selection to users who may not label sections', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_STRUCTURE, Page::Sections->viewPermission(), Page::Sections->bulkEditPermission()]);

	$variables = $this->runAction(SectionsController::class, 'index', query: ['selection' => '1'])->variables;

	expect($variables['bulkActions'])->toBe([])
		->and($variables['bulkSelection'])->toBeNull()
		->and(array_keys($variables['sections']))->toBe([4, 1, 2, 3]);
});

it('requires the permissions to access the structure and view the sections', function (array $permissions) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', ...$permissions]);

	$this->runAction(SectionsController::class, 'index');
})->with([
	'without the structure' => [[Hub::PERMISSION_ACCESS_DATA, Page::Sections->viewPermission()]],
	'without the sections' => [[Hub::PERMISSION_ACCESS_STRUCTURE, Page::Fields->viewPermission()]],
])->throws(ForbiddenHttpException::class);

it('requires the permission to view the entries for the entries tab', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_STRUCTURE, Page::Sections->viewPermission()]);

	$this->runAction(SectionsController::class, 'view', ['id' => 1, 'tab' => 'entries']);
})->throws(ForbiddenHttpException::class);
