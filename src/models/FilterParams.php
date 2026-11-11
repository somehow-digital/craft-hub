<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use DateTime;
use DateTimeZone;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\helpers\Ids;

/**
 * The query params of a list, as they appear in its URL or are kept by a saved filter, read as the values of its
 * filters; missing and invalid values don't filter.
 *
 * @internal
 */
final readonly class FilterParams
{
	/** value of filters by users standing for the user viewing the list, so saved filters list each user's own items */
	public const ME = 'me';

	/** query param of the sites a page is scoped to */
	public const SITE_PARAM = 'hubSite';

	/** query param of the collapsible panel of a page loaded alone */
	public const PANEL_PARAM = 'panel';

	/**
	 * @param array<string,mixed> $params
	 * @param string $timeZone the one the days of the date filters are read in
	 */
	public function __construct(
		private array $params,
		private string $timeZone,
	) {}

	/**
	 * Query params of a list that are its filters and sorting, leaving out the site scope, the page and the panel loaded.
	 * Craft adds the site of the CP to its URLs on multi-site installs, so that isn't a filter either.
	 *
	 * @return array<string,mixed>
	 */
	public static function listParams(mixed $params): array
	{
		if (!is_array($params)) {
			return [];
		}

		unset($params[self::SITE_PARAM], $params['site'], $params['page'], $params[self::PANEL_PARAM]);

		return Fill::map($params);
	}

	/**
	 * The search of the list, like `?q=news`.
	 */
	public function search(): string
	{
		return trim($this->string('q') ?? '');
	}

	public function string(string $name): ?string
	{
		$value = $this->params[$name] ?? null;

		return is_string($value) ? $value : null;
	}

	/**
	 * Whether a checkbox filter is checked, like `?drafts=1`.
	 */
	public function flag(string $name): bool
	{
		return $this->string($name) === '1';
	}

	/**
	 * One of the allowed values, or null.
	 *
	 * @param string[] $allowed
	 */
	public function choice(string $name, array $allowed): ?string
	{
		$value = $this->string($name);

		return in_array($value, $allowed, true) ? $value : null;
	}

	/**
	 * Comma-separated values of a filter, like `?volume=images,documents`, optionally limited to the allowed ones.
	 *
	 * @param string[]|null $allowed
	 * @return list<string>
	 */
	public function list(string $name, ?array $allowed = null): array
	{
		$values = array_values(array_unique(array_filter(array_map(trim(...), explode(',', $this->string($name) ?? '')), fn(string $item): bool => $item !== '')));

		return $allowed !== null ? array_values(array_intersect($values, $allowed)) : $values;
	}

	/**
	 * Comma-separated IDs of a filter, like `?author=1,4`.
	 *
	 * @return list<int>
	 */
	public function ids(string $name): array
	{
		return Ids::positive($this->list($name));
	}

	/**
	 * Comma-separated IDs of a filter by users, like `?author=me,4`, where `me` stands for the user viewing the list.
	 *
	 * @return array<int|string> IDs, and `me`
	 *
	 * @return list<int|string>
	 */
	public function users(string $name): array
	{
		$values = $this->list($name);

		return [...(in_array(self::ME, $values, true) ? [self::ME] : []), ...$this->ids($name)];
	}

	/**
	 * A non-negative number of a range filter.
	 */
	public function number(string $name): ?float
	{
		$value = $this->string($name);

		return $value !== null && is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
	}

	/**
	 * A whole non-negative number of a range filter.
	 */
	public function int(string $name): ?int
	{
		$value = $this->number($name);

		return $value !== null ? (int) floor($value) : null;
	}

	/**
	 * A day of a date range filter, as sent by date inputs (`Y-m-d`), in the user's time zone.
	 */
	public function date(string $name): ?DateTime
	{
		$value = $this->string($name);

		if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return null;
		}

		return Fill::date(DateTime::createFromFormat('!Y-m-d', $value, new DateTimeZone($this->timeZone)));
	}
}
