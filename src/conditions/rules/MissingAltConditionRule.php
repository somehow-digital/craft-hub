<?php

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseNumberConditionRule;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches the number of images of a volume without alternative text, on the selected sites.
 */
class MissingAltConditionRule extends BaseNumberConditionRule implements InfoConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('hub', 'Images without alternative text');
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof VolumeInfo ? $info->stats->missingAlt : 0);
	}
}
