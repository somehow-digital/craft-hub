<?php

use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\EntriesController;
use SomehowDigital\Craft\Hub\services\ReviewService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->createContentModel();
	$this->countableQueries();
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
		->and(array_keys($variables['counts']['sections']))->toBe(['news', 'pages', 'archive', 'settings'])
		->and(array_keys($variables['counts']['types']))->toBe(['article', 'empty', 'page'])
		->and($variables['counts']['statuses'])->toBe(['live' => 2, 'pending' => 2, 'expired' => 2, 'disabled' => 2])
		->and(array_keys($variables['counts']['usage']))->toBe(['referenced', 'unreferenced'])
		->and(array_keys($variables['counts']['diagnoses']))->toBe(['error', 'warning', 'notice', 'passed'])
		->and($variables['listFixed'])->toBeNull()
		->and($variables['listPath'])->toBe('hub/entries/entries')
		->and($variables['sort'])->toBeNull();
});

it('flags stale entries in the list', function () {
	$checks = Mockery::mock(SomehowDigital\Craft\Hub\services\CheckService::class)->makePartial();
	$checks->shouldReceive('forEntries')->once()->withArgs(fn (array $entries, array $relations, array $stale) => $stale === [1 => true, 2 => false])->andReturn([]);
	$this->hub()->set('checks', $checks);
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray ? [] : listedEntries(1, 2));

	$this->runAction(EntriesController::class, 'index');
});

it('reads the filters of the entries list', function () {
	$variables = $this->runAction(EntriesController::class, 'index', query: [
		'q' => 'term',
		'section' => 'news,bogus',
		'type' => 'article',
		'status' => 'live,bogus',
		'author' => '3,x,-1',
		'usage' => 'unreferenced',
		'relationsMin' => '1',
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
		->and($filter->statuses)->toBe(['live'])
		->and($filter->authors)->toBe([3])
		->and($filter->usage)->toBe('unreferenced')
		->and($filter->relationsMin)->toBe(1)
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

it('ignores unknown usage values', function () {
	expect($this->runAction(EntriesController::class, 'index', query: ['usage' => 'any'])->variables['filter']->usage)->toBeNull();
});

it('summarizes the entries', function () {
	$review = Mockery::mock(ReviewService::class)->makePartial();
	$review->shouldReceive('countStale')->with(12)->andReturn(3);
	$this->hub()->set('review', $review);

	[$entries, $sections, $types, $activity] = $this->runAction(EntriesController::class, 'index')->variables['summary'];

	expect($entries['total'])->toBe(8)
		->and($entries['segments'][2]['url'])->toContain('status=disabled')
		->and(array_column($sections['segments'], 'value', 'label'))->toBe(['News' => 5, 'Pages' => 2, 'Settings' => 1, 'Archive' => 0])
		->and($sections['segments'][0]['url'])->toContain('section=news')
		->and(array_column($types['segments'], 'value', 'label'))->toBe(['Article' => 5, 'Page' => 3])
		->and($types['segments'][1]['url'])->toContain('type=page')
		->and(array_column($activity['segments'], 'value'))->toBe([5, 3])
		->and($activity['segments'][1]['url'])->toContain('stale=1');
});

it('paginates lists on detail pages by their own param', function () {
	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->asArray ? [] : listedEntries(...range(1, 60)));

	$variables = $this->runAction(SomehowDigital\Craft\Hub\controllers\SectionsController::class, 'view', ['id' => 1], ['listPage' => '2'])->variables;

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
		->and($variables['relations'])->toBe([])
		->and($variables['sites'])->toBe([$entry, $variant])
		->and($variables['stale'])->toBeTrue()
		->and($variables['staleMonths'])->toBe(12)
		->and($variables['checks'])->not->toBeEmpty();
});

it('fails for entries that do not exist', function () {
	$this->runAction(EntriesController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);
