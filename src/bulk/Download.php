<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\StringHelper;
use craft\services\Path;
use SomehowDigital\Craft\Hub\base\SendsFileInterface;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\models\FileDownload;
use SomehowDigital\Craft\Hub\models\Paginator;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\BulkService;
use yii\base\Exception;
use ZipArchive;

/**
 * Sends the files the user may view: a single file as it is and several in a zip archive, like Craft's asset download,
 * by volume and path, recording them in the activity log as a batch.
 *
 * @internal
 */
final class Download extends BaseBulkAction implements SendsFileInterface
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly ActivityService $activity,
		private readonly Path $craftPath,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return ['label' => Craft::t('app', 'Download'), 'icon' => 'download', 'file' => true, 'preview' => true];
	}

	public function isAvailable(): bool
	{
		return $this->access->canAct(Page::Assets, Permission::DownloadAssets->value);
	}

	public function preview(array $ids, int $page): BulkPreview
	{
		$assets = $this->bulk->getDownloadableFiles($ids);
		[$pageInfo, $shown] = (new Paginator($page))->items($assets);

		return new BulkPreview('download-preview', count($assets), [
			'assets' => $shown,
			'pageInfo' => $pageInfo,
			'size' => array_sum(array_map(fn(Asset $asset): int => (int) $asset->size, $assets)),
			'volumes' => count(array_unique(array_map(fn(Asset $asset): int => (int) $asset->volumeId, $assets))),
		]);
	}

	public function download(array $ids, array $params): ?FileDownload
	{
		$assets = $this->bulk->getDownloadableFiles($ids);

		if ($assets === []) {
			return null;
		}

		$activity = $this->activity;
		$activity->beginBatch(ActivityAction::Bulk, ActivitySubject::Asset, $this->definition()['label']);

		foreach ($assets as $asset) {
			$activity->record(ActivityAction::Downloaded, ActivitySubject::Asset, $asset->id, $asset->getUiLabel(), siteId: $asset->siteId);
		}

		$activity->endBatch();

		if (count($assets) === 1) {
			$asset = $assets[0];

			return FileDownload::stream($asset->getStream(), $asset->getFilename(), $asset->getMimeType(), $asset->size);
		}

		$path = $this->craftPath->getTempPath() . '/' . StringHelper::UUID() . '.zip';
		$zip = new ZipArchive();

		if ($zip->open($path, ZipArchive::CREATE) !== true) {
			throw new Exception('Cannot create zip at ' . $path);
		}

		App::maxPowerCaptain();

		foreach ($assets as $asset) {
			$zip->addFromString(($asset->getVolume()->name ?? '') . '/' . $asset->getPath(), $asset->getContents());
		}

		$zip->close();

		return FileDownload::temporaryFile($path, 'files.zip');
	}
}
