<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\base\ElementInterface;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\enums\ListType;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\DeletionService;

/**
 * Moves entries or files to the trash, showing what's deleted along with them first, see DeletionService::getPreview().
 *
 * @internal
 */
final class Delete extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly DeletionService $deletion,
		private readonly Elements $craftElements,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return [
			'label' => Craft::t('app', 'Delete'),
			'icon' => 'trash',
			'confirm' => $this->list === ListType::Entries->value
				? Craft::t('hub', 'Are you sure you want to delete the selected entries?')
				: Craft::t('hub', 'Are you sure you want to delete the selected files?'),
			'preview' => true,
		];
	}

	public function isAvailable(): bool
	{
		return $this->list === ListType::Entries->value
			? $this->access->canAct(Page::Entries, Permission::DeleteEntries->value)
			: $this->access->canAct(Page::Assets, Permission::DeleteAssets->value);
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$service = $this->craftElements;

		return $this->succeeded(
			$this->bulk->getElements($this->list, $ids),
			fn(ElementInterface $element): bool => $service->canDelete($element) && $service->deleteElement($element),
		);
	}

	public function preview(array $ids, int $page): BulkPreview
	{
		$preview = $this->deletion->getPreview($this->list, $ids, $page);

		return new BulkPreview('deletion-preview', $preview->total, ['preview' => $preview, 'pageInfo' => $preview->pageInfo]);
	}
}
