<?php

namespace SomehowDigital\Craft\Hub\helpers;

/**
 * Pre-aggregates grouped query rows, so sums by any combination of criteria are lookups instead of passes over all rows.
 */
final class Aggregator
{
	/**
	 * Sums the rows for every combination of the given columns, with `null` criteria matching any value.
	 *
	 * Rows aren't summed by a criterion they have no value for, e.g. nested entries by section.
	 *
	 * @template T
	 * @param iterable<array<string,mixed>> $rows
	 * @param string[] $columns
	 * @param callable(T|null,array<string,mixed>):T $add adds a row to a sum, which is null for the first row
	 * @return array<string,T> keyed by {@see key()}
	 */
	public static function index(iterable $rows, array $columns, callable $add): array
	{
		$columns = array_values($columns);
		$combinations = 1 << count($columns);
		$sums = [];

		foreach ($rows as $row) {
			for ($mask = 0; $mask < $combinations; $mask++) {
				$criteria = [];

				foreach ($columns as $i => $column) {
					if (!($mask & (1 << $i))) {
						$criteria[] = null;
					} elseif ($row[$column] === null) {
						continue 2;
					} else {
						$criteria[] = $row[$column];
					}
				}

				$key = self::key($criteria);
				$sums[$key] = $add($sums[$key] ?? null, $row);
			}
		}

		return $sums;
	}

	/**
	 * @param array<int,int|string|null> $criteria values in the order of the indexed columns, null for any value
	 */
	public static function key(array $criteria): string
	{
		return implode('|', array_map(fn ($value) => $value === null ? '*' : (string)$value, $criteria));
	}

	/**
	 * Sums a column of the rows by the values of another one.
	 *
	 * @param iterable<array<string,mixed>> $rows
	 * @return array<int|string,int> value of the group column => sum
	 */
	public static function sumBy(iterable $rows, string $group, string $column = 'total'): array
	{
		$sums = [];

		foreach ($rows as $row) {
			if ($row[$group] !== null) {
				$sums[$row[$group]] = ($sums[$row[$group]] ?? 0) + (int)$row[$column];
			}
		}

		return $sums;
	}

	/**
	 * Sums a column of all rows.
	 *
	 * @param iterable<array<string,mixed>> $rows
	 */
	public static function sum(iterable $rows, string $column = 'total'): int
	{
		$sum = 0;

		foreach ($rows as $row) {
			$sum += (int)$row[$column];
		}

		return $sum;
	}
}
