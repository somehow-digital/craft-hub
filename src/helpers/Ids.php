<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\helpers;

/**
 * IDs posted to the control panel.
 *
 * @internal
 */
final class Ids
{
	/**
	 * Positive IDs of a value holding a list of them; anything else holds none.
	 *
	 * @return list<int>
	 */
	public static function positive(mixed $ids): array
	{
		return is_array($ids) ? array_values(array_filter(array_map(Fill::int(...), $ids), fn(int $id): bool => $id > 0)) : [];
	}

	/**
	 * IDs of the saved elements or models.
	 *
	 * @param iterable<object{id:int|string|null}> $elements
	 * @return list<int>
	 */
	public static function of(iterable $elements): array
	{
		$ids = [];

		foreach ($elements as $element) {
			$id = Fill::id($element->id);

			if ($id !== null) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
