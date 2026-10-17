<?php

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseNumberConditionRule;
use craft\base\FieldInterface;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches the number of custom fields in the field layout of an entry type or volume.
 */
class FieldCountConditionRule extends BaseNumberConditionRule implements InfoConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('hub', 'Number of fields');
	}

	/**
	 * @return FieldInterface[]
	 */
	private function layoutFields(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): array
	{
		return match (true) {
			$info instanceof EntryTypeInfo => $info->entryType->getFieldLayout()->getCustomFields(),
			$info instanceof VolumeInfo => $info->volume->getFieldLayout()->getCustomFields(),
			default => [],
		};
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue(count($this->layoutFields($info)));
	}
}
