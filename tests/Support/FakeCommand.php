<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\base\ElementInterface;
use craft\db\Command;
use craft\elements\db\ElementQuery;
use craft\events\PopulateElementEvent;
use LogicException;
use PDO;

/**
 * A command answered by its {@see FakeConnection}, shaped like PDO would return the rows.
 *
 * @property FakeConnection $db
 */
class FakeCommand extends Command
{
	protected function queryInternal($method, $fetchMode = null): mixed
	{
		$query = $this->db->findElementQuery($this->getSql());

		if ($query !== null) {
			return $this->answerElementQuery($query, $method, $fetchMode);
		}

		$result = $this->db->resolve($this->getRawSql());

		if (!is_array($result)) {
			return $method === 'fetchColumn' ? $result : [[$result]];
		}

		return $this->shape($method, $fetchMode, array_values($result));
	}

	/**
	 * @param ElementQuery<array-key,covariant ElementInterface> $query
	 */
	private function answerElementQuery(ElementQuery $query, string $method, mixed $fetchMode): mixed
	{
		$results = ElementQueries::resolve($query);

		// Counts and existence checks select a single value.
		if ($method === 'fetchColumn') {
			return count($results);
		}

		return $this->shape($method, $fetchMode, $query->asArray ? $results : $this->elementRows($query, $results));
	}

	/**
	 * @param list<mixed> $rows
	 */
	private function shape(string $method, mixed $fetchMode, array $rows): mixed
	{
		return match ($method) {
			'fetchAll' => $fetchMode === PDO::FETCH_COLUMN ? array_map($this->firstColumn(...), $rows) : $rows,
			'fetch' => $rows[0] ?? false,
			'fetchColumn' => isset($rows[0]) ? $this->firstColumn($rows[0]) : false,
			default => null,
		};
	}

	private function firstColumn(mixed $row): mixed
	{
		return is_array($row) ? reset($row) : $row;
	}

	/**
	 * Number of rows the write affected: that responded to it, or none.
	 */
	public function execute(): int
	{
		$result = $this->db->resolve($this->getRawSql());

		return is_int($result) ? $result : 0;
	}

	/**
	 * Rows the query populates the given elements from, as the elements are handed to it when it populates them.
	 *
	 * @param ElementQuery<array-key,covariant ElementInterface> $query
	 * @param list<mixed> $results
	 * @return list<array<string,mixed>>
	 */
	private function elementRows(ElementQuery $query, array $results): array
	{
		$elements = array_values(array_filter($results, fn(mixed $element): bool => $element instanceof ElementInterface));

		// Craft groups the elements it eager-loads onto by their site.
		foreach ($elements as $element) {
			$element->siteId ??= 1;
		}

		$query->on(ElementQuery::EVENT_BEFORE_POPULATE_ELEMENT, function (PopulateElementEvent $event) use ($elements): void {
			$index = $event->row['fakeIndex'] ?? null;
			if (!is_int($index)) {
				throw new LogicException('No fake index in the row.');
			}

			$event->element = $elements[$index];
		});

		return array_map(fn(ElementInterface $element, int $index): array => [
			'id' => $element->id,
			'siteId' => $element->siteId,
			'fakeIndex' => $index,
		], $elements, array_keys($elements));
	}
}
