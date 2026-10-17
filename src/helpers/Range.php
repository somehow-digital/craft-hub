<?php

namespace SomehowDigital\Craft\Hub\helpers;

use DateTime;
use yii\db\ExpressionInterface;

/**
 * Conditions for range filters, where either bound may be left open.
 */
final class Range
{
	/**
	 * Condition for a column or expression between the bounds, both included.
	 *
	 * @return array<int,mixed>|null null when both bounds are open
	 */
	public static function condition(string|ExpressionInterface $column, int|float|null $min, int|float|null $max): ?array
	{
		if ($min === null && $max === null) {
			return null;
		}

		return [
			'and',
			$min !== null ? ['>=', $column, $min] : [],
			$max !== null ? ['<=', $column, $max] : [],
		];
	}

	/**
	 * Element query date param for the days between the bounds, both days included.
	 *
	 * @return string[]|null null when both bounds are open
	 */
	public static function dateParam(?DateTime $from, ?DateTime $to): ?array
	{
		if ($from === null && $to === null) {
			return null;
		}

		$param = ['and'];

		if ($from !== null) {
			$param[] = '>= ' . (clone $from)->setTime(0, 0)->format(DateTime::ATOM);
		}

		if ($to !== null) {
			$param[] = '< ' . (clone $to)->setTime(0, 0)->modify('+1 day')->format(DateTime::ATOM);
		}

		return $param;
	}
}
