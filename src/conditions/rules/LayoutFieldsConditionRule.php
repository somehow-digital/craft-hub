<?php

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\FieldInterface;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches the custom fields in the field layout of an entry type or volume.
 */
class LayoutFieldsConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('hub', 'Fields in the layout');
	}

	protected function options(): array
	{
		$options = [];
		foreach (Hub::getInstance()->structure->getFields() as $info) {
			$options[] = ['value' => $info->field->uid, 'label' => Craft::t('site', $info->field->name)];
		}
		usort($options, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

		return $options;
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue(array_map(fn (FieldInterface $field) => (string)$field->uid, match (true) {
			$info instanceof EntryTypeInfo => $info->entryType->getFieldLayout()->getCustomFields(),
			$info instanceof VolumeInfo => $info->volume->getFieldLayout()->getCustomFields(),
			default => [],
		}));
	}
}
