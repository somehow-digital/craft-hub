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
 * Matches the types of the field layouts a field is placed in, among those of the existing fields.
 */
class LayoutOwnerConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('hub', 'Placed in');
	}

	protected function options(): array
	{
		$owners = [];
		foreach (Hub::getInstance()->structure->getFields() as $info) {
			foreach ($info->getOwnerTypes() as $owner) {
				$owners[$owner] = ['value' => $owner, 'label' => $owner];
			}
		}
		usort($owners, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

		return $owners;
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof FieldInfo ? $info->getOwnerTypes() : []);
	}
}
