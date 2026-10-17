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
 * Raw SQL of the `WHERE` clause a condition builds.
 *
 * @param array<int|string,mixed> $condition
 */
function conditionSql(array $condition): string
{
	return rawSql((new Query())->from(['elements' => 'elements'])->where($condition));
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

/**
 * A row of a predefined filter of a kind, configured with a threshold.
 *
 * @return array<string,mixed>
 */
function thresholdRow(string $kind, string $handle, int $threshold): array
{
	return [
		'id' => crc32("$kind.$handle"),
		'kind' => $kind,
		'handle' => $handle,
		'userId' => null,
		'name' => null,
		'description' => null,
		'icon' => null,
		'params' => null,
		'matchCondition' => null,
		'threshold' => $threshold,
		'enabled' => 1,
		'sortOrder' => 1,
		'uid' => "$kind.$handle",
	];
}

/**
 * Cards of a statistics tab with their data loaded, as their panels load them once opened.
 *
 * @param array<int,array<string,mixed>> $cards see BaseController::lazyCard()
 * @return array<int,array<string,mixed>>
 */
function loadCards(array $cards): array
{
	return array_map(fn (array $card) => [...$card, ...$card['load']()], $cards);
}
