<?php

use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\services\Tokens;
use SomehowDigital\Craft\Hub\controllers\EntriesController;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use SomehowDigital\Craft\Hub\services\ReviewService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->createContentModel();
});

/**
 * Entries with the given IDs, one stale.
 *
 * @return Entry[]
 */
function listedEntries(int ...$ids): array
{
	return array_map(fn (int $id) => new Entry([
		'id' => $id,
		'siteId' => 1,
		'sectionId' => 1,
		'typeId' => 10,
		'dateUpdated' => new DateTime($id === 1 ? '-2 years' : '-1 day'),
	]), $ids);
}

it('lists the entries', function () {
	$entries = listedEntries(1, 2);
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray ? [] : $entries);
	ElementQueries::respond(User::class, []);

	$response = $this->runAction(EntriesController::class, 'index');
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/entries/index')
		->and($variables['entries'])->toBe($entries)
		->and($variables['pageInfo']->total)->toBe(2)
		->and(array_keys($variables['statuses']))->toBe(['live', 'pending', 'expired', 'disabled'])
		->and($variables['staleMonths'])->toBe(12)
		->and(array_map(fn (SectionInfo $info) => $info->section->handle, array_values($variables['sections'])))->toBe(['archive', 'news', 'pages', 'settings'])
		->and(array_map(fn (EntryTypeInfo $info) => $info->entryType->handle, array_values($variables['entryTypes'])))->toBe(['article', 'block', 'empty', 'page'])
		->and(array_keys($variables['nestedFields']))->toBe([2])
		->and(array_column($variables['columns'], 'key'))->toContain('section', 'field', 'owner')
		->and($variables['listFixed'])->toBeNull()
		->and($variables['listPath'])->toBe('hub/data/entries')
		->and($variables['sort'])->toBeNull();
});

it('reads the filters of the entries list', function () {
	$variables = $this->runAction(EntriesController::class, 'index', query: [
		'q' => 'term',
		'section' => 'news,bogus',
		'type' => 'article',
		'origin' => 'nested',
		'field' => 'blocks,legacy,bogus',
		'status' => 'live,bogus',
		'author' => '3,x,-1',
		'usage' => 'unreferenced',
		'referencesMin' => '1',
		'postedFrom' => '2024-01-01',
		'postedTo' => '2024-13-45x',
		'updatedTo' => '2024-02-01',
		'stale' => '1',
		'drafts' => '1',
		'sort' => 'title',
	])->variables;
	$filter = $variables['filter'];

	expect($filter->search)->toBe('term')
		->and($filter->sections)->toBe(['news'])
		->and($filter->types)->toBe(['article'])
		->and($filter->origin)->toBe('nested')
		// Only fields holding nested entries can be filtered by.
		->and($filter->fields)->toBe(['blocks'])
		->and($filter->statuses)->toBe(['live'])
		->and($filter->authors)->toBe([3])
		->and($filter->usage)->toBe('unreferenced')
		->and($filter->referencesMin)->toBe(1)
		->and($filter->postedFrom->format('Y-m-d'))->toBe('2024-01-01')
		->and($filter->postedTo)->toBeNull()
		->and($filter->updatedTo->format('Y-m-d'))->toBe('2024-02-01')
		->and($filter->staleMonths)->toBe(12)
		->and($filter->drafts)->toBeTrue()
		->and($variables['sort'])->toBe('title')
		->and($variables['sortHeaders']['title']['url'])->toContain('section=news')
		->and($variables['sortHeaders']['title']['url'])->toContain('dir=desc');
});

it('ignores unknown usage values and origins', function () {
	$filter = $this->runAction(EntriesController::class, 'index', query: ['usage' => 'any', 'origin' => 'any'])->variables['filter'];

	expect($filter->usage)->toBeNull()
		->and($filter->origin)->toBeNull();
});

it('summarizes the entries', function () {
	$review = Mockery::mock(ReviewService::class)->makePartial();
	$review->shouldReceive('countStale')->with(12)->andReturn(3);
	$this->hub()->set('review', $review);

	[$entries, $sections, $types, $activity] = $this->runAction(EntriesController::class, 'summary')->variables['cards'];

	// Like the list, the summary counts nested entries too.
	expect($entries['total'])->toBe(15)
		->and($entries['segments'][2]['url'])->toContain('status=disabled')
		->and(array_column($sections['segments'], 'value', 'label'))->toBe(['News' => 5, 'Pages' => 2, 'Settings' => 1, 'Archive' => 0])
		->and($sections['segments'][0]['url'])->toContain('section=news')
		->and(array_column($types['segments'], 'value', 'label'))->toBe(['Block' => 7, 'Article' => 5, 'Page' => 3])
		->and($types['segments'][2]['url'])->toContain('type=page')
		->and(array_column($activity['segments'], 'value'))->toBe([12, 3])
		->and($activity['segments'][1]['url'])->toContain('stale=1');
});

it('paginates lists on detail pages by the page of the URL', function () {
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray ? [] : listedEntries(...range(1, 60)));
	$this->request();
	(fn () => $this->_pageNum = 2)->call(Craft::$app->getRequest());

	$controller = Craft::createObject(SomehowDigital\Craft\Hub\controllers\SectionsController::class, ['test', $this->hub()]);
	$variables = (fn () => $this->entryListVariables('hub/structure/sections/1/entries'))->call($controller);

	expect($variables['pageInfo']->currentPage)->toBe(2)
		->and($variables['pageInfo']->totalPages)->toBe(2)
		->and($variables['pageInfo']->total)->toBe(60);
});

it('shows an entry', function () {
	$entry = listedEntries(1)[0];
	$variant = new Entry(['id' => 1, 'siteId' => 2]);
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => match (true) {
		$query->asArray => [],
		$query->unique => [$entry],
		default => [$entry, $variant],
	});

	$response = $this->runAction(EntriesController::class, 'view', ['id' => 1]);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/entries/view')
		->and($variables['entry'])->toBe($entry)
		->and($variables['references'])->toBe([])
		->and($variables['sites'])->toBe([$entry, $variant])
		->and($variables['stale'])->toBeTrue()
		->and($variables['checks'])->not->toBeEmpty();
});

it('shows the history of an entry', function () {
	$entry = listedEntries(1)[0];
	$revisions = [new Entry(['id' => 3, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1])];
	$draft = new Entry(['id' => 4, 'siteId' => 1]);
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => match (true) {
		$query->asArray => [],
		$query->revisions => $revisions,
		$query->drafts === true => [$draft],
		default => [$entry],
	});

	$variables = $this->runAction(EntriesController::class, 'view', ['id' => 1, 'tab' => 'history'])->variables;

	expect($variables['selectedTab'])->toBe('history')
		->and($variables['hasHistory'])->toBeTrue()
		->and($variables['revisions'])->toBe($revisions)
		->and($variables['drafts'])->toBe([$draft])
		->and($variables['pageInfo']->total)->toBe(2);
});

it('loads the history only on its tab', function () {
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray || $query->revisions ? [] : listedEntries(1));

	$variables = $this->runAction(EntriesController::class, 'view', ['id' => 1])->variables;

	expect($variables)->not->toHaveKeys(['revisions', 'drafts'])
		->and(array_filter(ElementQueries::executed(Entry::class), fn (EntryQuery $query) => $query->revisions))->toBeEmpty();
});

it('has no history tab for entries without revisions', function () {
	// Nested entries of fields without versioning have no revisions.
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray ? [] : [new Entry(['id' => 1, 'siteId' => 1, 'fieldId' => 2, 'typeId' => 12])]);

	$this->runAction(EntriesController::class, 'view', ['id' => 1, 'tab' => 'history']);
})->throws(NotFoundHttpException::class);

/**
 * Opens revision 3 of entry 1 in the news section with the given preview targets, returning the URL it redirects to.
 *
 * @param array<int,array<string,string>> $previewTargets
 */
function openRevision(object $test, array $previewTargets): ?string
{
	(fn () => $this->sections[1]->previewTargets = $previewTargets)->call($test);
	$entry = new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'uri' => 'news/article']);
	$revision = new Entry(['id' => 3, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'uri' => 'news/article', 'canonicalId' => 1, 'revisionId' => 7]);
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->revisions ? [$revision] : [$entry]);
	$tokens = Mockery::mock(Tokens::class);
	$tokens->shouldReceive('createPreviewToken')->once()->with(['preview/preview', [
		'elementType' => Entry::class,
		'canonicalId' => 1,
		'siteId' => 1,
		'revisionId' => 7,
		'userId' => 1,
	]])->andReturn('abc');
	Craft::$app->set('tokens', $tokens);

	(fn () => $this->request())->call($test);
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));
	Craft::createObject(EntriesController::class, ['test', Craft::$app->getModule('hub')])->runAction('view-revision', ['id' => 1, 'revisionId' => 3]);

	return Craft::$app->getResponse()->getHeaders()->get('Location');
}

it('opens a revision on its first preview target with a preview token', function () {
	$location = openRevision($this, [['label' => 'Preview', 'url' => 'preview/article'], ['label' => 'Other', 'url' => 'other']]);
	$revisionQuery = array_values(array_filter(ElementQueries::executed(Entry::class), fn (EntryQuery $query) => $query->revisions))[0];

	expect($location)->toContain('preview/article')
		->and($location)->toContain('token=abc')
		->and($revisionQuery->id)->toBe(3);
});

it('opens a revision on its URL without preview targets', function () {
	expect(openRevision($this, []))->toContain('news/article')->toContain('token=abc');
});

it('fails for revisions not of the entry', function () {
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->revisions ? [] : listedEntries(1));

	$this->runAction(EntriesController::class, 'view-revision', ['id' => 1, 'revisionId' => 99]);
})->throws(NotFoundHttpException::class);

it('fails for entries that do not exist', function () {
	$this->runAction(EntriesController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);

/**
 * The query listing the entries, rather than counting them.
 */
function listingQuery(): EntryQuery
{
	return array_values(array_filter(ElementQueries::$executed, fn ($query) => $query instanceof EntryQuery && !$query->asArray))[0];
}

it('loads the owners and authors of the entries with their columns', function () {
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray ? [] : listedEntries(1, 2));

	$this->runAction(EntriesController::class, 'index');

	expect(listingQuery()->with)->toBe(['owner', 'authors']);
});

it('leaves out the data of hidden columns', function () {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_ENTRIES => ['order' => [], 'hidden' => ['owner', 'creator', 'references']]]];
	$references = Mockery::mock(ReferenceService::class)->makePartial();
	$references->shouldNotReceive('getReferenceCounts');
	$this->hub()->set('references', $references);
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray ? [] : listedEntries(1, 2));

	$variables = $this->runAction(EntriesController::class, 'index')->variables;

	expect(listingQuery()->with)->toBeEmpty()
		->and($variables['referenceCounts'])->toBe([])
		->and($variables['entries'])->toHaveCount(2);
});
