<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\base\ElementInterface;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ColumnService;

/**
 * Deletes trashed elements permanently, as far as the user may delete them.
 */
class DeletePermanently extends BulkAction
{
	public function definition(): array
	{
		return [
			'label' => Craft::t('app', 'Delete permanently'),
			'icon' => 'trash',
			'confirm' => $this->list === ColumnService::LIST_TRASHED_ENTRIES
				? Craft::t('hub', 'Are you sure you want to permanently delete the selected entries? This can’t be undone.')
				: Craft::t('hub', 'Are you sure you want to permanently delete the selected files? This can’t be undone.'),
		];
	}

	public function isAvailable(): bool
	{
		return $this->list === ColumnService::LIST_TRASHED_ENTRIES
			? $this->hub()->access->canAct(Page::Entries, Hub::PERMISSION_PURGE_ENTRIES)
			: $this->hub()->access->canAct(Page::Assets, Hub::PERMISSION_PURGE_ASSETS);
	}

	public function apply(array $ids, array $params): array
	{
		$service = Craft::$app->getElements();

		return $this->succeeded(
			$this->hub()->bulk->getElements($this->list, $ids),
			fn (ElementInterface $element) => $service->canDelete($element) && $service->deleteElement($element, true),
		);
	}
}
