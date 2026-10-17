<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\elements\db\ElementQuery;

/**
 * Results of element queries, which the {@see FakeConnection} answers without preparing them.
 */
final class ElementQueries
{
	/** @var array<int,callable(ElementQuery):array<int,mixed>> */
	private static array $handlers = [];

	/** @var ElementQuery[] queries run, in order */
	public static array $executed = [];

	public static function reset(): void
	{
		self::$handlers = [];
		self::$executed = [];
	}

	/**
	 * Answers the queries of an element type with elements, or rows for queries fetching arrays.
	 * Elements given as a list only answer queries fetching elements, so the counts the same code path fetches as arrays stay empty.
	 *
	 * @param class-string $elementType
	 * @param array<int,mixed>|callable(ElementQuery):array<int,mixed> $results
	 */
	public static function respond(string $elementType, array|callable $results): void
	{
		array_unshift(self::$handlers, function (ElementQuery $query) use ($elementType, $results) {
			if ($query->elementType !== $elementType) {
				return null;
			}

			if (is_callable($results)) {
				return $results($query);
			}

			return $query->asArray && is_object(reset($results)) ? null : $results;
		});
	}

	/**
	 * @return array<int,mixed>
	 */
	public static function resolve(ElementQuery $query): array
	{
		self::$executed[] = $query;

		foreach (self::$handlers as $handler) {
			$results = $handler($query);

			if ($results !== null) {
				return array_values($results);
			}
		}

		return [];
	}

	/**
	 * Queries run for an element type.
	 *
	 * @param class-string $elementType
	 * @return ElementQuery[]
	 */
	public static function executed(string $elementType): array
	{
		return array_values(array_filter(self::$executed, fn (ElementQuery $query) => $query->elementType === $elementType));
	}
}
