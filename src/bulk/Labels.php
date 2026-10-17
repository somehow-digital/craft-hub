<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use SomehowDigital\Craft\Hub\helpers\Ids;

/**
 * Adds and removes labels (`labelsAdd`, `labelsRemove`), keeping the items selected.
 */
class Labels extends BulkAction
{
	public function definition(): array
	{
		return ['label' => Craft::t('hub', 'Labels'), 'icon' => 'tags', 'labels' => true, 'keep' => true];
	}

	public function isAvailable(): bool
	{
		$labels = $this->hub()->labels;

		return $labels->canApply($this->list) && ($labels->getAllLabels() !== [] || $labels->canManage());
	}

	public function apply(array $ids, array $params): array
	{
		$labels = $this->hub()->labels;
		$ids = $labels->existingIds($this->list, $ids);
		$labels->changeLabels($this->list, $ids, Ids::positive($params['labelsAdd'] ?? null), Ids::positive($params['labelsRemove'] ?? null));

		return $ids;
	}
}
