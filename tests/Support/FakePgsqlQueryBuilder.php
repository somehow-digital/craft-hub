<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\db\pgsql\QueryBuilder;

class FakePgsqlQueryBuilder extends QueryBuilder
{
	use FakesQueryBuilding;
}
