<?php

namespace SomehowDigital\Craft\Hub\conditions;

use craft\base\conditions\BaseCondition;
use SomehowDigital\Craft\Hub\conditions\rules\InfoConditionRuleInterface;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * A condition on the items of a structure page, matched against what Hub knows about them, as Craft has no conditions
 * of its own for them.
 */
abstract class InfoCondition extends BaseCondition
{
	/**
	 * Whether the item matches each rule.
	 */
	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		foreach ($this->getConditionRules() as $rule) {
			if ($rule instanceof InfoConditionRuleInterface && !$rule->matchInfo($info)) {
				return false;
			}
		}

		return true;
	}
}
