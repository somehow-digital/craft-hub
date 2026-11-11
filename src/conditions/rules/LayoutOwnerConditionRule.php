<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use SomehowDigital\Craft\Hub\base\InfoConditionRuleInterface;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use SomehowDigital\Craft\Hub\services\StructureService;

/**
 * Matches the types of the field layouts a field is placed in, among those of the existing fields.
 *
 * @internal
 */
final class LayoutOwnerConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
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
		return Craft::t('hub', 'Placed in');
	}

	/**
	 * @return list<array{value: string, label: string}|string>
	 */
	protected function options(): array
	{
		$owners = [];
		foreach ($this->structure->getFields() as $info) {
			foreach ($info->getOwnerTypes() as $owner) {
				$owners[$owner] = ['value' => $owner, 'label' => $owner];
			}
		}

		usort($owners, fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

		return $owners;
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof FieldInfo ? $info->getOwnerTypes() : []);
	}
}
