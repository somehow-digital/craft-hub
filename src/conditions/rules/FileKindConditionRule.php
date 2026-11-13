<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use SomehowDigital\Craft\Hub\base\FileKindsProviderInterface;
use SomehowDigital\Craft\Hub\base\InfoConditionRuleInterface;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches the kinds of files a volume holds, among those of the existing assets.
 *
 * @internal
 */
final class FileKindConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
{
	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly FileKindsProviderInterface $volumes,
		array $config = [],
	) {
		parent::__construct($config);
	}

	public function getLabel(): string
	{
		return Craft::t('hub', 'File types');
	}

	/**
	 * @return list<array{value: string, label: string}|string>
	 */
	protected function options(): array
	{
		return array_values(array_map(fn(FileKindInfo $kind): array => ['value' => $kind->kind, 'label' => $kind->getLabel()], $this->volumes->getKinds()));
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue($info instanceof VolumeInfo ? $info->getKindValues() : []);
	}
}
