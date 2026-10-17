<?php

use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\EntriesController;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\services\CheckService;
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
		->and(array_keys($variables['diagnostics']))->toBe([1, 2])
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

it('flags stale entries in the list', function () {
	$checks = Mockery::mock(SomehowDigital\Craft\Hub\services\CheckService::class)->makePartial();
	$checks->shouldReceive('forEntries')->once()->withArgs(fn (array $entries, array $references, array $stale) => $stale === [1 => true, 2 => false])->andReturn([]);
	$this->hub()->set('checks', $checks);
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray ? [] : listedEntries(1, 2));

	$this->runAction(EntriesController::class, 'index');
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
		'diagnosis' => 'warning',
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
		->and($filter->diagnoses)->toBe(['warning'])
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

	[$entries, $sections, $types, $activity] = $this->runAction(EntriesController::class, 'index')->variables['summary'];

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

it('loads the owners, references and diagnostics of the entries with their columns', function () {
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray ? [] : listedEntries(1, 2));

	$variables = $this->runAction(EntriesController::class, 'index')->variables;

	expect(listingQuery()->with)->toBe(['owner'])
		->and(array_keys($variables['diagnostics']))->toBe([1, 2]);
});

it('leaves out the data of hidden columns', function () {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_ENTRIES => ['order' => [], 'hidden' => ['owner', 'references', 'diagnostics']]]];
	$checks = Mockery::mock(CheckService::class)->makePartial();
	$checks->shouldNotReceive('forEntries');
	$this->hub()->set('checks', $checks);
	$references = Mockery::mock(ReferenceService::class)->makePartial();
	$references->shouldNotReceive('getReferenceCounts');
	$this->hub()->set('references', $references);
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray ? [] : listedEntries(1, 2));

	$variables = $this->runAction(EntriesController::class, 'index')->variables;

	expect(listingQuery()->with)->toBeEmpty()
		->and($variables['referenceCounts'])->toBe([])
		->and($variables['diagnostics'])->toBe([])
		->and($variables['entries'])->toHaveCount(2);
});

it('loads the references for the diagnostics, even with their column hidden', function () {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_ENTRIES => ['order' => [], 'hidden' => ['references']]]];
	$references = Mockery::mock(ReferenceService::class)->makePartial();
	$references->shouldReceive('getReferenceCounts')->once()->with([1, 2])->andReturn([1 => 3]);
	$this->hub()->set('references', $references);
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray ? [] : listedEntries(1, 2));

	$variables = $this->runAction(EntriesController::class, 'index')->variables;

	expect($variables['referenceCounts'])->toBe([1 => 3])
		->and(array_keys($variables['diagnostics']))->toBe([1, 2]);
});
