<?php

namespace SomehowDigital\Craft\Hub\helpers;

/**
 * IDs posted to the control panel.
 */
final class Ids
{
	/**
	 * Positive IDs of a value holding a list of them; anything else holds none.
	 *
	 * @return int[]
	 */
	public static function positive(mixed $ids): array
	{
		return is_array($ids) ? array_values(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)) : [];
	}
}
