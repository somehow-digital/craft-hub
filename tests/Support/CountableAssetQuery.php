<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\elements\db\AssetQuery;

class CountableAssetQuery extends AssetQuery
{
	use CountsFakeResults;
}
