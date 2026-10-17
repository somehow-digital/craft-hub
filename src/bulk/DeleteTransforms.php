<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Asset;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Deletes the generated transforms of files, which Craft generates again when they're next requested, listing them by file.
 */
class DeleteTransforms extends BulkAction
{
	public function definition(): array
	{
		return [
			'label' => Craft::t('hub', 'Delete transforms'),
			'icon' => 'crop',
			'confirm' => Craft::t('hub', 'Are you sure you want to delete the transforms of the selected files? They are generated again when they are next requested.'),
			'preview' => true,
			'titles' => [
				'one' => Craft::t('hub', 'Delete the transforms of “{name}”?'),
				'some' => Craft::t('hub', 'Delete the transforms of {count} selected files?'),
			],
		];
	}

	public function isAvailable(): bool
	{
		return $this->hub()->access->canAct(Page::Assets, Hub::PERMISSION_DELETE_TRANSFORMS);
	}

	public function apply(array $ids, array $params): array
	{
		$files = $this->hub()->files;
		$service = Craft::$app->getElements();
		$assets = $this->hub()->bulk->getElementsOf($this->list, $ids, Asset::class);
		$transforms = $files->getTransformsOf($assets);

		return $this->succeeded($assets, fn (Asset $asset) => $service->canSave($asset)
			&& $files->deleteTransforms($asset, array_column($transforms[$asset->id] ?? [], 'id')) > 0);
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		return $this->previewOf($ids, $page);
	}

	/**
	 * Preview of deleting the transforms of the files with the IDs, or only those with the transform IDs of them, as picked on
	 * the page of a file: the files by the page, along with their transforms, the totals covering all of them.
	 *
	 * @param int[] $ids
	 * @param int[]|null $transformIds
	 */
	public function previewOf(array $ids, int $page, ?array $transformIds = null): BulkPreview
	{
		[$assets, $transforms] = $this->hub()->files->getDeletableTransforms($ids, $transformIds);
		[$pageInfo, $shown] = (new Paginator($page))->items($assets);

		return new BulkPreview('transforms-preview', count($assets), [
			'assets' => $shown,
			'transforms' => $transforms,
			'pageInfo' => $pageInfo,
			'count' => array_sum(array_map('count', $transforms)),
			'skipped' => $transformIds === null ? count(array_unique($ids)) - count($assets) : 0,
		]);
	}
}
