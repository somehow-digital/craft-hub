<?php

namespace SomehowDigital\Craft\Hub\conditions;

use SomehowDigital\Craft\Hub\conditions\rules\DataKindConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\EntryCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\EntryTypeCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\FieldTypeConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\HandleConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\InstructionsConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\LayoutCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\LayoutOwnerConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\NameConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\SearchableConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\TranslationMethodConditionRule;

/**
 * A condition on fields, matched against what Hub knows about them, see {@see \SomehowDigital\Craft\Hub\models\FieldInfo}.
 */
class FieldCondition extends InfoCondition
{
	protected function selectableConditionRules(): array
	{
		return [
			NameConditionRule::class,
			HandleConditionRule::class,
			FieldTypeConditionRule::class,
			DataKindConditionRule::class,
			InstructionsConditionRule::class,
			SearchableConditionRule::class,
			TranslationMethodConditionRule::class,
			LayoutCountConditionRule::class,
			LayoutOwnerConditionRule::class,
			EntryCountConditionRule::class,
			EntryTypeCountConditionRule::class,
		];
	}
}
