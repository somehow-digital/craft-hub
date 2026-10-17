<?php

namespace SomehowDigital\Craft\Hub\conditions;

use SomehowDigital\Craft\Hub\conditions\rules\AssetCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\FieldCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\FileKindConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\HandleConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\HasUrlsConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\LastUploadConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\LayoutFieldsConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\MissingAltConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\NameConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\ReferenceCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\SizeConditionRule;

/**
 * A condition on volumes, matched against what Hub knows about them, see {@see \SomehowDigital\Craft\Hub\models\VolumeInfo}.
 */
class VolumeCondition extends InfoCondition
{
	protected function selectableConditionRules(): array
	{
		return [
			NameConditionRule::class,
			HandleConditionRule::class,
			HasUrlsConditionRule::class,
			AssetCountConditionRule::class,
			FileKindConditionRule::class,
			SizeConditionRule::class,
			MissingAltConditionRule::class,
			ReferenceCountConditionRule::class,
			LastUploadConditionRule::class,
			LayoutFieldsConditionRule::class,
			FieldCountConditionRule::class,
		];
	}
}
