<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Asset;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Queues generating the transforms picked by their handles (`transforms`), see FileService::generateTransforms(), skipping files
 * with all of them generated.
 */
class GenerateTransforms extends BulkAction
{
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
		return $this->hub()->access->canAct(Page::Assets, Hub::PERMISSION_GENERATE_TRANSFORMS);
	}

	public function apply(array $ids, array $params): array
	{
		$files = $this->hub()->files;
		$handles = is_array($params['transforms'] ?? null) ? array_map('strval', $params['transforms']) : [];
		$transforms = array_values(array_intersect_key($files->getNamedTransforms(), array_flip($handles)));
		$assets = $this->hub()->bulk->getElementsOf($this->list, $ids, Asset::class);
		$transformable = $files->getTransformableFiles(array_map(fn (Asset $asset) => (int)$asset->id, $assets));

		return $transforms === [] ? [] : $this->succeeded($transformable, fn (Asset $asset) => $files->generateTransforms($asset, $transforms) > 0);
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		$files = $this->hub()->files;
		$transforms = $files->getNamedTransforms();

		return new BulkPreview('generate-transforms-preview', $transforms !== [] ? count($files->getTransformableFiles($ids)) : 0, ['transforms' => $transforms]);
	}
}
