<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Asset;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\models\Paginator;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\FileService;

/**
 * Deletes the generated transforms of files, which Craft generates again when they're next requested, listing them by file.
 *
 * @internal
 */
final class DeleteTransforms extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly FileService $files,
		private readonly Elements $craftElements,
	) {
		parent::__construct($list, $access);
	}

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
		return $this->access->canAct(Page::Assets, Permission::DeleteTransforms->value);
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$files = $this->files;
		$service = $this->craftElements;
		$assets = $this->bulk->getElementsOf($this->list, $ids, Asset::class);
		$transforms = $files->getTransformsOf($assets);

		return $this->succeeded($assets, fn(Asset $asset): bool => $service->canSave($asset)
			&& $files->deleteTransforms($asset, array_map(fn(array $transform): int => Row::int($transform, 'id'), $transforms[(int) $asset->id] ?? [])) > 0);
	}

	public function preview(array $ids, int $page): BulkPreview
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
		[$assets, $transforms] = $this->files->getDeletableTransforms($ids, $transformIds);
		[$pageInfo, $shown] = (new Paginator($page))->items($assets);

		return new BulkPreview('transforms-preview', count($assets), [
			'assets' => $shown,
			'transforms' => $transforms,
			'pageInfo' => $pageInfo,
			'count' => array_sum(array_map(count(...), $transforms)),
			'skipped' => $transformIds === null ? count(array_unique($ids)) - count($assets) : 0,
		]);
	}
}
