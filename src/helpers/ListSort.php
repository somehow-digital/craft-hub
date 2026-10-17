<?php

namespace SomehowDigital\Craft\Hub\helpers;

use Craft;
use craft\helpers\UrlHelper;

/**
 * The column and direction a list sorted on the server is sorted by, as its query params say (`sort` and `dir`): columns
 * sort in their natural direction unless `dir` says otherwise, and unknown ones sort by the default column.
 */
final class ListSort
{
	/** the column sorted by */
	public readonly string $column;

	/** `SORT_ASC` or `SORT_DESC` */
	public readonly int $direction;

	/**
	 * @param array<string,int> $columns column => natural direction, `SORT_ASC` or `SORT_DESC`
	 * @param string $default column sorted by unless another one is asked for
	 * @param array<string,mixed> $params query params of the list
	 */
	public function __construct(private readonly array $columns, private readonly string $default, private readonly array $params)
	{
		$sort = $params['sort'] ?? null;
		$this->column = is_string($sort) && isset($columns[$sort]) ? $sort : $default;
		$this->direction = match ($params['dir'] ?? null) {
			'asc' => SORT_ASC,
			'desc' => SORT_DESC,
			default => $columns[$this->column],
		};
	}

	/**
	 * The sorting of a list by the query params of the request.
	 *
	 * @param array<string,int> $columns see the constructor
	 */
	public static function fromRequest(array $columns, string $default): self
	{
		return new self($columns, $default, Craft::$app->getRequest()->getQueryParamsWithoutPath());
	}

	/**
	 * Template variables of the sorted list: the column headers, and the sorting kept as hidden fields, so changing a
	 * filter keeps it; the default sort is left out.
	 *
	 * @param string $path page the list is on, which the headers link to
	 * @return array{sortHeaders:array<string,array{url:string,direction:string|null}>,sort:string|null,dir:string|null}
	 */
	public function variables(string $path): array
	{
		$natural = $this->direction === $this->columns[$this->column];

		return [
			'sortHeaders' => $this->headers($path),
			'sort' => $this->column !== $this->default || !$natural ? $this->column : null,
			'dir' => !$natural ? self::dir($this->direction) : null,
		];
	}

	/**
	 * Link and current direction of each sortable column header. Clicking a column sorts in its natural direction, then
	 * reverses it, then resets the sorting; the default column just reverses. The filters are kept, the page is reset, and
	 * the default sort is left out of the URL, unless nothing else is left in it, as a URL without any params would be
	 * redirected to the kept filters and sorting (see BaseController::keepFilters()).
	 *
	 * @return array<string,array{url:string,direction:string|null}>
	 */
	private function headers(string $path): array
	{
		$params = $this->params;
		unset($params['sort'], $params['dir'], $params['page']);

		$headers = [];
		foreach ($this->columns as $column => $natural) {
			$reset = $column === $this->column && $this->direction !== $natural;
			$next = $column === $this->column && !$reset ? ($this->direction === SORT_ASC ? SORT_DESC : SORT_ASC) : $natural;
			$query = $params;

			if ($reset || ($column === $this->default && $next === $natural)) {
				$query = FilterParams::listParams($query) !== [] ? $query : [...$query, 'sort' => $this->default];
			} else {
				$query['sort'] = $column;

				if ($next !== $natural) {
					$query['dir'] = self::dir($next);
				}
			}

			$headers[$column] = [
				'url' => UrlHelper::cpUrl($path, $query),
				'direction' => $column === $this->column ? self::dir($this->direction) : null,
			];
		}

		return $headers;
	}

	private static function dir(int $direction): string
	{
		return $direction === SORT_ASC ? 'asc' : 'desc';
	}
}
