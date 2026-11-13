<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use SomehowDigital\Craft\Hub\helpers\Ids;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\LabelService;

/**
 * Adds and removes labels (`labelsAdd`, `labelsRemove`), keeping the items selected.
 *
 * @internal
 */
final class Labels extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly LabelService $labels,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return ['label' => Craft::t('hub', 'Labels'), 'icon' => 'tags', 'labels' => true, 'keep' => true];
	}

	public function isAvailable(): bool
	{
		$labels = $this->labels;

		return $labels->canApply($this->list) && ($labels->getAllLabels() !== [] || $labels->canManage());
	}

	public function apply(array $ids, array $params): array
	{
		$labels = $this->labels;
		$ids = $labels->existingIds($this->list, $ids);
		$labels->changeLabels($this->list, $ids, Ids::positive($params['labelsAdd'] ?? null), Ids::positive($params['labelsRemove'] ?? null));

		return $ids;
	}
}
