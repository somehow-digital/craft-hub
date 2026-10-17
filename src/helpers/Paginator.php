<?php

namespace SomehowDigital\Craft\Hub\helpers;

use Closure;
use Craft;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\web\twig\variables\Paginate;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\QueryService;

/**
 * Splits the lists of the Hub into pages, like Craft's paginator: the page asked for is kept within the pages there are,
 * a list without items having a single, empty page. Only the items of the page shown are loaded.
 */
final class Paginator
{
	/** items of a page of every list */
	public const PAGE_SIZE = 50;

	/**
	 * @param int $page the page asked for, kept within the pages there are
	 */
	public function __construct(private readonly int $page, private readonly int $pageSize = self::PAGE_SIZE)
	{
	}

	/**
	 * A paginator of the page of the URL.
	 */
	public static function fromRequest(): self
	{
		return new self(Craft::$app->getRequest()->getPageNum());
	}

	/**
	 * Paginates a list filtered and sorted in memory, like those of the structure pages, keeping its keys.
	 *
	 * @template T
	 * @param array<array-key,T> $items
	 * @return array{0:Paginate,1:array<array-key,T>}
	 */
	public function items(array $items): array
	{
		return $this->paginate(count($items), fn (int $offset, int $limit) => array_slice($items, $offset, $limit, true));
	}

	/**
	 * Paginates the rows of a query, for lists that aren't of elements, like the activity log.
	 *
	 * @return array{0:Paginate,1:array<int,array<string,mixed>>}
	 */
	public function rows(Query $query): array
	{
		return $this->paginate(
			(int)(clone $query)->orderBy([])->count(),
			fn (int $offset, int $limit) => (clone $query)->offset($offset)->limit($limit)->all(),
		);
	}

	/**
	 * Paginates the elements of a list. Only those of the page are loaded, as {@see QueryService} finds them, and counted.
	 *
	 * @return array{0:Paginate,1:array<int,mixed>}
	 */
	public function elements(ElementQuery $query): array
	{
		$queries = Hub::getInstance()->queries;

		return $this->paginate($queries->total($query), fn (int $offset, int $limit) => $queries->page($query, $offset, $limit));
	}

	/**
	 * Paginates a query on a single site, loading the elements of the page with the query itself, for elements
	 * {@see QueryService} can't load again by their ID, like revisions.
	 *
	 * @return array{0:Paginate,1:array<int,mixed>}
	 */
	public function query(ElementQuery $query): array
	{
		$queries = Hub::getInstance()->queries;

		return $this->paginate($queries->total($query), fn (int $offset, int $limit) => (clone $query)->offset($offset)->limit($limit)->all());
	}

	/**
	 * The page shown of a list with as many items, with the items it loads.
	 *
	 * @template T
	 * @param Closure(int,int):array<array-key,T> $load the items of the page, by their offset and limit
	 * @return array{0:Paginate,1:array<array-key,T>}
	 */
	private function paginate(int $total, Closure $load): array
	{
		$totalPages = max(1, (int)ceil($total / $this->pageSize));
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
