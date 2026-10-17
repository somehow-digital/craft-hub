<?php

use craft\db\Query;
use craft\elements\db\ElementQuery;
use SomehowDigital\Craft\Hub\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

/**
 * The SQL of a query, with its params filled in.
 */
function rawSql(Query $query): string
{
	return $query->createCommand()->getRawSql();
}

/**
 * The conditions a query was narrowed by, apart from the ones of the element query itself.
 *
 * @return array<int,mixed>
 */
function conditions(ElementQuery $query): array
{
	return is_array($query->where) && ($query->where[0] ?? null) === 'and' ? array_slice($query->where, 1) : [$query->where];
}
