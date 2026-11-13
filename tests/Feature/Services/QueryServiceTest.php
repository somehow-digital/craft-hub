<?php

declare(strict_types=1);

use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use SomehowDigital\Craft\Hub\Tests\Support\FakeQueryService;

/** what keeps the rows of a query apart from what's done with them on PostgreSQL */
const QUERY_FENCE = 'OFFSET 0';

/** what ranks the sites of elements to page them */
const QUERY_RANK = 'ROW_NUMBER()';

/** what tells whether a query matches anything */
const QUERY_FIRST_ROW = 'LIMIT 1';

/**
 * Rows built like Craft's subquery: a row for each site of an element, joined and matched by site.
 *
 * @param ElementQuery<array-key,covariant ElementInterface> $query
 * @return Query<array-key,mixed>
 */
function joinedRows(ElementQuery $query): Query
{
	return (new Query())
		->from(['elements' => '{{%elements}}'])
		->innerJoin(['elements_sites' => '{{%elements_sites}}'], '[[elements_sites.elementId]] = [[elements.id]]')
		->where(['elements.archived' => false])
		->andWhere(['elements_sites.siteId' => $query->siteId])
		->andWhere($query->where ?? []);
}

beforeEach(function (): void {
	$this->site(2, 'de');
	$this->queries = FakeQueryService::make();
});

it('counts elements on several sites once, if they match on any of their sites', function (): void {
	$this->queries->count(Entry::find()->siteId([1, 2]), ['typeId' => 'entries.typeId'], ['live' => ['elements.enabled' => true]], [
		'diagnosis' => ['warning' => ['elements.archived' => true], 'passed' => null],
	]);

	$sql = $this->db()->counts()[0];

	expect($sql)->toContain('GROUP BY `hubId`, `typeId`')
		->and($sql)->toContain('MAX(`live`)')
		->and($sql)->toContain('BIT_OR(`diagnosis`)');
});

it('counts the rows of elements on a single site as they are', function (): void {
	$this->queries->count(Entry::find()->siteId([2]), ['typeId' => 'entries.typeId'], ['live' => ['elements.enabled' => true]], [
		'diagnosis' => ['warning' => ['elements.archived' => true], 'passed' => null],
	]);

	$sql = $this->db()->counts()[0];

	expect($sql)->not->toContain('GROUP BY `hubId`')
		->and($sql)->not->toContain('MAX(')
		->and($sql)->not->toContain('BIT_OR(')
		->and($sql)->toContain('SUM(`live`)');
});

it('groups the rows of elements by element before joining the tables of site-independent conditions, unless on a single site', function (): void {
	$count = fn(array|int $siteIds) => $this->queries->count(Entry::find()->siteId($siteIds), ['kind' => 'assets.kind'], ['used' => ['assets.size' => 1]], [], ['assets' => '{{%assets}}']);

	$count([1, 2]);
	$count(2);

	expect($this->db()->counts()[0])->toContain('GROUP BY `elements`.`id`')
		->and($this->db()->counts()[1])->not->toContain('GROUP BY `elements`.`id`');
});

it('left joins subqueries for site-independent conditions, as elements may have no row in them', function (): void {
	$this->queries->count(Entry::find()->siteId(2), ['kind' => 'assets.kind'], ['alt' => '[[hubAlt.withAlt]] > 0'], [], [
		'assets' => '{{%assets}}',
		'hubAlt' => (new Query())->select(['id' => 'assetId'])->from('{{%assets_sites}}'),
	]);

	expect($this->db()->counts()[0])->toContain('INNER JOIN `assets` `assets` ON `assets`.`id` = `elements`.`id`')
		->and($this->db()->counts()[0])->toContain('LEFT JOIN (SELECT `assetId` AS `id`')
		->and($this->db()->counts()[0])->toContain('`hubAlt` ON `hubAlt`.`id` = `elements`.`id`');
});

it('keeps the rows of elements on a single site apart from the counts on PostgreSQL, so conditions are evaluated once', function (string $driver, bool $fenced): void {
	$this->createApp($driver);
	$this->site(2, 'de');
	$queries = FakeQueryService::make();

	$queries->count(Entry::find()->siteId(2), ['typeId' => 'entries.typeId'], ['live' => ['elements.enabled' => true]]);
	$queries->count(Entry::find()->siteId([1, 2]), ['typeId' => 'entries.typeId'], ['live' => ['elements.enabled' => true]]);

	expect(str_contains($this->db()->counts()[0], QUERY_FENCE))->toBe($fenced)
		// Elements on several sites are grouped, which keeps them apart already.
		->and($this->db()->counts()[1])->not->toContain(QUERY_FENCE);
})->with([
	'MySQL' => ['mysql', false],
	'PostgreSQL' => ['pgsql', true],
]);

it('ranks the sites of elements on several sites to page them', function (): void {
	$this->queries->pageIds(Entry::find()->siteId([1, 2])->orderBy(['elements.dateUpdated' => SORT_DESC]), 50, 25);

	$sql = $this->db()->queriesMatching('hubRank')[0];

	expect($sql)->toContain(QUERY_RANK . ' OVER (PARTITION BY `elements`.`id`')
		->and($sql)->toContain('ORDER BY `hubSort0` DESC')
		->and($sql)->toContain('LIMIT 25 OFFSET 50');
});

it('skips pages past the elements a query matches', function (): void {
	expect($this->queries->pageIds(Entry::find()->siteId([2]), 50, 25, 50))->toBe([])
		->and($this->queries->pageIds(Entry::find()->siteId([1, 2]), 75, 25, 60))->toBe([])
		->and($this->db()->queriesMatching('hubId'))->toBe([]);
});

it('sorts the pages of elements on a single site holding the last of them once all are found', function (string $driver, int $total, ?string $fence): void {
	$this->createApp($driver);
	$this->site(2, 'de');
	FakeQueryService::make()->pageIds(Entry::find()->siteId([2])->orderBy(['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC]), 0, 25, $total);

	$sql = $this->db()->queriesMatching('hubId')[0];
	$quote = fn(string $sql): string => $driver === 'pgsql' ? str_replace('`', '"', $sql) : $sql;

	expect($sql)->toContain($quote($fence !== null ? 'ORDER BY `hubSort0` DESC, `hubSort1` DESC' : 'ORDER BY `elements`.`dateCreated` DESC, `elements`.`id` DESC'))
		->and($fence !== null ? str_contains($sql, $quote("{$fence}) `hubRows`")) : !str_contains($sql, 'hubRows'))->toBeTrue()
		->and($sql)->toEndWith('LIMIT 25');
})->with([
	'the last of them on MySQL' => ['mysql', 25, 'LIMIT ' . PHP_INT_MAX],
	'the last of them on PostgreSQL' => ['pgsql', 25, QUERY_FENCE],
	'more than the page' => ['mysql', 26, null],
]);

it('pages elements on a single site without ranking their sites', function (): void {
	$this->queries->pageIds(Entry::find()->siteId([2])->orderBy(['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]), 50, 25);

	$sql = $this->db()->queriesMatching('hubId')[0];

	expect($sql)->not->toContain(QUERY_RANK)
		->and($sql)->toContain('ORDER BY `elements`.`dateUpdated` DESC, `elements`.`id` DESC')
		->and($sql)->toContain('LIMIT 25 OFFSET 50');
});

describe('elements without criteria depending on their site', function (): void {
	beforeEach(function (): void {
		// Rows built like Craft's subquery: a row for each site of an element, joined and matched by site.
		$this->queries = FakeQueryService::make(joinedRows(...));

		// Not all sites, as the sites of elements on all of them aren't matched.
		$this->site(3, 'fr');
	});

	it('leaves out matching their sites on all sites, as every element is on one of them', function (): void {
		$this->queries->pageIds(Entry::find()->siteId([1, 2, 3])->orderBy(['elements.dateUpdated' => SORT_DESC]), 0, 25);
		$this->queries->count(Entry::find()->siteId([3, 2, 1]), ['kind' => 'assets.kind']);

		foreach ([$this->db()->queriesMatching('hubId')[0], $this->db()->counts()[0]] as $sql) {
			expect($sql)->not->toContain('hubSites')
				->and($sql)->not->toContain('elements_sites')
				->and($sql)->not->toContain(QUERY_RANK);
		}
	});

	it('tells whether any of them matches by the first row found', function (): void {
		$this->db()->respond(QUERY_FIRST_ROW, fn(string $sql): array => str_contains($sql, 'IN (1, 2)') ? [['hubId' => '5']] : []);
		$rows = joinedRows(...);

		// Rows like those of the other tests, of the service itself rather than the fake answering whether queries match.
		$queries = FakeQueryService::make($rows, answers: false);

		expect($queries->exists(Entry::find()->siteId([1, 2])))->toBeTrue()
			->and($queries->exists(Entry::find()->siteId([1, 3])))->toBeFalse()
			->and($this->db()->queriesMatching(QUERY_FIRST_ROW)[0])->toContain('EXISTS (SELECT *')
			->and($this->db()->queriesMatching(QUERY_FIRST_ROW)[0])->not->toContain('COUNT(');
	});

	it('pages them by a row of each, matching their sites with a subquery', function (): void {
		$this->queries->pageIds(Entry::find()->siteId([1, 2])->orderBy(['elements.dateUpdated' => SORT_DESC]), 50, 25);

		$sql = $this->db()->queriesMatching('hubId')[0];

		expect($sql)->not->toContain(QUERY_RANK)
			->and($sql)->not->toContain('JOIN `elements_sites`')
			->and($sql)->toContain('EXISTS (SELECT *')
			->and($sql)->toContain('`hubSites`.`siteId` IN (1, 2)')
			->and($sql)->toContain('ORDER BY `elements`.`dateUpdated` DESC')
			->and($sql)->toContain('LIMIT 25 OFFSET 50');
	});

	it('counts them by a row of each', function (): void {
		$this->queries->count(Entry::find()->siteId([1, 2]), ['kind' => 'assets.kind'], ['used' => ['assets.size' => 1]], [], ['assets' => '{{%assets}}']);

		$sql = $this->db()->counts()[0];

		expect($sql)->not->toContain('GROUP BY `elements`.`id`')
			->and($sql)->not->toContain('MAX(')
			->and($sql)->toContain('`hubSites`.`siteId` IN (1, 2)');
	});

	it('ranks their sites if they are sorted by a column of their site', function (): void {
		$this->queries->pageIds(Entry::find()->siteId([1, 2])->orderBy(['elements_sites.title' => SORT_ASC]), 0, 25);

		expect($this->db()->queriesMatching('hubRank'))->toHaveCount(1);
	});

	it('ranks their sites if the criteria refer to their site', function (): void {
		$this->queries->pageIds(Entry::find()->siteId([1, 2])->where(['like', 'elements_sites.title', 'a'])->orderBy(['elements.id' => SORT_ASC]), 0, 25);

		expect($this->db()->queriesMatching('hubRank'))->toHaveCount(1);
	});

	it('groups their rows if a condition counted refers to their site', function (): void {
		$this->queries->count(Entry::find()->siteId([1, 2]), [], ['enabled' => ['elements_sites.enabled' => true]]);

		expect($this->db()->counts()[0])->toContain('MAX(');
	});
});

describe('pages of elements', function (): void {
	beforeEach(function (): void {
		// Pages loaded like the service does, apart from the rows of the query, which aren't prepared.
		$this->queries = FakeQueryService::make(answers: false);
	});

	it('loads the elements of a page on the site each was found on, without the criteria of the query', function (): void {
		$this->db()->respond('hubRank', [['hubId' => '5', 'hubSiteId' => '2'], ['hubId' => '3', 'hubSiteId' => '1'], ['hubId' => '4', 'hubSiteId' => '2']]);
		ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => array_map(fn(int $id): Entry => new Entry(['id' => $id, 'siteId' => $query->siteId]), idsOf($query->id)));

		$page = $this->queries->page(Entry::find()->siteId([1, 2])->status('live')->orderBy(['elements.dateUpdated' => SORT_DESC]), 0, 25);
		$queries = ElementQueries::executed(Entry::class);

		expect(array_map(fn(Entry $entry): string => asInt($entry->id) . '@' . asInt($entry->siteId), objectsAt(Entry::class, ['page' => $page], 'page')))->toBe(['5@2', '3@1', '4@2'])
			->and(array_map(fn(ElementQuery $query): array => [$query->siteId, $query->id], $queries))->toBe([[2, [5, 4]], [1, [3]]])
			->and($queries[0]->status)->toBeNull();
	});

	it('loads the elements of a page on a single site on that site', function (): void {
		$this->db()->respond('hubId', [['hubId' => '7']]);
		ElementQueries::respond(Entry::class, fn(): array => [new Entry(['id' => 7, 'siteId' => 2])]);

		expect($this->queries->page(Entry::find()->siteId([2]), 0, 25))->toHaveCount(1)
			->and(ElementQueries::executed(Entry::class)[0]->siteId)->toBe(2)
			->and(ElementQueries::executed(Entry::class)[0]->unique)->toBeFalse();
	});

	it('loads the elements of a page of trashed ones as trashed', function (): void {
		$this->db()->respond('hubId', [['hubId' => '7']]);
		ElementQueries::respond(Entry::class, fn(): array => [new Entry(['id' => 7, 'siteId' => 2])]);

		$this->queries->page(Entry::find()->siteId([2])->trashed(), 0, 25);

		expect(ElementQueries::executed(Entry::class)[0]->trashed)->toBeTrue();
	});

	it("loads the elements of a page of drafts as drafts, as they aren't found otherwise", function (): void {
		$this->db()->respond('hubId', [['hubId' => '7']]);
		ElementQueries::respond(Entry::class, fn(): array => [new Entry(['id' => 7, 'siteId' => 2])]);

		$this->queries->page(Entry::find()->siteId([2])->drafts()->provisionalDrafts(null), 0, 25);

		expect(ElementQueries::executed(Entry::class)[0]->drafts)->toBeTrue()
			->and(ElementQueries::executed(Entry::class)[0]->provisionalDrafts)->toBeNull();
	});

	it('loads the elements of a page along with what the query loads with them', function (): void {
		$this->db()->respond('hubId', [['hubId' => '7', 'hubSiteId' => '2']]);
		ElementQueries::respond(Entry::class, fn(): array => [new Entry(['id' => 7, 'siteId' => 2])]);
		ElementQueries::respond(User::class, fn(): array => [new User(['id' => 7])]);

		$this->queries->page(Entry::find()->siteId([2])->with(['owner']), 0, 25);
		$this->queries->page(User::find()->with(['photo'])->withGroups(), 0, 25);

		expect(ElementQueries::executed(Entry::class)[0]->with)->toBe(['owner'])
			->and(ElementQueries::executed(User::class)[0]->with)->toBe(['photo'])
			->and(at(ElementQueries::executed(User::class)[0], 'withGroups'))->toBeTrue();
	});

	it('loads no elements for an empty page', function (): void {
		expect($this->queries->page(Entry::find()->siteId([1, 2]), 100, 25))->toBe([])
			->and(ElementQueries::executed(Entry::class))->toBe([]);
	});
});
