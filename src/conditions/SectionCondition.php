<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\conditions;

use SomehowDigital\Craft\Hub\conditions\rules\ActivityConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\EntryCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\EntryTypeCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\HandleConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\HasUrlsConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\NameConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\ReferenceCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\SectionTypeConditionRule;

/**
 * A condition on sections, matched against what Hub knows about them, see {@see
 * \SomehowDigital\Craft\Hub\models\SectionInfo}.
 *
 * @internal
 */
final class SectionCondition extends BaseInfoCondition
{
	/**
	 * @return list<array{class: string}|string>
	 */
	protected function selectableConditionRules(): array
	{
		return [
			NameConditionRule::class,
			HandleConditionRule::class,
			SectionTypeConditionRule::class,
			HasUrlsConditionRule::class,
			EntryCountConditionRule::class,
			EntryTypeCountConditionRule::class,
			ReferenceCountConditionRule::class,
			ActivityConditionRule::class,
		];
	}
}
