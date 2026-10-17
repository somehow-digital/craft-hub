<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use yii\base\Exception;
use yii\web\BadRequestHttpException;
use yii\web\Response;

use ZipArchive;

/**
 * Applies the action picked from a list's bulk actions menu to the items selected in it, then goes back to the list.
 * Downloading files sends them instead, so the list stays as it is.
 */
class BulkController extends BaseController
{
	public function actionApply(): ?Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		/** @var BulkService $bulk */
		$bulk = Hub::getInstance()->bulk;
		$list = (string)$request->getRequiredBodyParam('list');
		$action = (string)$request->getRequiredBodyParam('bulkAction');
		$actions = $bulk->getActions($list);

		if (!isset($actions[$action])) {
			throw new BadRequestHttpException('Unknown action.');
		}

		$ids = $request->getBodyParam('ids');
		$ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0))) : [];

		if ($list === ColumnService::LIST_FILES && $action === BulkService::ACTION_DOWNLOAD) {
			return $this->download($bulk->getDownloadableFiles($ids), $actions[$action]['label']);
		}

		$count = $bulk->apply($list, $action, $ids);
		$skipped = count($ids) - $count;
		$label = $actions[$action]['label'];

		if ($count === 0) {
			return $this->asFailure(Craft::t('hub', '“{action}” couldn’t be applied to the selected items.', ['action' => $label]));
		}

		$message = Craft::t('hub', '“{action}” applied to {count, plural, =1{one item} other{# items}}.', ['action' => $label, 'count' => $count]);

		if ($skipped > 0) {
			$message .= ' ' . Craft::t('hub', '{count, plural, =1{One item was} other{# items were}} skipped, as you may not change {count, plural, =1{it} other{them}} or {count, plural, =1{it} other{they}} couldn’t be saved.', ['count' => $skipped]);
		}

		return $this->asSuccess($message, ['count' => $count, 'skipped' => $skipped]);
	}

	/**
	 * Sends a single file as it is and several in a zip archive, like Craft's asset download, by volume and path.
	 *
	 * @param Asset[] $assets
	 */
	private function download(array $assets, string $label): ?Response
	{
		if ($assets === []) {
			return $this->asFailure(Craft::t('hub', '“{action}” couldn’t be applied to the selected items.', ['action' => $label]));
		}

		if (count($assets) === 1) {
			$asset = $assets[0];

			return $this->response->sendStreamAsFile($asset->getStream(), $asset->getFilename(), [
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
		$this->response->on(Response::EVENT_AFTER_SEND, fn () => FileHelper::unlink($path));

		return $this->response->sendFile($path, 'files.zip');
	}
}
