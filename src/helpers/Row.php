<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\helpers;

use craft\helpers\DateTimeHelper;
use DateTime;
use UnexpectedValueException;

/**
 * Values of a database row, which the drivers return as strings, numbers or null, whatever the columns hold.
 *
 * @internal
 */
final class Row
{
	public static function int(mixed $row, string $key): int
	{
		return self::intOrNull($row, $key) ?? 0;
	}

	public static function intOrNull(mixed $row, string $key): ?int
	{
		$value = self::value($row, $key);

		return is_numeric($value) ? (int) $value : null;
	}

	public static function string(mixed $row, string $key): string
	{
		return self::stringOrNull($row, $key) ?? '';
	}

	public static function stringOrNull(mixed $row, string $key): ?string
	{
		$value = self::value($row, $key);

		return is_scalar($value) ? (string) $value : null;
	}

	public static function bool(mixed $row, string $key): bool
	{
		return filter_var(self::value($row, $key), FILTER_VALIDATE_BOOLEAN);
	}

	public static function date(mixed $row, string $key): ?DateTime
	{
		$value = self::value($row, $key);

		return (is_string($value) && $value !== '') || (is_int($value) && $value !== 0) ? Fill::date(DateTimeHelper::toDateTime($value)) : null;
	}

	public static function value(mixed $row, string $key): mixed
	{
		return is_array($row) ? ($row[$key] ?? null) : null;
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	public static function instance(mixed $row, string $key, string $class): object
	{
		$value = self::value($row, $key);

		return $value instanceof $class ? $value : throw new UnexpectedValueException("The row has no {$class} in '{$key}'.");
	}
}
