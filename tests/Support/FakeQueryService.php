<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\Tests\Support;

use Closure;
use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use SomehowDigital\Craft\Hub\base\ElementQueryRunnerInterface;
use SomehowDigital\Craft\Hub\services\QueryService;
use SomehowDigital\Craft\Hub\services\SiteScopeService;

/**
 * Counts element queries without preparing them, as preparing them takes most of Craft.
 *
 * Counts are built on the unprepared query and answered by the {@see FakeConnection},
 * while totals, pages and whether queries match anything are answered by {@see ElementQueries}, like the queries themselves.
 */
final readonly class FakeQueryService implements ElementQueryRunnerInterface
{
	/**
	 * The service as the plugin creates it, but for the app of the test.
	 */
	/**
	 * @param (Closure(ElementQuery<array-key,covariant ElementInterface>):Query<array-key,mixed>)|null $rows the rows the counts are built on, by default those of the query
	 */
	public function __construct(
		private ?Closure $rows = null,
		private bool $answers = true
	) {}

	/**
	 * @param (Closure(ElementQuery<array-key,covariant ElementInterface>):Query<array-key,mixed>)|null $rows
	 */
	public static function make(?Closure $rows = null, bool $answers = true): QueryService
	{
		return new QueryService(Craft::createObject(SiteScopeService::class), Craft::$app->getDb(), Craft::$app->getSites(), new self($rows, $answers));
	}

	public function total(ElementQuery $query): ?int
	{
		return !$this->answers ? null : count(ElementQueries::resolve($query));
	}

	public function exists(ElementQuery $query): ?bool
	{
		return !$this->answers ? null : ElementQueries::resolve($query) !== [];
	}

	public function page(ElementQuery $query, int $offset, int $limit): ?array
	{
		if (!$this->answers) {
			return null;
		}

		/** @var list<ElementInterface> $elements */
		$elements = array_slice(ElementQueries::resolve($query), $offset, $limit);

		return $elements;
	}

	/**
	 * @param ElementQuery<array-key,covariant ElementInterface> $query
	 * @return Query<array-key,mixed>
	 */
	public function rows(ElementQuery $query): Query
	{
		return $this->rows instanceof Closure ? ($this->rows)($query) : (new Query())->from(['hubQuery' => $query]);
	}
}
