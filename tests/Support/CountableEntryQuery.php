<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\elements\db\EntryQuery;

class CountableEntryQuery extends EntryQuery
{
	use CountsFakeResults;
}
