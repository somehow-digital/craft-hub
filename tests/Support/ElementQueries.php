<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\Tests\Support;

use Closure;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;

/**
 * Results of element queries, which the {@see FakeConnection} answers without preparing them.
 */
final class ElementQueries
{
	/** @var list<callable(ElementQuery<array-key,covariant ElementInterface>):(array<mixed>|null)> */
	private static array $handlers = [];

	/** @var list<ElementQuery<array-key,covariant ElementInterface>> queries run, in order */
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
	 * @param array<mixed>|Closure $results a closure is called with the query, of the class of the element type
	 */
	public static function respond(string $elementType, array|Closure $results): void
	{
		array_unshift(self::$handlers, function (ElementQuery $query) use ($elementType, $results): ?array {
			if ($query->elementType !== $elementType) {
				return null;
			}

			if ($results instanceof Closure) {
				/** @var array<int,mixed> $answer */
				$answer = $results($query);

				return $answer;
			}

			return $query->asArray && is_object(reset($results)) ? null : $results;
		});
	}

	/**
	 * @param ElementQuery<array-key,covariant ElementInterface> $query
	 * @return list<mixed>
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
	 * @return list<ElementQuery<array-key,covariant ElementInterface>>
	 */
	public static function executed(string $elementType): array
	{
		return array_values(array_filter(self::$executed, fn(ElementQuery $query): bool => $query->elementType === $elementType));
	}
}
