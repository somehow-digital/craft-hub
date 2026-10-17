<?php

namespace SomehowDigital\Craft\Hub\helpers;

/**
 * Sets of sites, kept as the comma-separated IDs of the sites, like the snapshots keep which sites their counts are of.
 *
 * Counts of items are kept by the set of sites the items are on, so they can be counted for any of the sites later:
 * an item is counted for some sites when any of them is in its set, see matches().
 */
final class SiteSet
{
	/** stands for every site, like for relations of fields that aren't translatable */
	public const ANY = 0;

	/**
	 * @return int[]
	 */
	public static function parse(?string $value): array
	{
		if ($value === null || $value === '') {
			return [];
		}

		return array_map('intval', explode(',', $value));
	}

	/**
	 * The set of the sites, sorted, so the same sites always make the same set; a set with any site stands for every site.
	 *
	 * @param int[] $siteIds
	 */
	public static function format(array $siteIds): ?string
	{
		if ($siteIds === []) {
			return null;
		}

		if (in_array(self::ANY, $siteIds, true)) {
			return (string)self::ANY;
		}

		$siteIds = array_unique($siteIds);
		sort($siteIds);

		return implode(',', $siteIds);
	}

	/**
	 * Whether a set has any of the sites.
	 *
	 * @param int[] $siteIds
	 */
	public static function matches(?string $set, array $siteIds): bool
	{
		$ids = self::parse($set);

		return in_array(self::ANY, $ids, true) || array_intersect($ids, $siteIds) !== [];
	}

	/**
	 * The set of the sites of both sets.
	 */
	public static function union(?string $a, ?string $b): ?string
	{
		return self::format([...self::parse($a), ...self::parse($b)]);
	}
}
