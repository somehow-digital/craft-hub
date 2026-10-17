<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\base\ElementInterface;
use craft\db\Command;
use craft\elements\db\ElementQuery;
use craft\events\PopulateElementEvent;
use PDO;

/**
 * A command answered by its {@see FakeConnection}, shaped like PDO would return the rows.
 *
 * @property FakeConnection $db
 */
class FakeCommand extends Command
{
	protected function queryInternal($method, $fetchMode = null)
	{
		$query = $this->db->findElementQuery($this->getSql());

		if ($query !== null) {
			$results = ElementQueries::resolve($query);

			// Counts and existence checks select a single value.
			if ($method === 'fetchColumn') {
				return count($results);
			}

			$rows = $query->asArray ? $results : $this->elementRows($query, $results);
		} else {
			$result = $this->db->resolve($this->getRawSql());

			if (!is_array($result)) {
				return $method === 'fetchColumn' ? $result : [[$result]];
			}

			$rows = array_values($result);
		}

		return match ($method) {
			'fetchAll' => $fetchMode === PDO::FETCH_COLUMN ? array_map(fn ($row) => is_array($row) ? reset($row) : $row, $rows) : $rows,
			'fetch' => $rows[0] ?? false,
			'fetchColumn' => isset($rows[0]) ? (is_array($rows[0]) ? reset($rows[0]) : $rows[0]) : false,
			default => null,
		};
	}

	public function execute()
	{
		$this->db->resolve($this->getRawSql());

		return 0;
	}

	/**
	 * Rows the query populates the given elements from, as the elements are handed to it when it populates them.
	 *
	 * @param ElementInterface[] $elements
	 * @return array<int,array<string,mixed>>
	 */
	private function elementRows(ElementQuery $query, array $elements): array
	{
		$query->on(ElementQuery::EVENT_BEFORE_POPULATE_ELEMENT, function (PopulateElementEvent $event) use ($elements) {
			$event->element = $elements[$event->row['fakeIndex']];
		});

		return array_map(fn (ElementInterface $element, int $index) => [
			'id' => $element->id,
			'siteId' => $element->siteId,
			'fakeIndex' => $index,
		], $elements, array_keys($elements));
	}
}
