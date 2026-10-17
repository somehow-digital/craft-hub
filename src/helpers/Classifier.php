<?php

namespace SomehowDigital\Craft\Hub\helpers;

use SomehowDigital\Craft\Hub\enums\UsageKind;

/**
 * Pure helpers without Craft dependencies, so they can be unit tested.
 */
final class Classifier
{
	/**
	 * Resolves the `sources` setting of an Entries field or a Link field's entry link type to section UIDs.
	 *
	 * @param string|string[]|null $sources `'*'`, or a list of `section:<uid>` / `singles` keys
	 * @param array<string,string> $sectionTypes section UID => section type
	 * @return string[]
	 */
	public static function resolveSectionUids(string|array|null $sources, array $sectionTypes): array
	{
		if ($sources === '*') {
			return array_keys($sectionTypes);
		}

		if (!is_array($sources)) {
			return [];
		}

		$uids = [];

		foreach ($sources as $source) {
			if ($source === '*') {
				return array_keys($sectionTypes);
			}

			if ($source === 'singles') {
				foreach ($sectionTypes as $uid => $type) {
					if ($type === 'single') {
						$uids[$uid] = true;
					}
				}
				continue;
			}

			if (str_starts_with($source, 'section:')) {
				$uid = substr($source, 8);
				if (isset($sectionTypes[$uid])) {
					$uids[$uid] = true;
				}
			}
		}

		return array_keys($uids);
	}

	/**
	 * Resolves the `sources` setting of an Assets field or a Link field's asset link type to volume UIDs.
	 *
	 * @param string|string[]|null $sources `'*'`, or a list of `volume:<uid>` keys
	 * @param string[] $volumeUids all volume UIDs the sources can resolve to
	 * @return string[]
	 */
	public static function resolveVolumeUids(string|array|null $sources, array $volumeUids): array
	{
		if ($sources === '*') {
			return $volumeUids;
		}

		if (!is_array($sources)) {
			return [];
		}

		$uids = [];

		foreach ($sources as $source) {
			if ($source === '*') {
				return $volumeUids;
			}

			if (str_starts_with($source, 'volume:')) {
				$uid = substr($source, 7);
				if (in_array($uid, $volumeUids, true)) {
					$uids[$uid] = true;
				}
			}
		}

		return array_keys($uids);
	}

	/**
	 * Whether two field handles are similar enough to hint at duplicate fields,
	 * e.g. `teaserImage`/`teaser_image`, `teaserImage`/`teaserImg` or `link`/`links`.
	 */
	public static function similarHandles(string $a, string $b, float $threshold = 0.8): bool
	{
		$a = self::normalizeHandle($a);
		$b = self::normalizeHandle($b);

		if ($a === $b) {
			return true;
		}

		$length = max(strlen($a), strlen($b));

		// Short handles differ in a single character too easily (`text`/`test`).
		if (min(strlen($a), strlen($b)) < 4) {
			return false;
		}

		// The distance is at least the difference in length, so most pairs are ruled out without computing it.
		if (1 - abs(strlen($a) - strlen($b)) / $length < $threshold) {
			return false;
		}

		return 1 - levenshtein($a, $b) / $length >= $threshold;
	}

	/**
	 * Whether a filename is a camera, screenshot or download default that doesn't describe the file,
	 * e.g. `IMG_1234.jpg`, `Screenshot 2024-01-01 at 10.00.00.png` or `d41d8cd98f00b204e9800998ecf8427e.png`.
	 */
	public static function isGenericFilename(string $filename): bool
	{
		$name = mb_strtolower(pathinfo($filename, PATHINFO_FILENAME));

		// Hashes and IDs
		if (preg_match('/^[0-9a-f]{16,}$/', $name) || preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $name)) {
			return true;
		}

		$rest = preg_replace(
			'/^(whatsapp image|bildschirmfoto|screenshot|screen shot|unbenannt|untitled|download|picture|mvimg|image|photo|foto|dscn|dscf|dcim|bild|scan|file|img|dsc|pxl|pic)/',
			'',
			$name,
			1,
			$count,
		) ?? $name;

		// Dates and times of screenshots, e.g. "2024-01-01 at 10.00.00" or "vom 2024-01-01 um 10.00.00".
		$rest = preg_replace('/\b(at|um|am|vom|from|von)\b/', '', $rest) ?? $rest;

		return ($count > 0 || $rest !== '') && !preg_match('/\p{L}/u', $rest);
	}

	private static function normalizeHandle(string $handle): string
	{
		return strtolower(str_replace(['_', '-'], '', $handle));
	}

	/**
	 * @return UsageKind[]
	 */
	public static function entryTypeKinds(
		int $sectionCount,
		int $nestedFieldCount,
		int $referenceFieldCount,
		int $linkFieldCount,
		bool $hasUrls,
		int $entryCount,
	): array {
		$kinds = [];

		if ($sectionCount > 0) {
			$kinds[] = UsageKind::Section;
		}

		if ($referenceFieldCount > 0) {
			$kinds[] = UsageKind::Reference;
		}

		if ($linkFieldCount > 0) {
			$kinds[] = UsageKind::Link;
		}

		if ($nestedFieldCount > 0) {
			$kinds[] = UsageKind::Nested;
		}

		if ($hasUrls) {
			$kinds[] = UsageKind::HasUrls;
		}

		if ($sectionCount === 0 && $nestedFieldCount === 0) {
			$kinds[] = UsageKind::Unused;
		} elseif ($entryCount === 0) {
			$kinds[] = UsageKind::Empty;
		}

		return $kinds;
	}
}
