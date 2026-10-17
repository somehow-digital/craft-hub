<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\helpers\Ids;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\SelectionService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Keeps the items selected in a list for its bulk actions (see SelectionService::getSelection()), and applies the action
 * picked for them, or for a single item of the list, as picked from its context menu. The items the action was applied to
 * are unselected; those it was skipped for stay selected, to be reviewed again. Downloading files and exporting items send
 * them instead, and labeling keeps the items selected, so the list and its selection stay as they are. Deleting,
 * downloading and exporting are previewed first, exporting picking the exporter and format there, as are the actions
 * picking what they change the items to, like the section entries are moved to. Only users who may bulk-edit a list select its items; others apply the actions to a single item.
 */
class BulkController extends BaseController
{
	public function actionApply(): ?Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		$bulk = Hub::getInstance()->bulk;
		$list = (string)$request->getRequiredBodyParam('list');
		$path = $this->requirePostedListPath();
		$action = (string)$request->getRequiredBodyParam('bulkAction');
		$actions = $bulk->getActions($list);

		if (!isset($actions[$action])) {
			throw new BadRequestHttpException('Unknown action.');
		}

		// A single item, picked from its context menu, in place of the selection.
		$item = (int)$request->getBodyParam('item');
		$this->requireSelectable($list, $item);
		$selected = Hub::getInstance()->selection->getSelection($list, $path);
		$ids = $item > 0 ? [$item] : $selected;
		$label = $actions[$action]['label'];
		$keep = $actions[$action]['keep'] ?? false;

		if ($actions[$action]['file'] ?? false) {
			return $bulk->send($list, $action, $this->response, $ids, $request->getBodyParams())
				?? $this->asFailure(Craft::t('hub', '“{action}” couldn’t be applied to the selected items.', ['action' => $label]));
		}

		$applied = $bulk->apply($list, $action, $ids, $request->getBodyParams());
		$count = count($applied);

		if ($count === 0) {
			return $this->asFailure(Craft::t('hub', '“{action}” couldn’t be applied to the selected items.', ['action' => $label]));
		}

		if (!$keep) {
			Hub::getInstance()->selection->setSelection($list, $path, array_values(array_diff($selected, $applied)));
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
	 * Previews what an action takes along for the selection of a list, or a single item of it, as HTML (see
	 * BulkAction::preview()), listing the items by the page asked for (`page`), the totals covering all of them. Responds with
	 * the number of items the action applies to, so nothing is offered without any.
	 */
	public function actionPreview(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		$hub = Hub::getInstance();
		$list = (string)$request->getRequiredBodyParam('list');
		$action = (string)$request->getBodyParam('bulkAction', BulkService::ACTION_DELETE);

		if (!($hub->bulk->getActions($list)[$action]['preview'] ?? false)) {
			throw new BadRequestHttpException('Unknown action.');
		}

		$item = (int)$request->getBodyParam('item');
		$this->requireSelectable($list, $item);
		$ids = $item > 0 ? [$item] : $hub->selection->getSelection($list, $this->requirePostedListPath());
		$page = (int)$request->getBodyParam('page', 1);
		$preview = $hub->bulk->preview($list, $action, $ids, $page);

		if ($preview === null) {
			throw new BadRequestHttpException('Unknown action.');
		}

		return $this->renderPreview($preview, count(array_unique($ids)));
	}

	/**
	 * Changes the selection of a list on a page, in this order: clearing it, unselecting and selecting items by their IDs,
	 * then selecting or unselecting all items matching the filters the list was last shown with (`matching`: `add` or
	 * `remove`). A change that would select more than SelectionService::LIMIT items is refused, keeping the
	 * selection as it was. Responds with the selection.
	 */
	public function actionSelect(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		$bulk = Hub::getInstance()->bulk;
		$list = (string)$request->getRequiredBodyParam('list');
		$path = $this->requirePostedListPath();

		// Lists without actions have nothing to select.
		if ($bulk->getActions($list) === []) {
			throw new BadRequestHttpException('Unknown list.');
		}

		$this->requireSelectable($list);

		$kept = Hub::getInstance()->selection->getSelection($list, $path);
		$ids = $request->getBodyParam('clear') ? [] : $kept;
		$ids = array_values(array_diff($ids, Ids::positive($request->getBodyParam('remove'))));
		$ids = [...$ids, ...Ids::positive($request->getBodyParam('add'))];

		$filter = Hub::getInstance()->selection->getRememberedFilter($list, $path);
		$matching = $request->getBodyParam('matching');

		if ($filter !== null && $matching === 'remove') {
			$ids = array_values(array_diff($ids, $bulk->getMatchingIds($list, $filter, $ids)));
		} elseif ($filter !== null && $matching === 'add') {
			// One more than the limit is enough to tell that it is exceeded.
			$ids = [...$ids, ...$bulk->getMatchingIds($list, $filter, limit: SelectionService::LIMIT + 1)];
		}

		if (count(array_unique($ids)) > SelectionService::LIMIT) {
			return $this->asJson([
				'ids' => $kept,
				'error' => Craft::t('hub', 'Up to {limit, number} items can be selected at once. Narrow down the filters, or apply an action to the selected items first.', ['limit' => SelectionService::LIMIT]),
			]);
		}

		return $this->asJson(['ids' => Hub::getInstance()->selection->setSelection($list, $path, $ids)]);
	}

	/**
	 * Requires the user to be able to select several items of a list, unless a single item is picked from its context menu.
	 *
	 * @throws ForbiddenHttpException
	 */
	private function requireSelectable(string $list, int $item = 0): void
	{
		if ($item <= 0 && !Hub::getInstance()->bulk->canSelect($list)) {
			throw new ForbiddenHttpException('User is not authorized to bulk-edit these items.');
		}
	}
}
