<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\helpers;

use DateTime;

/**
 * Values that count as given only while they are not empty.
 *
 * @internal
 */
final class Fill
{
	/**
	 * The text, or null if there is none.
	 */
	public static function text(mixed $text): ?string
	{
		return is_scalar($text) && (string) $text !== '' ? (string) $text : null;
	}

	/**
	 * The date, or null if parsing failed.
	 */
	public static function date(DateTime|false|null $date): ?DateTime
	{
		return $date instanceof DateTime ? $date : null;
	}

	/**
	 * The number, or null if it is none.
	 */
	public static function number(?int $number): ?int
	{
		return $number !== null && $number !== 0 ? $number : null;
	}

	/**
	 * The entries of the array that are not falsy.
	 *
	 * @template TKey of array-key
	 * @template TValue
	 * @param array<TKey, TValue> $values
	 * @return array<TKey, TValue>
	 */
	public static function truthy(array $values): array
	{
		return array_filter($values, static fn(mixed $value): bool => !in_array($value, [null, false, 0, 0.0, '', '0', []], true));
	}

	/**
	 * The counts keyed by ID, as integers.
	 *
	 * @param array<mixed> $counts
	 * @return array<int,int>
	 */
	public static function counts(array $counts): array
	{
		$result = [];

		foreach ($counts as $id => $count) {
			$result[self::int($id)] = self::int($count);
		}

		return $result;
	}

	/**
	 * The ID, or null if there is none.
	 */
	public static function id(mixed $id): ?int
	{
		return is_numeric($id) ? (int) $id : null;
	}

	/**
	 * The value as an integer, 0 if it is not numeric.
	 */
	public static function int(mixed $value): int
	{
		return is_numeric($value) ? (int) $value : 0;
	}

	/**
	 * The value as an array: itself if it is one, nothing for null, otherwise as its only item.
	 *
	 * @return array<mixed>
	 */
	public static function list(mixed $value): array
	{
		return match (true) {
			is_array($value) => $value,
			$value === null => [],
			default => [$value],
		};
	}

	/**
	 * The strings among the items of the value.
	 *
	 * @return list<string>
	 */
	public static function strings(mixed $value): array
	{
		return array_values(array_filter(self::list($value), is_string(...)));
	}

	/**
	 * The items of the value under string keys.
	 *
	 * @return array<string,mixed>
	 */
	public static function map(mixed $value): array
	{
		return array_filter(self::list($value), is_string(...), ARRAY_FILTER_USE_KEY);
	}
}
