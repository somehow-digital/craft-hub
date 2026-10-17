<?php

namespace SomehowDigital\Craft\Hub\conditions;

use SomehowDigital\Craft\Hub\conditions\rules\ActivityConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\EntryCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\FieldCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\HandleConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\HasUrlsConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\LayoutFieldsConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\NameConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\RelationCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\TitleFieldConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\UsageConditionRule;

/**
 * A condition on entry types, matched against what Hub knows about them, see {@see
 * \SomehowDigital\Craft\Hub\models\EntryTypeInfo}.
 */
class EntryTypeCondition extends InfoCondition
{
	protected function selectableConditionRules(): array
	{
		return [
			NameConditionRule::class,
			HandleConditionRule::class,
			UsageConditionRule::class,
			HasUrlsConditionRule::class,
			EntryCountConditionRule::class,
			RelationCountConditionRule::class,
			ActivityConditionRule::class,
			TitleFieldConditionRule::class,
			LayoutFieldsConditionRule::class,
			FieldCountConditionRule::class,
		];
	}
}
