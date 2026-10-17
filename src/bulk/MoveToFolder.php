<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Asset;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Moves files to the folder picked (`folderId`), like Craft's own moving, skipping those whose filename the folder has
 * already.
 */
class MoveToFolder extends BulkAction
{
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
		return $this->hub()->access->canAct(Page::Assets, Hub::PERMISSION_MOVE_ASSETS);
	}

	public function apply(array $ids, array $params): array
	{
		$files = $this->hub()->files;
		$folder = $files->getFolderTargets()[$this->intParam($params, 'folderId')] ?? null;
		$service = Craft::$app->getAssets();

		return $folder === null ? [] : $this->succeeded(
			$this->hub()->bulk->getElementsOf($this->list, $ids, Asset::class),
			fn (Asset $asset) => $files->canMoveTo($asset, $folder) && $service->moveAsset($asset, $folder),
		);
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		$service = Craft::$app->getElements();
		$folders = $this->hub()->files->getFolderTargets();
		$movable = array_filter($this->hub()->bulk->getElementsOf($this->list, $ids, Asset::class), fn (Asset $asset) => $service->canSave($asset) && $service->canDelete($asset));

		return new BulkPreview('folder-preview', $folders !== [] ? count($movable) : 0, ['folders' => $folders]);
	}
}
