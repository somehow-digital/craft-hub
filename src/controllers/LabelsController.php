<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\helpers\Json;
use SomehowDigital\Craft\Hub\enums\ListType;
use SomehowDigital\Craft\Hub\helpers\Ids;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Label;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\services\LabelService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Manages the labels, the same for all lists, for users who may manage them, and labels the items of the lists, for users
 * who may label them, while labels are enabled.
 */
class LabelsController extends BaseController
{
	/** paths of the pages of the lists, by their name, which link to them filtered by a label */
	/**
	 * The labels, along with whether they're enabled, for admins; settings that failed to save are passed back.
	 */
	public function actionIndex(?Settings $settings = null): Response
	{
		$this->requirePermission(Hub::PERMISSION_MANAGE_LABELS);

		$labels = Hub::getInstance()->labels;
		$usage = $labels->getUsageByList();

		$lists = [];
		foreach ($labels->getAllLabels() as $id => $label) {
			$lists[$id] = $this->usageOf($id, $usage[$id] ?? []);
		}

		return $this->renderTemplate('hub/pages/settings/labels', [
			'labels' => $labels->getAllLabels(),
			'lists' => $lists,
			'enabled' => $labels->isEnabled(),
			...$this->settingsVariables($settings),
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

		$label ??= new Label();
		$usage = $label->id !== null ? Hub::getInstance()->labels->getUsageByList()[$label->id] ?? [] : [];

		return $this->renderTemplate('hub/pages/settings/label', [
			'label' => $label,
			'lists' => $label->id !== null ? $this->usageOf($label->id, $usage) : [],
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Hub::PERMISSION_MANAGE_LABELS);

		$request = Craft::$app->getRequest();
		$labels = Hub::getInstance()->labels;
		$id = $request->getBodyParam('labelId');
		$label = $id ? $labels->getLabelById((int)$id) ?? throw new NotFoundHttpException('Label not found.') : new Label();

		$label->name = (string)$request->getBodyParam('name', '');
		$label->description = (string)$request->getBodyParam('description', '');
		$label->icon = (string)$request->getBodyParam('icon', '') ?: null;
		// Craft's color select sends its blank option as `__blank__`.
		$color = (string)$request->getBodyParam('color', '');
		$label->color = $color !== '' && $color !== '__blank__' ? $color : null;

		if (!$labels->saveLabel($label)) {
			return $this->asModelFailure($label, Craft::t('hub', 'Couldn’t save the label.'), 'label');
		}

		// Back to the labels, unless saved to continue editing it.
		$redirect = $this->getPostedRedirectUrl($label) ?? $this->hubUrl('hub/manage/settings/labels');

		return $this->asModelSuccess($label, Craft::t('hub', 'Label saved.'), 'label', redirect: $redirect);
	}

	/**
	 * Deletes a label, from the labels table or from its form, which is sent back to the labels.
	 */
	public function actionDelete(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Hub::PERMISSION_MANAGE_LABELS);

		Hub::getInstance()->labels->deleteLabelById((int)Craft::$app->getRequest()->getRequiredBodyParam('id'));

		return $this->asSuccess(Craft::t('hub', 'Label deleted.'), redirect: $this->hubUrl('hub/manage/settings/labels'));
	}

	/**
	 * Removes a label from all items labeled with it, keeping the label, from the labels table or from its form. Responds
	 * with the number of items it was removed from.
	 */
	public function actionClear(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Hub::PERMISSION_MANAGE_LABELS);

		$labels = Hub::getInstance()->labels;
		$id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
		$label = $labels->getLabelById($id) ?? throw new NotFoundHttpException('Label not found.');
		$count = $labels->clearLabelById($id);

		return $this->asSuccess(
			Craft::t('hub', 'Removed “{name}” from {count, plural, =1{one item} other{# items}}.', ['name' => $label->name, 'count' => $count]),
			['count' => $count],
		);
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
		Hub::getInstance()->labels->reorderLabels(Ids::positive($ids));

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
		$labels = Hub::getInstance()->labels;
		$list = (string)$request->getRequiredBodyParam('list');

		if (!Hub::getInstance()->labels->isEnabled()) {
			throw new ForbiddenHttpException('Labels are disabled.');
		}
		$id = (int)$request->getRequiredBodyParam('id');

		if (!isset(LabelService::LISTS[$list])) {
			throw new BadRequestHttpException('Unknown list.');
		}

		if (!$labels->canApply($list)) {
			throw new ForbiddenHttpException('User is not authorized to label these items.');
		}

		if (!$labels->itemExists($list, $id)) {
			throw new NotFoundHttpException('Item not found.');
		}

		$labelIds = $request->getBodyParam('labels');

		return $this->asJson([
			'labels' => $labels->setLabels($list, $id, Ids::positive($labelIds)),
		]);
	}

	/**
	 * Number of the items selected in a list on a page (see SelectionService::getSelection()) labeled with each label, by
	 * its ID, and of all of them, for the labels to add to and remove from them.
	 */
	public function actionCounts(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		$list = (string)$request->getRequiredBodyParam('list');

		if (!Hub::getInstance()->labels->isEnabled()) {
			throw new ForbiddenHttpException('Labels are disabled.');
		}

		if (!isset(LabelService::LISTS[$list])) {
			throw new BadRequestHttpException('Unknown list.');
		}

		// Only the items of lists the user may bulk-edit are selected.
		if (!Hub::getInstance()->labels->canApply($list) || !Hub::getInstance()->bulk->canSelect($list)) {
			throw new ForbiddenHttpException('User is not authorized to label these items.');
		}

		$ids = Hub::getInstance()->selection->getSelection($list, $this->requirePostedListPath());

		return $this->asJson([
			'total' => count($ids),
			'counts' => (object)Hub::getInstance()->labels->countLabelsOf($list, $ids),
		]);
	}

	/**
	 * Lists a label is used in, in their order, each with the number of its items labeled with it and the URL of the list
	 * filtered by it, if the user may view the list.
	 *
	 * @param array<string,int> $counts number of the items of each list labeled with it, by its name
	 * @return array<string,array{label:string,count:int,url:string|null}>
	 */
	private function usageOf(int $labelId, array $counts): array
	{
		$usage = [];
		foreach ($counts as $list => $count) {
			$type = ListType::tryFrom($list);

			if ($type?->path() !== null) {
				$usage[$list] = ['label' => $type->label(), 'count' => $count, 'url' => Hub::getInstance()->access->canViewList($list) ? $this->hubUrl($type->path(), ['label' => $labelId]) : null];
			}
		}

		return $usage;
	}
}
