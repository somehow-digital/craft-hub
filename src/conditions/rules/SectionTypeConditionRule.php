<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use SomehowDigital\Craft\Hub\base\InfoConditionRuleInterface;
use SomehowDigital\Craft\Hub\enums\SectionType;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches the type of a section.
 *
 * @internal
 */
final class SectionTypeConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('app', 'Section Type');
	}

	/**
	 * @return list<array{value: string, label: string}|string>
	 */
	protected function options(): array
	{
		return array_map(fn(SectionType $type): array => ['value' => $type->value, 'label' => $type->label()], SectionType::cases());
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof SectionInfo ? $info->section->type : null);
	}
}
