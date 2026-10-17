<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\base\ElementInterface;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ColumnService;

/**
 * Moves entries or files to the trash, showing what's deleted along with them first, see DeletionService::getPreview().
 */
class Delete extends BulkAction
{
	public function definition(): array
	{
		return [
			'label' => Craft::t('app', 'Delete'),
			'icon' => 'trash',
			'confirm' => $this->list === ColumnService::LIST_ENTRIES
				? Craft::t('hub', 'Are you sure you want to delete the selected entries?')
				: Craft::t('hub', 'Are you sure you want to delete the selected files?'),
			'preview' => true,
		];
	}

	public function isAvailable(): bool
	{
		return $this->list === ColumnService::LIST_ENTRIES
			? $this->hub()->access->canAct(Page::Entries, Hub::PERMISSION_DELETE_ENTRIES)
			: $this->hub()->access->canAct(Page::Assets, Hub::PERMISSION_DELETE_ASSETS);
	}

	public function apply(array $ids, array $params): array
	{
		$service = Craft::$app->getElements();

		return $this->succeeded(
			$this->hub()->bulk->getElements($this->list, $ids),
			fn (ElementInterface $element) => $service->canDelete($element) && $service->deleteElement($element),
		);
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		$preview = $this->hub()->deletion->getPreview($this->list, $ids, $page);

		return new BulkPreview('deletion-preview', $preview->total, ['preview' => $preview, 'pageInfo' => $preview->pageInfo]);
	}
}
