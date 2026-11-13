<?php

declare(strict_types=1);

use SomehowDigital\Craft\Hub\conditions\ConditionFactory;
use SomehowDigital\Craft\Hub\conditions\EntryTypeCondition;
use SomehowDigital\Craft\Hub\conditions\FieldCondition;
use SomehowDigital\Craft\Hub\conditions\rules\HandleConditionRule;
use SomehowDigital\Craft\Hub\conditions\SectionCondition;
use SomehowDigital\Craft\Hub\conditions\VolumeCondition;
use SomehowDigital\Craft\Hub\enums\CheckKind;

it('creates the condition of a kind of check with the rules of its config', function (CheckKind $kind, string $class): void {
	$condition = ConditionFactory::create($kind, ['conditionRules' => [['class' => HandleConditionRule::class, 'operator' => '=', 'value' => 'news'], 'invalid']]);

	expect($condition instanceof $class)->toBeTrue()
		->and($condition->getConditionRules())->toHaveCount(1)
		->and(ConditionFactory::create($kind, ['conditionRules' => 'invalid'])->getConditionRules())->toBe([]);
})->with([
	[CheckKind::EntryTypes, EntryTypeCondition::class],
	[CheckKind::Sections, SectionCondition::class],
	[CheckKind::Fields, FieldCondition::class],
	[CheckKind::Volumes, VolumeCondition::class],
]);
