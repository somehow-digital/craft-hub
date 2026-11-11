<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Asset;
use craft\services\Assets;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\FileService;

/**
 * Moves files to the folder picked (`folderId`), like Craft's own moving, skipping those whose filename the folder has
 * already.
 *
 * @internal
 */
final class MoveToFolder extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly FileService $files,
		private readonly Elements $craftElements,
		private readonly Assets $craftAssets,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return $this->picked(
			Craft::t('hub', 'Move to folder'),
			'folder-open',
			Craft::t('hub', 'Move “{name}” to another folder?'),
			Craft::t('hub', 'Move {count} selected files to another folder?'),
			Craft::t('hub', 'Checking which folders they can be moved to…'),
		);
	}

	public function isAvailable(): bool
	{
		return $this->access->canAct(Page::Assets, Permission::MoveAssets->value);
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$files = $this->files;
		$folder = $files->getFolderTargets()[$this->intParam($params, 'folderId')] ?? null;
		$service = $this->craftAssets;

		return $folder === null ? [] : $this->succeeded(
			$this->bulk->getElementsOf($this->list, $ids, Asset::class),
			fn(Asset $asset): bool => $files->canMoveTo($asset, $folder) && $service->moveAsset($asset, $folder),
		);
	}

	public function preview(array $ids, int $page): BulkPreview
	{
		$service = $this->craftElements;
		$folders = $this->files->getFolderTargets();
		$movable = array_filter($this->bulk->getElementsOf($this->list, $ids, Asset::class), fn(Asset $asset): bool => $service->canSave($asset) && $service->canDelete($asset));

		return new BulkPreview('folder-preview', $folders !== [] ? count($movable) : 0, ['folders' => $folders]);
	}
}
