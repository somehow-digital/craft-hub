<?php

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches the kinds of files a volume holds, among those of the existing assets.
 */
class FileKindConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('hub', 'File types');
	}

	protected function options(): array
	{
		return array_values(array_map(fn (FileKindInfo $kind) => ['value' => $kind->kind, 'label' => $kind->getLabel()], Hub::getInstance()->volumes->getKinds()));
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof VolumeInfo ? $info->getKindValues() : []);
	}
}
