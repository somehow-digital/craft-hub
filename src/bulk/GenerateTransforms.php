<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Asset;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\FileService;

/**
 * Queues generating the transforms picked by their handles (`transforms`), see FileService::generateTransforms(),
 * skipping files with all of them generated.
 *
 * @internal
 */
final class GenerateTransforms extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly FileService $files,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return $this->picked(
			Craft::t('hub', 'Generate transforms'),
			'wand-magic-sparkles',
			Craft::t('hub', 'Generate the transforms of “{name}”?'),
			Craft::t('hub', 'Generate the transforms of {count} selected files?'),
			Craft::t('hub', 'Checking which files can be transformed…'),
		);
	}

	public function isAvailable(): bool
	{
		return $this->access->canAct(Page::Assets, Permission::GenerateTransforms->value);
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$files = $this->files;
		$handles = is_array($params['transforms'] ?? null) ? array_map(fn(mixed $handle): string => Row::string(['handle' => $handle], 'handle'), $params['transforms']) : [];
		$transforms = array_values(array_intersect_key($files->getNamedTransforms(), array_flip($handles)));
		$assets = $this->bulk->getElementsOf($this->list, $ids, Asset::class);
		$transformable = $files->getTransformableFiles(array_map(fn(Asset $asset): int => (int) $asset->id, $assets));

		return $transforms === [] ? [] : $this->succeeded($transformable, fn(Asset $asset): bool => $files->generateTransforms($asset, $transforms) > 0);
	}

	public function preview(array $ids, int $page): BulkPreview
	{
		$files = $this->files;
		$transforms = $files->getNamedTransforms();

		return new BulkPreview('generate-transforms-preview', $transforms !== [] ? count($files->getTransformableFiles($ids)) : 0, ['transforms' => $transforms]);
	}
}
