<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use SomehowDigital\Craft\Hub\services\QueryService;

/**
 * Counts element queries without preparing them, as preparing them takes most of Craft.
 *
 * Counts are built on the unprepared query and answered by the {@see FakeConnection},
 * while totals and pages are answered by {@see ElementQueries}, like the queries themselves.
 */
class FakeQueryService extends QueryService
{
	public function total(ElementQuery $query): int
	{
		return count(ElementQueries::resolve($query));
	}

	public function page(ElementQuery $query, int $offset, int $limit): array
	{
		return array_slice(ElementQueries::resolve($query), $offset, $limit);
	}

	protected function rows(ElementQuery $query): Query
	{
		return (new Query())->from(['hubQuery' => $query]);
	}
}
