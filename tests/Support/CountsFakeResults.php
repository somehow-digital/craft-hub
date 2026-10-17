<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\elements\db\ElementQuery;

/**
 * Counts element queries by their results registered with {@see ElementQueries}, as counting prepares the query.
 */
trait CountsFakeResults
{
	/**
	 * A copy of the query that can be counted.
	 */
	public static function of(ElementQuery $query): static
	{
		$copy = new static($query->elementType);

		foreach (get_object_vars($query) as $name => $value) {
			$copy->$name = $value;
		}

		return $copy;
	}

	public function count($q = '*', $db = null): bool|int|string|null
	{
		return count(ElementQueries::resolve($this));
	}
}
