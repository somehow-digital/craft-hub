<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\Response;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use yii\base\Exception;
use yii\web\Response as YiiResponse;
use ZipArchive;

/**
 * Sends the files the user may view: a single file as it is and several in a zip archive, like Craft's asset download,
 * by volume and path, recording them in the activity log as a batch.
 */
class Download extends BulkAction implements SendsFile
{
	public function definition(): array
	{
		return ['label' => Craft::t('app', 'Download'), 'icon' => 'download', 'file' => true, 'preview' => true];
	}

	public function isAvailable(): bool
	{
		return $this->hub()->access->canAct(Page::Assets, Hub::PERMISSION_DOWNLOAD_ASSETS);
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		$assets = $this->hub()->bulk->getDownloadableFiles($ids);
		[$pageInfo, $shown] = (new Paginator($page))->items($assets);

		return new BulkPreview('download-preview', count($assets), [
			'assets' => $shown,
			'pageInfo' => $pageInfo,
			'size' => array_sum(array_map(fn (Asset $asset) => (int)$asset->size, $assets)),
			'volumes' => count(array_unique(array_map(fn (Asset $asset) => (int)$asset->volumeId, $assets))),
		]);
	}

	public function send(Response $response, array $ids, array $params): ?Response
	{
		$assets = $this->hub()->bulk->getDownloadableFiles($ids);

		if ($assets === []) {
			return null;
		}

		$activity = $this->hub()->activity;
		$activity->beginBatch(ActivityAction::Bulk, ActivitySubject::Asset, $this->definition()['label']);

		foreach ($assets as $asset) {
			$activity->record(ActivityAction::Downloaded, ActivitySubject::Asset, $asset->id, $asset->getUiLabel(), siteId: $asset->siteId);
		}

		$activity->endBatch();

		if (count($assets) === 1) {
			$asset = $assets[0];

			return $response->sendStreamAsFile($asset->getStream(), $asset->getFilename(), [
				'fileSize' => $asset->size,
				'mimeType' => $asset->getMimeType(),
			]);
		}

		$path = Craft::$app->getPath()->getTempPath() . '/' . StringHelper::UUID() . '.zip';
		$zip = new ZipArchive();

		if ($zip->open($path, ZipArchive::CREATE) !== true) {
			throw new Exception('Cannot create zip at ' . $path);
		}

		App::maxPowerCaptain();

		foreach ($assets as $asset) {
			$zip->addFromString($asset->getVolume()->name . '/' . $asset->getPath(), $asset->getContents());
		}

		$zip->close();
		$response->on(YiiResponse::EVENT_AFTER_SEND, fn () => FileHelper::unlink($path));

		return $response->sendFile($path, 'files.zip');
	}
}
