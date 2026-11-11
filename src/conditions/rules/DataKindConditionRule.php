<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use SomehowDigital\Craft\Hub\base\InfoConditionRuleInterface;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches whether a field holds nested entries, relations or plain values.
 *
 * @internal
 */
final class DataKindConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('hub', 'Data');
	}

	/**
	 * @return list<array{value: string, label: string}|string>
	 */
	protected function options(): array
	{
		return array_map(fn(DataKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], DataKind::cases());
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof FieldInfo ? $info->getDataKind()->value : null);
	}
}
