<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\helpers\Json;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Label;
use SomehowDigital\Craft\Hub\services\LabelService;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Manages the labels, the same for all lists, for users who may manage them, and labels the items of the lists, for all
 * users with access.
 */
class LabelsController extends BaseController
{
	public function actionIndex(): Response
	{
		$this->requirePermission(Hub::PERMISSION_MANAGE_LABELS);

		/** @var LabelService $labels */
		$labels = Hub::getInstance()->labels;

		return $this->renderTemplate('hub/pages/labels/index', [
			'labels' => $labels->getAllLabels(),
			'usage' => $labels->getUsageCounts(),
		]);
	}

	/**
	 * Form of a new label, or of the label with the ID; a label that failed to save is passed back to it.
	 */
	public function actionEdit(?int $labelId = null, ?Label $label = null): Response
	{
		$this->requirePermission(Hub::PERMISSION_MANAGE_LABELS);

		if ($label === null && $labelId !== null) {
			$label = Hub::getInstance()->labels->getLabelById($labelId) ?? throw new NotFoundHttpException('Label not found.');
		}

		return $this->renderTemplate('hub/pages/labels/edit', [
			'label' => $label ?? new Label(),
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Hub::PERMISSION_MANAGE_LABELS);

		$request = Craft::$app->getRequest();
		/** @var LabelService $labels */
		$labels = Hub::getInstance()->labels;
		$id = $request->getBodyParam('labelId');
		$label = $id ? $labels->getLabelById((int)$id) ?? throw new NotFoundHttpException('Label not found.') : new Label();

		$label->name = (string)$request->getBodyParam('name', '');
		$label->icon = (string)$request->getBodyParam('icon', '') ?: null;
		// Craft's color select sends its blank option as `__blank__`.
		$color = (string)$request->getBodyParam('color', '');
		$label->color = $color !== '' && $color !== '__blank__' ? $color : null;

		if (!$labels->saveLabel($label)) {
			return $this->asModelFailure($label, Craft::t('hub', 'Couldn’t save the label.'), 'label');
		}

		return $this->asModelSuccess($label, Craft::t('hub', 'Label saved.'), 'label', redirect: $this->hubUrl('hub/manage/labels'));
	}

	public function actionDelete(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requirePermission(Hub::PERMISSION_MANAGE_LABELS);

		Hub::getInstance()->labels->deleteLabelById((int)Craft::$app->getRequest()->getRequiredBodyParam('id'));

		return $this->asSuccess();
	}

	/**
	 * Orders the labels by the JSON-encoded IDs sent by Craft's admin table.
	 */
	public function actionReorder(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requirePermission(Hub::PERMISSION_MANAGE_LABELS);

		$ids = Json::decode((string)Craft::$app->getRequest()->getRequiredBodyParam('ids'));
		Hub::getInstance()->labels->reorderLabels(is_array($ids) ? array_map('intval', $ids) : []);

		return $this->asSuccess();
	}

	/**
	 * Labels an item of a list with the labels sent, and only with them. Responds with the IDs of its labels.
	 */
	public function actionApply(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		/** @var LabelService $labels */
		$labels = Hub::getInstance()->labels;
		$list = (string)$request->getRequiredBodyParam('list');
		$id = (int)$request->getRequiredBodyParam('id');

		if (!isset(LabelService::LISTS[$list])) {
			throw new BadRequestHttpException('Unknown list.');
		}

		if (!$labels->itemExists($list, $id)) {
			throw new NotFoundHttpException('Item not found.');
		}

		$labelIds = $request->getBodyParam('labels');

		return $this->asJson([
			'labels' => $labels->setLabels($list, $id, is_array($labelIds) ? array_map('intval', $labelIds) : []),
		]);
	}

	/**
	 * Number of the items selected in a list on a page (see BulkService::getSelection()) labeled with each label, by its ID,
	 * and of all of them, for the labels to add to and remove from them.
	 */
	public function actionCounts(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		$list = (string)$request->getRequiredBodyParam('list');

		if (!isset(LabelService::LISTS[$list])) {
			throw new BadRequestHttpException('Unknown list.');
		}

		$ids = Hub::getInstance()->bulk->getSelection($list, (string)$request->getRequiredBodyParam('path'));

		return $this->asJson([
			'total' => count($ids),
			'counts' => (object)Hub::getInstance()->labels->countLabelsOf($list, $ids),
		]);
	}
}
