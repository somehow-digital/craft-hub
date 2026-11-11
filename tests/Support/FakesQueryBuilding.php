<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\elements\db\ElementQuery;

/**
 * Leaves element queries unprepared, as preparing them takes most of Craft, and lets the connection answer them instead.
 *
 * @property FakeConnection $db
 */
trait FakesQueryBuilding
{
	/**
	 * @param array<mixed> $params
	 * @return array<mixed>
	 */
	public function build($query, $params = [])
	{
		if ($query instanceof ElementQuery) {
			return [
				$this->db->stashElementQuery($query),
				$params,
			];
		}

		return parent::build($query, $params);
	}
}
