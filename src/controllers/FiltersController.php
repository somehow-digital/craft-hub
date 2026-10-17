<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\View;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\helpers\Ids;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\models\SavedFilter;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The saved filters of the lists. The filters tab of the settings page manages those offered to everyone, for users who
 * may manage them: Hub's predefined filters can be disabled, reordered, renamed and given another threshold, custom ones
 * created, changed and deleted too, unless checks flag their items; changes are recorded in the activity log. The lists
 * save, rename and delete the users' own filters, for users who may view them.
 */
class FiltersController extends BaseController
{
	/** actions of the users' own filters, from the lists, rather than of the settings page */
	private const OWN_ACTIONS = ['save-own', 'rename-own', 'delete-own'];

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) {
			return false;
		}

		if (!in_array($action->id, self::OWN_ACTIONS, true)) {
			$this->requirePermission(Hub::PERMISSION_MANAGE_FILTERS);
		}

		return true;
	}

	/**
	 * The filters of each kind offered to everyone.
	 */
	public function actionIndex(): Response
	{
		$filters = Hub::getInstance()->filters;

		$kinds = [];
		foreach (CheckKind::cases() as $kind) {
			$kinds[$kind->value] = ['kind' => $kind, 'filters' => array_values($filters->getFilters($kind))];
		}

		return $this->renderTemplate('hub/pages/settings/filters', ['kinds' => $kinds]);
	}

	/**
	 * Form of a new custom filter of a kind, or of the filter with the ID; a filter that failed to save is passed back to it.
	 */
	public function actionEdit(?int $filterId = null, ?string $kind = null, ?SavedFilter $filter = null): Response
	{
		if ($filter === null && $filterId !== null) {
			$filter = Hub::getInstance()->filters->getFilterById($filterId) ?? throw new NotFoundHttpException('Filter not found.');
		}

		$filter ??= new SavedFilter(['kind' => CheckKind::tryFrom((string)$kind) ?? throw new NotFoundHttpException('Kind not found.')]);

		return $this->renderTemplate('hub/pages/settings/filter', ['filter' => $filter]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		$filters = Hub::getInstance()->filters;
		$id = $request->getBodyParam('filterId');

		if ($id) {
			$filter = $filters->getFilterById((int)$id) ?? throw new NotFoundHttpException('Filter not found.');
		} else {
			$kind = CheckKind::tryFrom((string)$request->getBodyParam('kind')) ?? throw new BadRequestHttpException('Unknown kind.');
			$filter = new SavedFilter(['kind' => $kind]);
		}

		$filter->name = (string)$request->getBodyParam('name', '');
		$filter->enabled = (bool)$request->getBodyParam('enabled', true);

		if ($filter->thresholdField !== null) {
			$threshold = $request->getBodyParam('threshold');
			$filter->threshold = is_numeric($threshold) ? (int)$threshold : null;
		}

		if (!$filter->isPredefined()) {
			$filter->description = (string)$request->getBodyParam('description', '');
			$filter->icon = (string)$request->getBodyParam('icon', '') ?: null;
			$filter->condition = $this->conditionConfig($filter->kind, $request->getBodyParam('condition'));
		}

		$isNew = $filter->id === null;

		if (!$filters->saveFilter($filter)) {
			return $this->asModelFailure($filter, Craft::t('hub', 'Couldn’t save the filter.'), 'filter');
		}

		$this->record($isNew ? ActivityAction::Created : ActivityAction::Updated, $filter);

		// Back to the filters, unless saved to continue editing it.
		$redirect = $this->getPostedRedirectUrl($filter) ?? $this->hubUrl('hub/manage/settings/filters');

		return $this->asModelSuccess($filter, Craft::t('hub', 'Filter saved.'), 'filter', redirect: $redirect);
	}

	/**
	 * Deletes a custom filter, from the filters table or from its form, which is sent back to the filters.
	 */
	public function actionDelete(): ?Response
	{
		$this->requirePostRequest();

		$filters = Hub::getInstance()->filters;
		$filter = $filters->getFilterById((int)Craft::$app->getRequest()->getRequiredBodyParam('id')) ?? throw new NotFoundHttpException('Filter not found.');

		if ($filter->isPredefined()) {
			throw new BadRequestHttpException('Predefined filters can’t be deleted.');
		}

		$checks = Hub::getInstance()->diagnostics->getChecksOfFilter($filter);

		if ($checks !== []) {
			return $this->asFailure(Craft::t('hub', 'The filter can’t be deleted, as checks flag its items: {checks}.', [
				'checks' => implode(', ', array_map(fn (CheckDefinition $check) => $check->name, $checks)),
			]));
		}

		if ($filters->deleteFilterById($filter->id)) {
			$this->record(ActivityAction::Deleted, $filter);
		}

		return $this->asSuccess(Craft::t('hub', 'Filter deleted.'), redirect: $this->hubUrl('hub/manage/settings/filters'));
	}

	/**
	 * Orders the filters of a kind by the JSON-encoded IDs sent by Craft's admin table.
	 */
	public function actionReorder(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$ids = Json::decode((string)Craft::$app->getRequest()->getRequiredBodyParam('ids'));
		Hub::getInstance()->filters->reorderFilters(Ids::positive($ids));

		return $this->asSuccess();
	}

	/**
	 * Enables or disables a filter, from the filters table.
	 */
	public function actionToggle(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		$filters = Hub::getInstance()->filters;
		$filter = $filters->getFilterById((int)$request->getRequiredBodyParam('id')) ?? throw new NotFoundHttpException('Filter not found.');
		$filter->enabled = (bool)$request->getRequiredBodyParam('enabled');

		if (!$filters->saveFilter($filter)) {
			return $this->asFailure(Craft::t('hub', 'Couldn’t save the filter.'));
		}

		$this->record($filter->enabled ? ActivityAction::Enabled : ActivityAction::Disabled, $filter);

		return $this->asSuccess($filter->enabled ? Craft::t('hub', 'Filter enabled.') : Craft::t('hub', 'Filter disabled.'), ['enabled' => $filter->enabled]);
	}

	/**
	 * Saves the filters a list shows as the user's own filter by the name, replacing those of their filter of the same name;
	 * responds with the saved filters of the list, rendered again.
	 */
	public function actionSaveOwn(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		$filters = Hub::getInstance()->filters;
		$kind = $this->ownKind();
		$userId = (int)Craft::$app->getUser()->getId();
		$name = trim((string)$request->getBodyParam('name', ''));
		$params = $filters->normalizeParams($request->getBodyParam('params'));

		$filter = $filters->getOwnFilterByName($kind, $userId, $name) ?? new SavedFilter(['kind' => $kind, 'userId' => $userId]);
		$filter->name = $name;
		$filter->params = $params;

		if (!$filters->saveFilter($filter)) {
			return $this->asFailure(implode(' ', $filter->getFirstErrors()) ?: Craft::t('hub', 'Couldn’t save the filter.'));
		}

		return $this->ownResponse($kind, Craft::t('hub', 'Filter saved.'), $filter);
	}

	/**
	 * Renames one of the user's own filters.
	 */
	public function actionRenameOwn(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		$filters = Hub::getInstance()->filters;
		$kind = $this->ownKind();
		$userId = (int)Craft::$app->getUser()->getId();
		$filter = $filters->getOwnFilterById((int)$request->getRequiredBodyParam('id'), $userId);

		if ($filter === null || $filter->kind !== $kind) {
			throw new NotFoundHttpException('Filter not found.');
		}

		$name = trim((string)$request->getBodyParam('name', ''));
		$other = $filters->getOwnFilterByName($kind, $userId, $name);

		if ($other !== null && $other->id !== $filter->id) {
			return $this->asFailure(Craft::t('hub', 'You already have a filter named “{name}”.', ['name' => $other->name]));
		}

		$filter->name = $name;

		if (!$filters->saveFilter($filter)) {
			return $this->asFailure(implode(' ', $filter->getFirstErrors()) ?: Craft::t('hub', 'Couldn’t save the filter.'));
		}

		return $this->ownResponse($kind, Craft::t('hub', 'Filter renamed.'), $filter);
	}

	/**
	 * Deletes one of the user's own filters.
	 */
	public function actionDeleteOwn(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$kind = $this->ownKind();
		$id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');

		if (!Hub::getInstance()->filters->deleteFilterById($id, (int)Craft::$app->getUser()->getId())) {
			throw new NotFoundHttpException('Filter not found.');
		}

		return $this->ownResponse($kind, Craft::t('hub', 'Filter deleted.'));
	}

	/**
	 * Kind of the list whose own filters are changed, which the user must be able to view.
	 */
	private function ownKind(): CheckKind
	{
		$kind = CheckKind::tryFrom((string)Craft::$app->getRequest()->getRequiredBodyParam('kind')) ?? throw new BadRequestHttpException('Unknown kind.');

		if (!Hub::getInstance()->access->canView($kind->page())) {
			throw new ForbiddenHttpException('User is not authorized to perform this action.');
		}

		return $kind;
	}

	/**
	 * The saved filters of the list, rendered again for the page it's on, with the custom filter it applies.
	 */
	private function ownResponse(CheckKind $kind, string $message, ?SavedFilter $filter = null): Response
	{
		$request = Craft::$app->getRequest();
		$path = $this->postedListPath() ?? $kind->path();
		$current = $request->getBodyParam('current');
		$current = is_string($current) && Hub::getInstance()->filters->getCustomFilter($kind, $current) !== null ? $current : null;

		return $this->asSuccess($message, [
			'id' => $filter?->id,
			'html' => $this->getView()->renderTemplate('hub/_components/saved-filters', [
				...$this->savedFilterVariables($kind, $path, $current),
				'hubSite' => Hub::getInstance()->siteScope->getSiteParam(),
			], View::TEMPLATE_MODE_CP),
		]);
	}

	/**
	 * Filters are recorded by their kind, as filters of several kinds may have the same name.
	 */
	private function record(ActivityAction $action, SavedFilter $filter): void
	{
		Hub::getInstance()->activity->record($action, ActivitySubject::Filter, $filter->id, $filter->getName(), ['kind' => $filter->kind->label()]);
	}
}
