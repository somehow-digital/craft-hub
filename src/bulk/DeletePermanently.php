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

/**
 * Deletes trashed elements permanently, as far as the user may delete them.
 *
 * @internal
 */
final class DeletePermanently extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly Elements $craftElements,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return [
			'label' => Craft::t('app', 'Delete permanently'),
			'icon' => 'trash',
			'confirm' => $this->list === ListType::TrashedEntries->value
				? Craft::t('hub', 'Are you sure you want to permanently delete the selected entries? This can’t be undone.')
				: Craft::t('hub', 'Are you sure you want to permanently delete the selected files? This can’t be undone.'),
		];
	}

	public function isAvailable(): bool
	{
		return $this->list === ListType::TrashedEntries->value
			? $this->access->canAct(Page::Entries, Permission::PurgeEntries->value)
			: $this->access->canAct(Page::Assets, Permission::PurgeAssets->value);
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$service = $this->craftElements;

		return $this->succeeded(
			$this->bulk->getElements($this->list, $ids),
			fn(ElementInterface $element): bool => $service->canDelete($element) && $service->deleteElement($element, true),
		);
	}
}
