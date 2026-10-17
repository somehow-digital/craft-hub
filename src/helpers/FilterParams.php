<?php

namespace SomehowDigital\Craft\Hub\helpers;

use Craft;
use DateTime;
use DateTimeZone;
use SomehowDigital\Craft\Hub\Hub;

/**
 * The query params of a list, as they appear in its URL or are kept by a saved filter, read as the values of its
 * filters; missing and invalid values don't filter.
 */
final class FilterParams
{
	/** value of filters by users standing for the user viewing the list, so saved filters list each user's own items */
	public const ME = 'me';

	/**
	 * @param array<string,mixed> $params
	 */
	public function __construct(private readonly array $params)
	{
	}

	/**
	 * Query params of a list that are its filters and sorting, leaving out the site scope and the page.
	 * Craft adds the site of the CP to its URLs on multi-site installs, so that isn't a filter either.
	 *
	 * @return array<string,mixed>
	 */
	public static function listParams(mixed $params): array
	{
		if (!is_array($params)) {
			return [];
		}

		unset($params[Hub::SITE_PARAM], $params['site'], $params['page']);

		return $params;
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
	 * @return string[]
	 */
	public function list(string $name, ?array $allowed = null): array
	{
		$values = array_values(array_unique(array_filter(array_map('trim', explode(',', $this->string($name) ?? '')), fn (string $item) => $item !== '')));

		return $allowed !== null ? array_values(array_intersect($values, $allowed)) : $values;
	}

	/**
	 * Comma-separated IDs of a filter, like `?author=1,4`.
	 *
	 * @return int[]
	 */
	public function ids(string $name): array
	{
		return Ids::positive($this->list($name));
	}

	/**
	 * Comma-separated IDs of a filter by users, like `?author=me,4`, where `me` stands for the user viewing the list.
	 *
	 * @return array<int|string> IDs, and `me`
	 */
	public function users(string $name): array
	{
		$values = $this->list($name);

		return [...(in_array(self::ME, $values, true) ? [self::ME] : []), ...$this->ids($name)];
	}

	/**
	 * IDs of the users of a filter by users, `me` being the current user. Without one, e.g. in the console, `me` is `0`,
	 * which matches no one, so the filter doesn't match the items of everyone instead.
	 *
	 * @param array<int|string> $values IDs, and `me`
	 * @return int[]
	 */
	public static function userIds(array $values): array
	{
		return array_map(fn (int|string $value) => $value === self::ME ? (int)Craft::$app->getUser()->getId() : (int)$value, $values);
	}

	/**
	 * A non-negative number of a range filter.
	 */
	public function number(string $name): ?float
	{
		$value = $this->string($name);

		return $value !== null && is_numeric($value) && (float)$value >= 0 ? (float)$value : null;
	}

	/**
	 * A whole non-negative number of a range filter.
	 */
	public function int(string $name): ?int
	{
		$value = $this->number($name);

		return $value !== null ? (int)floor($value) : null;
	}

	/**
	 * A day of a date range filter, as sent by date inputs (`Y-m-d`), in the user's time zone.
	 */
	public function date(string $name): ?DateTime
	{
		$value = $this->string($name);

		if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return null;
		}

		return DateTime::createFromFormat('!Y-m-d', $value, new DateTimeZone(Craft::$app->getTimeZone())) ?: null;
	}
}
