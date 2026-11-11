<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\FieldInterface;
use SomehowDigital\Craft\Hub\base\InfoConditionRuleInterface;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use SomehowDigital\Craft\Hub\services\StructureService;

/**
 * Matches the custom fields in the field layout of an entry type or volume.
 *
 * @internal
 */
final class LayoutFieldsConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
{
	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly StructureService $structure,
		array $config = [],
	) {
		parent::__construct($config);
	}

	public function getLabel(): string
	{
		return Craft::t('hub', 'Fields in the layout');
	}

	/**
	 * @return list<array{value: string, label: string}>
	 */
	protected function options(): array
	{
		$options = [];
		foreach ($this->structure->getFields() as $info) {
			$options[] = ['value' => $info->field->uid ?? '', 'label' => Craft::t('site', $info->field->name ?? '')];
		}

		usort($options, fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

		return $options;
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue(array_map(fn(FieldInterface $field): string => (string) $field->uid, match (true) {
			$info instanceof EntryTypeInfo => $info->entryType->getFieldLayout()->getCustomFields(),
			$info instanceof VolumeInfo => $info->volume->getFieldLayout()->getCustomFields(),
			default => [],
		}));
	}
}
