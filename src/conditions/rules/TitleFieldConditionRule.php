<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;
use SomehowDigital\Craft\Hub\base\InfoConditionRuleInterface;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches whether entries of an entry type have a title field.
 *
 * @internal
 */
final class TitleFieldConditionRule extends BaseLightswitchConditionRule implements InfoConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('hub', 'Show the Title field');
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof EntryTypeInfo && $info->entryType->hasTitleField);
	}
}
