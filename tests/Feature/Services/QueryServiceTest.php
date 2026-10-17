<?php

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\services\QueryService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use SomehowDigital\Craft\Hub\Tests\Support\FakeQueryService;

beforeEach(function () {
	$this->site(2, 'de');
	$this->queries = new FakeQueryService();
});

it('counts elements on several sites once, if they match on any of their sites', function () {
	$this->queries->count(Entry::find()->siteId([1, 2]), ['typeId' => 'entries.typeId'], ['live' => ['elements.enabled' => true]], [
		'diagnosis' => ['warning' => ['elements.archived' => true], 'passed' => null],
	]);

	$sql = $this->db()->counts()[0];

	expect($sql)->toContain('GROUP BY `hubId`, `typeId`')
		->and($sql)->toContain('MAX(`live`)')
		->and($sql)->toContain('BIT_OR(`diagnosis`)');
});

it('counts the rows of elements on a single site as they are', function () {
	$this->queries->count(Entry::find()->siteId([2]), ['typeId' => 'entries.typeId'], ['live' => ['elements.enabled' => true]], [
		'diagnosis' => ['warning' => ['elements.archived' => true], 'passed' => null],
	]);

	$sql = $this->db()->counts()[0];

	expect($sql)->not->toContain('GROUP BY `hubId`')
		->and($sql)->not->toContain('MAX(')
		->and($sql)->not->toContain('BIT_OR(')
		->and($sql)->toContain('SUM(`live`)');
});

it('groups the rows of elements by element before joining the tables of site-independent conditions, unless on a single site', function () {
	$count = fn (array|int $siteIds) => $this->queries->count(Entry::find()->siteId($siteIds), ['kind' => 'assets.kind'], ['used' => ['assets.size' => 1]], [], ['assets' => '{{%assets}}']);

	$count([1, 2]);
	$count(2);

	expect($this->db()->counts()[0])->toContain('GROUP BY `elements`.`id`')
		->and($this->db()->counts()[1])->not->toContain('GROUP BY `elements`.`id`');
});

it('left joins subqueries for site-independent conditions, as elements may have no row in them', function () {
	$this->queries->count(Entry::find()->siteId(2), ['kind' => 'assets.kind'], ['alt' => '[[hubAlt.withAlt]] > 0'], [], [
		'assets' => '{{%assets}}',
		'hubAlt' => (new Query())->select(['id' => 'assetId'])->from('{{%assets_sites}}'),
	]);

	expect($this->db()->counts()[0])->toContain('INNER JOIN `assets` `assets` ON `assets`.`id` = `elements`.`id`')
		->and($this->db()->counts()[0])->toContain('LEFT JOIN (SELECT `assetId` AS `id`')
		->and($this->db()->counts()[0])->toContain('`hubAlt` ON `hubAlt`.`id` = `elements`.`id`');
});

it('keeps the rows of elements on a single site apart from the counts on PostgreSQL, so conditions are evaluated once', function (string $driver, bool $fenced) {
	$this->createApp($driver);
	$this->site(2, 'de');
	$queries = new FakeQueryService();

	$queries->count(Entry::find()->siteId(2), ['typeId' => 'entries.typeId'], ['live' => ['elements.enabled' => true]]);
	$queries->count(Entry::find()->siteId([1, 2]), ['typeId' => 'entries.typeId'], ['live' => ['elements.enabled' => true]]);

	expect(str_contains($this->db()->counts()[0], 'OFFSET 0'))->toBe($fenced)
		// Elements on several sites are grouped, which keeps them apart already.
		->and($this->db()->counts()[1])->not->toContain('OFFSET 0');
})->with([
	'MySQL' => ['mysql', false],
	'PostgreSQL' => ['pgsql', true],
]);

it('ranks the sites of elements on several sites to page them', function () {
	$this->queries->pageIds(Entry::find()->siteId([1, 2])->orderBy(['elements.dateUpdated' => SORT_DESC]), 50, 25);

	$sql = $this->db()->queriesMatching('hubRank')[0];

	expect($sql)->toContain('ROW_NUMBER() OVER (PARTITION BY `elements`.`id`')
		->and($sql)->toContain('ORDER BY `hubSort0` DESC')
		->and($sql)->toContain('LIMIT 25 OFFSET 50');
});

it('pages elements on a single site without ranking their sites', function () {
	$this->queries->pageIds(Entry::find()->siteId([2])->orderBy(['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC]), 50, 25);

	$sql = $this->db()->queriesMatching('hubId')[0];

	expect($sql)->not->toContain('ROW_NUMBER()')
		->and($sql)->toContain('ORDER BY `elements`.`dateUpdated` DESC, `elements`.`id` DESC')
		->and($sql)->toContain('LIMIT 25 OFFSET 50');
});

describe('elements without criteria depending on their site', function () {
	beforeEach(function () {
		// Rows built like Craft's subquery: a row for each site of an element, joined and matched by site.
		$this->queries = new class() extends FakeQueryService {
			protected function rows(ElementQuery $query): Query
			{
				return (new Query())
					->from(['elements' => '{{%elements}}'])
					->innerJoin(['elements_sites' => '{{%elements_sites}}'], '[[elements_sites.elementId]] = [[elements.id]]')
					->where(['elements.archived' => false])
					->andWhere(['elements_sites.siteId' => $query->siteId])
					->andWhere($query->where ?? []);
			}
		};
	});

	it('pages them by a row of each, matching their sites with a subquery', function () {
		$this->queries->pageIds(Entry::find()->siteId([1, 2])->orderBy(['elements.dateUpdated' => SORT_DESC]), 50, 25);

		$sql = $this->db()->queriesMatching('hubId')[0];

		expect($sql)->not->toContain('ROW_NUMBER()')
			->and($sql)->not->toContain('JOIN `elements_sites`')
			->and($sql)->toContain('EXISTS (SELECT *')
			->and($sql)->toContain('`hubSites`.`siteId` IN (1, 2)')
			->and($sql)->toContain('ORDER BY `elements`.`dateUpdated` DESC')
			->and($sql)->toContain('LIMIT 25 OFFSET 50');
	});

	it('counts them by a row of each', function () {
		$this->queries->count(Entry::find()->siteId([1, 2]), ['kind' => 'assets.kind'], ['used' => ['assets.size' => 1]], [], ['assets' => '{{%assets}}']);

		$sql = $this->db()->counts()[0];

		expect($sql)->not->toContain('GROUP BY `elements`.`id`')
			->and($sql)->not->toContain('MAX(')
			->and($sql)->toContain('`hubSites`.`siteId` IN (1, 2)');
	});

	it('ranks their sites if they are sorted by a column of their site', function () {
		$this->queries->pageIds(Entry::find()->siteId([1, 2])->orderBy(['elements_sites.title' => SORT_ASC]), 0, 25);

		expect($this->db()->queriesMatching('hubRank'))->toHaveCount(1);
	});

	it('ranks their sites if the criteria refer to their site', function () {
		$this->queries->pageIds(Entry::find()->siteId([1, 2])->where(['like', 'elements_sites.title', 'a'])->orderBy(['elements.id' => SORT_ASC]), 0, 25);

		expect($this->db()->queriesMatching('hubRank'))->toHaveCount(1);
	});

	it('groups their rows if a condition counted refers to their site', function () {
		$this->queries->count(Entry::find()->siteId([1, 2]), [], ['enabled' => ['elements_sites.enabled' => true]]);

		expect($this->db()->counts()[0])->toContain('MAX(');
	});
});

describe('pages of elements', function () {
	beforeEach(function () {
		// Pages loaded like the service does, apart from the rows of the query, which aren't prepared.
		$this->queries = new class() extends QueryService {
			protected function rows(ElementQuery $query): Query
			{
				return (new Query())->from(['hubQuery' => $query]);
			}
		};
	});

	it('loads the elements of a page on the site each was found on, without the criteria of the query', function () {
		$this->db()->respond('hubRank', [['hubId' => '5', 'hubSiteId' => '2'], ['hubId' => '3', 'hubSiteId' => '1'], ['hubId' => '4', 'hubSiteId' => '2']]);
		ElementQueries::respond(Entry::class, fn (ElementQuery $query) => array_map(fn (int $id) => new Entry(['id' => $id, 'siteId' => $query->siteId]), $query->id));

		$page = $this->queries->page(Entry::find()->siteId([1, 2])->status('live')->orderBy(['elements.dateUpdated' => SORT_DESC]), 0, 25);
		$queries = ElementQueries::executed(Entry::class);

		expect(array_map(fn (Entry $entry) => "$entry->id@$entry->siteId", $page))->toBe(['5@2', '3@1', '4@2'])
			->and(array_map(fn (ElementQuery $query) => [$query->siteId, $query->id], $queries))->toBe([[2, [5, 4]], [1, [3]]])
			->and($queries[0]->status)->toBeNull();
	});

	it('loads the elements of a page on a single site on that site', function () {
		$this->db()->respond('hubId', [['hubId' => '7']]);
		ElementQueries::respond(Entry::class, fn (ElementQuery $query) => [new Entry(['id' => 7, 'siteId' => 2])]);

		expect($this->queries->page(Entry::find()->siteId([2]), 0, 25))->toHaveCount(1)
			->and(ElementQueries::executed(Entry::class)[0]->siteId)->toBe(2)
			->and(ElementQueries::executed(Entry::class)[0]->unique)->toBeFalse();
	});

	it('loads no elements for an empty page', function () {
		expect($this->queries->page(Entry::find()->siteId([1, 2]), 100, 25))->toBe([])
			->and(ElementQueries::executed(Entry::class))->toBe([]);
	});
});
