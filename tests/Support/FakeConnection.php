<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\db\Connection;
use craft\db\ExpressionBuilder;
use craft\db\ExpressionInterface;
use craft\elements\db\ElementQuery;
use PDO;

/**
 * A database connection that never connects: queries are built as usual, and answered by the responses tests register.
 *
 * Responses are matched against the raw SQL of a query, the most recently registered first; unmatched queries return no rows.
 * Element queries aren't built, but answered by {@see ElementQueries}.
 */
class FakeConnection extends Connection
{
	private const ELEMENT_QUERY = '/* element query %d */';

	/** @var array<int,array{0:string,1:mixed}> pattern => rows, or a callable receiving the raw SQL */
	private array $responses = [];

	/** @var ElementQuery[] element queries about to run, by their placeholder SQL */
	private array $elementQueries = [];

	private ?FakeMysqlQueryBuilder $mysqlBuilder = null;

	private ?FakePgsqlQueryBuilder $pgsqlBuilder = null;

	/** @var string[] raw SQL of the queries run, in order */
	public array $queries = [];

	public function init(): void
	{
		$this->commandClass = FakeCommand::class;

		parent::init();

		// Query builders read the server version from the PDO instance, which is never queried otherwise.
		$this->pdo = new PDO('sqlite::memory:');
	}

	public function getQueryBuilder()
	{
		// Configured like the builders of Craft's schemas.
		$config = [
			'expressionBuilders' => [
				ExpressionInterface::class => ExpressionBuilder::class,
			],
			'separator' => "\n",
		];

		return $this->getIsPgsql()
			? $this->pgsqlBuilder ??= new FakePgsqlQueryBuilder($this, $config)
			: $this->mysqlBuilder ??= new FakeMysqlQueryBuilder($this, $config);
	}

	/**
	 * Answers queries containing the pattern, a part of their raw SQL like `{{%relations}}`, quoted like the SQL.
	 *
	 * @param array<int,mixed>|callable(string):mixed|int|string $result rows, a scalar for `scalar()` and `count()`, or a callable returning either
	 */
	public function respond(string $pattern, mixed $result): static
	{
		array_unshift($this->responses, [$this->quoteSql($pattern), $result]);

		return $this;
	}

	public function resolve(string $sql): mixed
	{
		// Writes read the schema of their table, which has no columns, so they leave out Craft's audit columns.
		if (str_starts_with($sql, 'SHOW ')) {
			return str_starts_with($sql, 'SHOW CREATE TABLE') ? [['Table' => '', 'Create Table' => '']] : [];
		}

		$this->queries[] = $sql;

		foreach ($this->responses as [$pattern, $result]) {
			if (str_contains($sql, $pattern)) {
				return is_callable($result) ? $result($sql) : $result;
			}
		}

		return [];
	}

	/**
	 * Answers the counts of {@see FakeQueryService}, with rows for the element query counted.
	 *
	 * @param callable(ElementQuery):array<int,array<string,mixed>> $rows
	 */
	public function respondToCounts(callable $rows): static
	{
		return $this->respond('[[hubElements]]', fn (string $sql) => $rows($this->elementQueryIn($sql)));
	}

	/**
	 * Answers the entry counts of {@see \SomehowDigital\Craft\Hub\services\StatisticsService}, which reads those of
	 * section entries and nested entries separately, with the rows of either.
	 *
	 * @param array<int,array<string,mixed>>|callable(string):array<int,array<string,mixed>> $rows
	 */
	public function respondToEntryRows(array|callable $rows): static
	{
		return $this->respond('GROUP BY [[entries.sectionId]], [[entries.typeId]], [[entries.fieldId]]', function (string $sql) use ($rows) {
			$nested = !str_contains($sql, $this->quoteSql('NOT ([[entries.sectionId]] IS NULL)'));

			return array_values(array_filter(
				is_callable($rows) ? $rows($sql) : $rows,
				fn (array $row) => ($row['sectionId'] === null) === $nested,
			));
		});
	}

	/**
	 * Raw SQL of the counts of {@see FakeQueryService} run.
	 *
	 * @return string[]
	 */
	public function counts(): array
	{
		return $this->queriesMatching('[[hubElements]]');
	}

	/**
	 * The element query a query is built on, as a subquery.
	 */
	public function elementQueryIn(string $sql): ?ElementQuery
	{
		return preg_match('#/\* element query \d+ \*/#', $sql, $match) ? $this->findElementQuery($match[0]) : null;
	}

	/**
	 * Raw SQL of the queries run that contain the pattern.
	 *
	 * @return string[]
	 */
	public function queriesMatching(string $pattern): array
	{
		$pattern = $this->quoteSql($pattern);

		return array_values(array_filter($this->queries, fn (string $sql) => str_contains($sql, $pattern)));
	}

	/**
	 * @return string placeholder SQL the command finds the query by
	 */
	public function stashElementQuery(ElementQuery $query): string
	{
		$sql = sprintf(self::ELEMENT_QUERY, count($this->elementQueries));
		$this->elementQueries[$sql] = $query;

		return $sql;
	}

	public function findElementQuery(string $sql): ?ElementQuery
	{
		return $this->elementQueries[$sql] ?? null;
	}

	public function quoteValue($value)
	{
		return is_string($value) ? "'" . addslashes($value) . "'" : $value;
	}
}
