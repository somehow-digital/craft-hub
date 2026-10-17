<?php

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseNumberConditionRule;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches the total size of the assets of a volume in megabytes, on the selected sites.
 */
class SizeConditionRule extends BaseNumberConditionRule implements InfoConditionRuleInterface
{
	public int|float|null $step = null;

	public function getLabel(): string
	{
		return Craft::t('hub', 'Size (MB)');
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof VolumeInfo ? $info->stats->size / 1024 / 1024 : 0);
	}
}
