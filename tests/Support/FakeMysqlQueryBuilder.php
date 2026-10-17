<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\db\mysql\QueryBuilder;

class FakeMysqlQueryBuilder extends QueryBuilder
{
	use FakesQueryBuilding;
}
