<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\ActivityGroup;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\ActivityFilter;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The activity log, below the "Hub" navigation item, listing what users did, for users who may view it, while it's enabled.
 */
class ActivityController extends BaseController
{
	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) {
			return false;
		}

		if (!Hub::getInstance()->activity->isEnabled()) {
			throw new NotFoundHttpException('The activity log is disabled.');
		}

		$this->requirePermission(Hub::PERMISSION_VIEW_ACTIVITY);

		return true;
	}

	/**
	 * The activities, filtered, sorted and paginated on the server, as there can be many. Activities of a batch are listed
	 * within it, or on their own by its ID (`batch`), which isn't kept along with the filters.
	 */
	public function actionIndex(): Response
	{
		$hub = Hub::getInstance();
		/** @var ActivityService $activity */
		$activity = $hub->activity;
		$path = 'hub/manage/activity';

		$batchId = (int)Craft::$app->getRequest()->getQueryParam('batch') ?: null;
		$batch = $batchId !== null ? $activity->populate($activity->getQuery(new ActivityFilter())->where(['id' => $batchId, 'batchId' => null])->all())[0] ?? null : null;

		if ($batchId !== null && $batch === null) {
			throw new NotFoundHttpException('Batch not found.');
		}

		if ($batch === null && ($redirect = $this->keepFilters($path)) !== null) {
			return $redirect;
		}

		$userOptions = $activity->getUserOptions();
		$filter = $this->filterFromRequest(array_keys($userOptions));
		$filter->batch = $batch?->id;
		$sortColumns = $activity->getSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ActivityService::SORT_DATE);
		[$pageInfo, $rows] = $this->paginateRows($activity->getQuery($filter, $sort, $direction));

		return $this->renderTemplate('hub/pages/activity', [
			'activities' => $activity->populate($rows, $filter),
			'pageInfo' => $pageInfo,
			'filter' => $filter,
			'batch' => $batch,
			'userOptions' => $this->userChoices($userOptions),
			'actions' => ActivityGroup::cases(),
			'subjects' => ActivitySubject::cases(),
			'retentionDays' => $hub->settings->activityRetentionDays,
			'listPath' => $path,
			// Single-site installs have no site to tell.
			'columns' => $hub->columns->getColumns(ColumnService::LIST_ACTIVITY, Craft::$app->getIsMultiSite() ? [] : ['site']),
			...$this->sortVariables($path, $sortColumns, ActivityService::SORT_DATE, $sort, $direction),
		]);
	}

	/**
	 * Options of the users filter, by their value, which Twig would renumber when merging them: the users shown by their
	 * photos, deleted ones by an icon, and the activities without a user.
	 *
	 * @param array<int,array{label:string,user:\craft\elements\User|null}> $userOptions see ActivityService::getUserOptions()
	 * @return array<int|string,array<string,mixed>>
	 */
	private function userChoices(array $userOptions): array
	{
		$choices = array_map(fn (array $option) => $option['user'] !== null ? $option : ['label' => $option['label'], 'icon' => 'user-slash', 'color' => 'gray'], $userOptions);

		return $choices + [ActivityFilter::USER_NONE => ['label' => Craft::t('hub', 'No user'), 'icon' => 'robot', 'color' => 'gray']];
	}

	/**
	 * Filters arrive as query params, like those of the other lists.
	 *
	 * @param int[] $userIds IDs of the users that can be filtered by
	 */
	private function filterFromRequest(array $userIds): ActivityFilter
	{
		$params = $this->filterParamsOfRequest();

		$filter = new ActivityFilter();
		$filter->search = $params->search();
		// Not `action`, which Craft takes for the action to run, and which would shadow the action of the filters form.
		$filter->actions = $params->list('event', array_map(fn (ActivityGroup $group) => $group->value, ActivityGroup::cases()));
		$filter->subjects = $params->list('type', array_map(fn (ActivitySubject $subject) => $subject->value, ActivitySubject::cases()));
		$filter->users = $params->list('user', [...array_map('strval', $userIds), ActivityFilter::USER_NONE]);
		$filter->dateFrom = $params->date('dateFrom');
		$filter->dateTo = $params->date('dateTo');

		return $filter;
	}
}
