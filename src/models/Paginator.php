<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use Closure;
use craft\web\twig\variables\Paginate;

/**
 * Splits the lists of the Hub into pages, like Craft's paginator: the page asked for is kept within the pages there are,
 * a list without items having a single, empty page. Only the items of the page shown are loaded.
 *
 * @internal
 */
final readonly class Paginator
{
	/** items of a page of every list */
	public const PAGE_SIZE = 50;

	/**
	 * @param int $page the page asked for, kept within the pages there are
	 */
	public function __construct(
		private int $page,
		private int $pageSize = self::PAGE_SIZE
	) {}

	/**
	 * Paginates a list filtered and sorted in memory, like those of the structure pages, keeping its keys.
	 *
	 * @template T
	 * @param array<array-key,T> $items
	 * @return array{0:Paginate,1:array<array-key,T>}
	 */
	public function items(array $items): array
	{
		return $this->paginate(count($items), fn(int $offset, int $limit): array => array_slice($items, $offset, $limit, true));
	}

	/**
	 * The page shown of a list with as many items, with the items it loads.
	 *
	 * @template K of array-key
	 * @template T
	 * @param Closure(int,int):array<K,T> $load the items of the page, by their offset and limit
	 * @return array{0:Paginate,1:array<K,T>}
	 */
	public function paginate(int $total, Closure $load): array
	{
		$totalPages = max(1, (int) ceil($total / $this->pageSize));
		$page = max(1, min($this->page, $totalPages));
		$offset = ($page - 1) * $this->pageSize;
		$items = $load($offset, $this->pageSize);

		return [new Paginate([
			'first' => $offset + 1,
			'last' => $offset + count($items),
			'total' => $total,
			'currentPage' => $page,
			'totalPages' => $totalPages,
		]), $items];
	}
}
