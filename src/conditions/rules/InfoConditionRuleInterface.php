<?php

namespace SomehowDigital\Craft\Hub\conditions\rules;

use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * A rule of a condition on the items of a structure page, see {@see \SomehowDigital\Craft\Hub\conditions\InfoCondition}.
 */
interface InfoConditionRuleInterface
{
	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool;
}
