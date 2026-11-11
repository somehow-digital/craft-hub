<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\base;

use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use yii\db\ExpressionInterface;

/**
 * Runs the element queries that the QueryService counts and pages, in place of its own queries; the seam to the elements of
 * Craft, which tests answer without preparing the queries, as preparing them takes most of Craft.
 *
 * @internal
 */
interface ElementQueryRunnerInterface
{
	/**
	 * How many elements a query matches; null to leave it to the QueryService.
	 *
	 * @param ElementQuery<array-key,covariant ElementInterface> $query
	 */
	public function total(ElementQuery $query): ?int;

	/**
	 * Whether a query matches any element; null to leave it to the QueryService.
	 *
	 * @param ElementQuery<array-key,covariant ElementInterface> $query
	 */
	public function exists(ElementQuery $query): ?bool;

	/**
	 * The elements on a page; null to leave it to the QueryService.
	 *
	 * @param ElementQuery<array-key,covariant ElementInterface> $query
	 * @return list<ElementInterface>|null
	 */
	public function page(ElementQuery $query, int $offset, int $limit): ?array;

	/**
	 * The rows of a query that the counts are built on, a database query (`craft\db\Query`, named by its interface as
	 * contracts know nothing of the database); null where they're those of the query itself.
	 *
	 * @param ElementQuery<array-key,covariant ElementInterface> $query
	 */
	public function rows(ElementQuery $query): ?ExpressionInterface;
}
