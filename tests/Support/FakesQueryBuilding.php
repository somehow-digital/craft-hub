<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\elements\db\ElementQuery;

/**
 * Leaves element queries unprepared, as preparing them takes most of Craft, and lets the connection answer them instead.
 *
 * @property FakeConnection $db
 */
trait FakesQueryBuilding
{
	public function build($query, $params = [])
	{
		if ($query instanceof ElementQuery) {
			return [$this->db->stashElementQuery($query), $params];
		}

		return parent::build($query, $params);
	}
}
