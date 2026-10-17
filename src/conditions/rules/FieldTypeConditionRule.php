<?php

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches the type of a field, among the types of the existing fields.
 */
class FieldTypeConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('app', 'Field Type');
	}

	protected function options(): array
	{
		$options = [];
		foreach (Hub::getInstance()->structure->getFields() as $info) {
			$options[$info->field::class] = ['value' => $info->field::class, 'label' => $info->getTypeName()];
		}
		usort($options, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

		return $options;
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof FieldInfo ? $info->field::class : null);
	}
}
