<?php

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseTextConditionRule;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches the name of an item.
 */
class NameConditionRule extends BaseTextConditionRule implements InfoConditionRuleInterface
{
	use InfoItem;

	public function getLabel(): string
	{
		return Craft::t('app', 'Name');
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue((string)$this->item($info)->name);
	}
}
