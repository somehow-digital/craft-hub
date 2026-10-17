<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\db\UserQuery;
use craft\elements\Entry;
use yii\base\Component;
use yii\db\Expression;

/**
 * Counts and pages element queries over several sites without querying unique elements.
 *
 * Queries for unique elements on several sites pick a site for each element with a subquery repeating all criteria,
 * for each element and site. Instead, elements are counted by their ID, and the elements of a page are found by ranking
 * the sites of each element like that subquery does, so only the elements of the page are loaded with it.
 * As that subquery picks a site among those matching all criteria, an element counts if it matches on any of its sites.
 * On a single site, each element has a single row, so the rows are counted and sorted as they are.
 * The same goes for several sites when nothing depends on the site of a row: the sites aren't joined then, but matched
 * with a subquery, as an element matches on all of its sites or none, so it has a single row too.
 */
class QueryService extends Component
{
	/**
	 * Counts the elements of a query, and those matching each of the conditions, grouped by the given columns.
	 * An element counts for a condition, or a value of a case, if it matches it on any of its sites.
	 *
	 * The counts are sums of one row per element, as counting distinct IDs takes a sort for each count.
	 * Conditions are evaluated for each element and site, or once per element if they don't depend on the site,
	 * which saves evaluating them again for each site. Each of them is aggregated once, as databases may evaluate
	 * a value of a subquery again for each aggregate referring to it; the values of a case are bits of a single value.
	 *
	 * @param array<string,string> $groups alias => column to group by, which each element has a single value of
	 * @param array<string,mixed> $conditions alias => condition, in the format of `where()`
	 * @param array<string,array<string,mixed>> $cases alias => value => condition; the first matching value counts, null for the remaining elements
	 * @param array<string,string|Query>|null $tables alias => table the conditions refer to besides `elements`, joined by element ID,
	 *     to evaluate them once per element; null if they depend on the site, to evaluate them on the rows of the query.
	 *     Tables are inner joined, as each element has a row in them; subqueries are left joined, as it may not have one
	 * @return array<int,array<string,mixed>> a row for each group, with its columns, the `total`, the count of each condition,
	 *     and the count of each value of a case as `{alias}_{value}`
	 */
	public function count(ElementQuery $query, array $groups, array $conditions = [], array $cases = [], ?array $tables = null): array
	{
		$aliases = array_keys($groups);

		// The value of each condition and case for each row, as 1 or 0, and as the bit of the matching value.
		$values = [];

		foreach ($conditions as $alias => $condition) {
			$values[$alias] = sprintf('CASE WHEN %s THEN 1 ELSE 0 END', $this->sql($condition));
		}

		foreach ($cases as $alias => $caseValues) {
			$values[$alias] = $this->caseSql($caseValues);
		}

		[$rows, $single] = $this->elementRows($query, [...array_values($groups), ...array_values($values)]);

		if ($tables !== null) {
			// A row for each element, joined to the tables the conditions are evaluated on.
			$rows->select(['hubId' => 'elements.id', ...$groups]);

			if (!$single) {
				$rows->groupBy(['elements.id', ...array_values($groups)]);
			}

			$source = (new Query())
				->from(['hubIds' => $rows])
				->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[hubIds.hubId]]');

			foreach ($tables as $alias => $table) {
				$table instanceof Query
					? $source->leftJoin([$alias => $table], "[[$alias.id]] = [[elements.id]]")
					: $source->innerJoin([$alias => $table], "[[$alias.id]] = [[elements.id]]");
			}

			$columns = array_map(fn (string $alias) => "[[hubIds.$alias]]", array_combine($aliases, $aliases));
			$id = '[[hubIds.hubId]]';
		} else {
			$rows->select([...$groups, 'hubId' => 'elements.id', ...array_map(fn (string $sql) => new Expression($sql), $values)]);
			$source = (new Query())->from(['hubRows' => $rows]);
			$columns = array_map(fn (string $alias) => "[[$alias]]", array_combine($aliases, $aliases));
			$values = array_map(fn (string $alias) => "[[$alias]]", array_combine(array_keys($values), array_keys($values)));
			$id = '[[hubId]]';
		}

		// A row for each element, with whether it matches each condition and value on any of its sites.
		$elements = $source->select(array_map(fn (string $column) => new Expression($column), $columns));

		if (!$single) {
			$elements->groupBy(array_map(fn (string $column) => new Expression($column), [$id, ...array_values($columns)]));
		} elseif (Craft::$app->getDb()->getIsPgsql()) {
			// PostgreSQL pulls a subquery without grouping up into the counts, evaluating the value of each condition
			// again for each aggregate referring to it; an offset keeps it apart. MySQL keeps subqueries apart
			// that select subqueries already.
			$elements->offset(new Expression('0'));
		}

		$aggregate = fn (string $function, string $sql) => new Expression($single ? $sql : "$function($sql)");

		$counts = (new Query())
			->select([...$aliases, 'total' => new Expression('COUNT(*)')])
			->from(['hubElements' => $elements]);

		foreach (array_keys($conditions) as $alias) {
			$elements->addSelect([$alias => $aggregate('MAX', $values[$alias])]);
			$counts->addSelect([$alias => new Expression(sprintf('SUM([[%s]])', $alias))]);
		}

		foreach ($cases as $alias => $caseValues) {
			$elements->addSelect([$alias => $aggregate('BIT_OR', $values[$alias])]);

			foreach (array_keys($caseValues) as $bit => $value) {
				$counts->addSelect(["{$alias}_$value" => new Expression(sprintf('SUM(CASE WHEN ([[%s]] & %d) <> 0 THEN 1 ELSE 0 END)', $alias, 1 << $bit))]);
			}
		}

		if ($aliases !== []) {
			$counts->groupBy($aliases);
		}

		return $counts->all();
	}

	/**
	 * How many elements a query matches.
	 */
	public function total(ElementQuery $query): int
	{
		return (int)($this->count($query, [])[0]['total'] ?? 0);
	}

	/**
	 * IDs of the elements on a page of a query, in its order.
	 *
	 * @return int[]
	 */
	public function pageIds(ElementQuery $query, int $offset, int $limit): array
	{
		return array_keys($this->pageSites($query, $offset, $limit));
	}

	/**
	 * The elements on a page of a query, in its order.
	 *
	 * They are loaded by their ID on the site found for them, so the criteria aren't evaluated again;
	 * only elements matching on all sites or none are loaded on their preferred site, without the criteria too.
	 *
	 * @return array<int,mixed>
	 */
	public function page(ElementQuery $query, int $offset, int $limit): array
	{
		$sites = $this->pageSites($query, $offset, $limit);
		$bySite = [];

		foreach ($sites as $id => $siteId) {
			$bySite[$siteId ?? ''][] = $id;
		}

		$elements = [];

		foreach ($bySite as $siteId => $ids) {
			/** @var ElementQuery $siteQuery */
			// Lists show none of the custom fields, though the titles and preview targets of entries may be made of them.
			$siteQuery = $query->elementType::find()->id($ids)->status(null)->trashed($query->trashed)->with($query->with)->withCustomFields($query->elementType === Entry::class);

			if ($query instanceof UserQuery && $siteQuery instanceof UserQuery) {
				$siteQuery->withGroups($query->withGroups);
			}

			if ($siteId !== '') {
				$siteQuery->siteId($siteId);
			} else {
				$siteQuery->siteId($query->siteId)->preferSites($query->preferSites)->unique();
			}

			foreach ($siteQuery->all() as $element) {
				$elements[$element->id] = $element;
			}
		}

		return array_values(array_filter(array_map(fn (int $id) => $elements[$id] ?? null, array_keys($sites))));
	}

	/**
	 * Elements on a page of a query, in its order, with the site each of them is found on,
	 * or null if they match on all of their sites or none, so any of them is.
	 *
	 * @return array<int,int|null> element ID => site ID
	 */
	private function pageSites(ElementQuery $query, int $offset, int $limit): array
	{
		$orderBy = $query->orderBy ?? [];
		[$rows, $single] = $this->elementRows($query, array_map('strval', array_keys($orderBy)));
		$siteId = $this->isSingleSite($query) ? (int)(is_array($query->siteId) ? reset($query->siteId) : $query->siteId) : null;

		if ($single) {
			$ids = $rows
				->select(['hubId' => 'elements.id'])
				->orderBy($orderBy ?: ['elements.id' => SORT_ASC])
				->offset($offset)
				->limit($limit)
				->column();

			return array_fill_keys(array_map('intval', $ids), $siteId);
		}

		$select = [
			'hubId' => 'elements.id',
			'hubSiteId' => 'elements_sites.siteId',
			'hubRank' => new Expression(sprintf('ROW_NUMBER() OVER (PARTITION BY [[elements.id]] ORDER BY %s, [[elements_sites.id]])', $this->preferSql($query))),
		];
		$sort = [];

		foreach (array_keys($orderBy) as $i => $column) {
			$select["hubSort$i"] = $column;
			$sort["hubSort$i"] = $orderBy[$column];
		}

		$rows = (new Query())
			->select(['hubId', 'hubSiteId'])
			->from(['hubRows' => $rows->select($select)])
			->where(['hubRank' => 1])
			->orderBy($sort ?: ['hubId' => SORT_ASC])
			->offset($offset)
			->limit($limit)
			->all();

		$sites = [];

		foreach ($rows as $row) {
			$sites[(int)$row['hubId']] = (int)$row['hubSiteId'];
		}

		return $sites;
	}

	/**
	 * The rows of a query, a row for each element and site matching its criteria, with the tables of its criteria joined.
	 */
	protected function rows(ElementQuery $query): Query
	{
		return (clone $query)
			->unique(false)
			->orderBy(null)
			->offset(null)
			->limit(null)
			->prepareSubquery();
	}

	/**
	 * The rows of a query, and whether each element has a single row: on a single site, or on several sites if neither
	 * the criteria of the query nor the given SQL refer to the site of a row, as the sites are matched with a subquery then.
	 *
	 * @param string[] $sql columns and conditions evaluated on the rows
	 * @return array{0:Query,1:bool}
	 */
	private function elementRows(ElementQuery $query, array $sql): array
	{
		$rows = $this->rows($query);

		if ($this->isSingleSite($query)) {
			return [$rows, true];
		}

		$found = 0;
		$elementRows = clone $rows;
		$elementRows->where = $this->matchSites($elementRows->where, $found);
		$joins = array_filter($elementRows->join ?? [], fn (array $join) => !is_array($join[1]) || array_keys($join[1]) !== ['elements_sites']);

		// Only the join and the condition of the sites Craft adds are replaced.
		if ($found !== 1 || count($joins) !== count($elementRows->join ?? []) - 1) {
			return [$rows, false];
		}

		$elementRows->join = array_values($joins);
		$elementRows->select(['hubId' => 'elements.id']);
		[$criteria] = Craft::$app->getDb()->getQueryBuilder()->build($elementRows);

		foreach ([$criteria, ...$sql] as $part) {
			if (preg_match('/\belements_sites\W*\./', $part)) {
				return [$rows, false];
			}
		}

		return [$elementRows, true];
	}

	/**
	 * The conditions of a query, with the condition of its sites matching an element on any of them in a subquery.
	 */
	private function matchSites(mixed $condition, int &$found): mixed
	{
		if (!is_array($condition)) {
			return $condition;
		}

		if (array_keys($condition) === ['elements_sites.siteId']) {
			$found++;

			return ['exists', (new Query())
				->from(['hubSites' => Table::ELEMENTS_SITES])
				->where('[[hubSites.elementId]] = [[elements.id]]')
				->andWhere(['hubSites.siteId' => $condition['elements_sites.siteId']])];
		}

		if (is_string($condition[0] ?? null) && strtolower($condition[0]) === 'and') {
			foreach (array_slice(array_keys($condition), 1) as $key) {
				$condition[$key] = $this->matchSites($condition[$key], $found);
			}
		}

		return $condition;
	}

	/**
	 * Whether the query is limited to a single site, which each element has a single row on.
	 */
	private function isSingleSite(ElementQuery $query): bool
	{
		return is_numeric($query->siteId) || (is_array($query->siteId) && count($query->siteId) === 1);
	}

	/**
	 * Rank of a site of an element, like the `unique()` param ranks them: preferred sites first, then the current site.
	 */
	private function preferSql(ElementQuery $query): string
	{
		$sites = Craft::$app->getSites();
		$preferSites = [];

		foreach ($query->preferSites ?: [$sites->getCurrentSite()->id] as $site) {
			$id = is_numeric($site) ? (int)$site : $sites->getSiteByHandle((string)$site)?->id;

			if ($id !== null) {
				$preferSites[] = $id;
			}
		}

		$sql = 'CASE';

		foreach ($preferSites as $rank => $siteId) {
			$sql .= sprintf(' WHEN [[elements_sites.siteId]] = %d THEN %d', $siteId, $rank);
		}

		return $sql . sprintf(' ELSE %d END', count($preferSites));
	}

	/**
	 * The bit of the first value whose condition matches, in the order of the values.
	 *
	 * @param array<string,mixed> $values value => condition, null for the remaining rows
	 */
	private function caseSql(array $values): string
	{
		$sql = 'CASE';
		$else = 'NULL';

		foreach (array_values($values) as $bit => $condition) {
			if ($condition === null) {
				$else = (string)(1 << $bit);
			} else {
				$sql .= sprintf(' WHEN %s THEN %d', $this->sql($condition), 1 << $bit);
			}
		}

		return "$sql ELSE $else END";
	}

	/**
	 * SQL of a condition with its values inlined, as parameters of its own would clash with those of the query it is used in.
	 */
	private function sql(mixed $condition): string
	{
		$db = Craft::$app->getDb();
		$params = [];
		$sql = $db->getQueryBuilder()->buildCondition($condition, $params);

		return $db->createCommand($sql, $params)->getRawSql();
	}
}
