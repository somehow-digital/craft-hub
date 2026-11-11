<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseTextConditionRule;
use SomehowDigital\Craft\Hub\base\InfoConditionRuleInterface;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches the handle of an item.
 *
 * @internal
 */
final class HandleConditionRule extends BaseTextConditionRule implements InfoConditionRuleInterface
{
	use InfoItemTrait;

	public function getLabel(): string
	{
		return Craft::t('app', 'Handle');
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		return $this->matchValue((string) $this->item($info)->handle);
	}
}
