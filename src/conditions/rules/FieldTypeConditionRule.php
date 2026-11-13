<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use SomehowDigital\Craft\Hub\base\FieldsProviderInterface;
use SomehowDigital\Craft\Hub\base\InfoConditionRuleInterface;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches the type of a field, among the types of the existing fields.
 *
 * @internal
 */
final class FieldTypeConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
{
	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly FieldsProviderInterface $structure,
		array $config = [],
	) {
		parent::__construct($config);
	}

	public function getLabel(): string
	{
		return Craft::t('app', 'Field Type');
	}

	/**
	 * @return list<array{value: string, label: string}|string>
	 */
	protected function options(): array
	{
		$options = [];
		foreach ($this->structure->getFields() as $info) {
			$options[$info->field::class] = ['value' => $info->field::class, 'label' => $info->getTypeName()];
		}

		usort($options, fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

		return $options;
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof FieldInfo ? $info->field::class : null);
	}
}
