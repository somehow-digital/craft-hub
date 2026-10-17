<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\base\ElementInterface;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ColumnService;

/**
 * Restores trashed elements like Craft's restore action, as far as the user may save them. Files whose file wasn't kept
 * can't be restored, see Asset::beforeRestore().
 */
class Restore extends BulkAction
{
	public function definition(): array
	{
		return ['label' => Craft::t('app', 'Restore'), 'icon' => 'trash-arrow-up', 'unless' => 'unrestorable'];
	}

	public function isAvailable(): bool
	{
		return $this->list === ColumnService::LIST_TRASHED_ENTRIES
			? $this->hub()->access->canAct(Page::Entries, Hub::PERMISSION_RESTORE_ENTRIES)
			: $this->hub()->access->canAct(Page::Assets, Hub::PERMISSION_RESTORE_ASSETS);
	}

	public function apply(array $ids, array $params): array
	{
		$service = Craft::$app->getElements();

		return $this->succeeded(
			$this->hub()->bulk->getElements($this->list, $ids),
			fn (ElementInterface $element) => $service->canSave($element) && $service->restoreElement($element),
		);
	}
}
