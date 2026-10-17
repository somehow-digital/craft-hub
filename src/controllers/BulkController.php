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
 * Keeps the items selected in a list for its bulk actions (see BulkService::getSelection()), and applies the action
 * picked for them, or for a single item of the list, as picked from its context menu. The items the action was applied to
 * are unselected; those it was skipped for stay selected, to be reviewed again. Downloading files sends them instead, and
 * labeling keeps the items selected, so the list and its selection stay as they are.
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
		$path = (string)$request->getRequiredBodyParam('path');
		$action = (string)$request->getRequiredBodyParam('bulkAction');
		$actions = $bulk->getActions($list);

		if (!isset($actions[$action])) {
			throw new BadRequestHttpException('Unknown action.');
		}

		// A single item, picked from its context menu, in place of the selection.
		$item = (int)$request->getBodyParam('item');
		$selected = $bulk->getSelection($list, $path);
		$ids = $item > 0 ? [$item] : $selected;
		$label = $actions[$action]['label'];
		$keep = $actions[$action]['keep'] ?? false;

		if ($list === ColumnService::LIST_FILES && $action === BulkService::ACTION_DOWNLOAD) {
			return $this->download($bulk->getDownloadableFiles($ids), $label);
		}

		$applied = $action === BulkService::ACTION_LABELS
			? $bulk->applyLabels($list, $ids, $this->idsBodyParam('labelsAdd'), $this->idsBodyParam('labelsRemove'))
			: $bulk->apply($list, $action, $ids);
		$count = count($applied);

		if ($count === 0) {
			return $this->asFailure(Craft::t('hub', '“{action}” couldn’t be applied to the selected items.', ['action' => $label]));
		}

		if (!$keep) {
			$bulk->setSelection($list, $path, array_values(array_diff($selected, $applied)));
		}

		$skipped = count(array_diff($ids, $applied));
		$message = Craft::t('hub', '“{action}” applied to {count, plural, =1{one item} other{# items}}.', ['action' => $label, 'count' => $count]);

		if ($skipped > 0) {
			$message .= ' ' . Craft::t('hub', '{count, plural, =1{One item was} other{# items were}} skipped, as you may not change {count, plural, =1{it} other{them}} or {count, plural, =1{it} other{they}} couldn’t be saved, so {count, plural, =1{it stays} other{they stay}} selected.', ['count' => $skipped]);
		}

		// With nothing left to review, the list is shown again, with its kept filters; otherwise the page is shown again.
		$done = $skipped === 0 && $item === 0 && !$keep;

		return $this->asSuccess($message, ['count' => $count, 'skipped' => $skipped], $done ? $this->hubUrl($path) : null);
	}

	/**
	 * Changes the selection of a list on a page, in this order: clearing it, unselecting and selecting items by their IDs,
	 * then selecting or unselecting all items matching the filters the list was last shown with (`matching`: `add` or
	 * `remove`). A change that would select more than BulkService::SELECTION_LIMIT items is refused, keeping the
	 * selection as it was. Responds with the selection.
	 */
	public function actionSelect(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		/** @var BulkService $bulk */
		$bulk = Hub::getInstance()->bulk;
		$list = (string)$request->getRequiredBodyParam('list');
		$path = (string)$request->getRequiredBodyParam('path');

		// Lists without actions have nothing to select.
		if ($bulk->getActions($list) === []) {
			throw new BadRequestHttpException('Unknown list.');
		}

		$kept = $bulk->getSelection($list, $path);
		$ids = $request->getBodyParam('clear') ? [] : $kept;
		$ids = array_values(array_diff($ids, $this->idsBodyParam('remove')));
		$ids = [...$ids, ...$this->idsBodyParam('add')];

		$filter = $bulk->getRememberedFilter($list, $path);
		$matching = $request->getBodyParam('matching');

		if ($filter !== null && $matching === 'remove') {
			$ids = array_values(array_diff($ids, $bulk->getMatchingIds($list, $filter, $ids)));
		} elseif ($filter !== null && $matching === 'add') {
			// One more than the limit is enough to tell that it is exceeded.
			$ids = [...$ids, ...$bulk->getMatchingIds($list, $filter, limit: BulkService::SELECTION_LIMIT + 1)];
		}

		if (count(array_unique($ids)) > BulkService::SELECTION_LIMIT) {
			return $this->asJson([
				'ids' => $kept,
				'error' => Craft::t('hub', 'Up to {limit, number} items can be selected at once. Narrow down the filters, or apply an action to the selected items first.', ['limit' => BulkService::SELECTION_LIMIT]),
			]);
		}

		return $this->asJson(['ids' => $bulk->setSelection($list, $path, $ids)]);
	}

	/**
	 * Positive IDs of a body param holding a list of them.
	 *
	 * @return int[]
	 */
	private function idsBodyParam(string $name): array
	{
		$ids = Craft::$app->getRequest()->getBodyParam($name);

		return is_array($ids) ? array_values(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)) : [];
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
