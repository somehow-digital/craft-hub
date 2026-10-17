<?php

namespace SomehowDigital\Craft\Hub\conditions\rules;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Matches how recently a person edited the entries of a section or entry type, see the general settings.
 */
class ActivityConditionRule extends BaseMultiSelectConditionRule implements InfoConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('hub', 'Activity');
	}

	protected function options(): array
	{
		return [
			['value' => EntryStatistics::ACTIVITY_RECENT, 'label' => Craft::t('hub', 'Recently edited')],
			['value' => EntryStatistics::ACTIVITY_STALE, 'label' => Craft::t('hub', 'Stale')],
			['value' => EntryStatistics::ACTIVITY_NONE, 'label' => Craft::t('hub', 'No entries')],
		];
	}

	public function matchInfo(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): bool
	{
		$stats = $info instanceof VolumeInfo ? new EntryStatistics() : $info->stats;

		// Sections and entry types are stale by the threshold of their stale filter, the entries of fields by that of entries.
		$months = Hub::getInstance()->filters->getThreshold(match (true) {
			$info instanceof SectionInfo => CheckKind::Sections,
			$info instanceof EntryTypeInfo => CheckKind::EntryTypes,
			default => CheckKind::Entries,
		}, 'stale');

		return $this->matchValue($stats->getActivity($months));
	}
}
