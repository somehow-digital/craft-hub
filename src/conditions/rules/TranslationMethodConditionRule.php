<?php

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\Field;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches how the values of a field are translated.
 */
class TranslationMethodConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('app', 'Translation Method');
	}

	protected function options(): array
	{
		return [
			['value' => Field::TRANSLATION_METHOD_NONE, 'label' => Craft::t('app', 'Not translatable')],
			['value' => Field::TRANSLATION_METHOD_SITE, 'label' => Craft::t('app', 'Translate for each site')],
			['value' => Field::TRANSLATION_METHOD_SITE_GROUP, 'label' => Craft::t('app', 'Translate for each site group')],
			['value' => Field::TRANSLATION_METHOD_LANGUAGE, 'label' => Craft::t('app', 'Translate for each language')],
			['value' => Field::TRANSLATION_METHOD_CUSTOM, 'label' => Craft::t('app', 'Custom…')],
		];
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof FieldInfo ? $info->field->translationMethod : null);
	}
}
