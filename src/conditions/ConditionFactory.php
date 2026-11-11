<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\conditions;

use Craft;
use craft\base\conditions\BaseCondition;
use craft\base\conditions\ConditionInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\models\SavedFilter;

/**
 * The conditions on what a kind of check lists: Craft's condition of the element type, or Hub's own of the items of a
 * structure page.
 *
 * @internal
 */
final class ConditionFactory
{
	/**
	 * Only the rules of the config are taken, so the condition is always one of the kind, and rules that aren't one of its
	 * rules are left out.
	 *
	 * @param array<string,mixed> $config the condition's config, as saved
	 */
	public static function create(CheckKind $kind, array $config = []): ConditionInterface
	{
		$condition = match ($kind) {
			CheckKind::Entries => Entry::createCondition(),
			CheckKind::Assets => Asset::createCondition(),
			CheckKind::Users => User::createCondition(),
			CheckKind::EntryTypes => Craft::createObject(EntryTypeCondition::class),
			CheckKind::Sections => Craft::createObject(SectionCondition::class),
			CheckKind::Fields => Craft::createObject(FieldCondition::class),
			CheckKind::Volumes => Craft::createObject(VolumeCondition::class),
		};

		$rules = $config['conditionRules'] ?? [];
		$condition->setConditionRules(is_array($rules) ? array_values(array_filter($rules, is_array(...))) : []);

		return $condition;
	}

	/**
	 * The condition of a filter, if there is one, set up as a builder within the filter's form, which posts its rules by the name.
	 */
	public static function forFilter(?SavedFilter $filter): ?ConditionInterface
	{
		if (!$filter instanceof SavedFilter) {
			return null;
		}

		$condition = self::create($filter->kind, $filter->condition ?? []);

		if ($condition instanceof BaseCondition) {
			$condition->name = 'condition';
			$condition->id = 'condition';
			$condition->mainTag = 'div';
		}

		return $condition;
	}
}
