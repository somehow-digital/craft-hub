<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\ActivityGroup;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\helpers\Ids;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\ActivityFilter;
use SomehowDigital\Craft\Hub\services\ActivityFeedService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The activity log, below the "Hub" navigation item, listing what users did, for users who may view it, while it's enabled.
 *
 * @internal
 */
final class ActivityController extends BaseController
{
	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) {
			return false;
		}

		if (!Hub::getInstance()->activity->isEnabled()) {
			throw new NotFoundHttpException('The activity log is disabled.');
		}

		$this->requirePermission(Permission::ViewActivity->value);

		return true;
	}

	/**
	 * The activities, filtered, sorted and paginated on the server, as there can be many. Activities of a batch are listed
	 * within it, or on their own by its ID (`batch`), which isn't kept along with the filters.
	 */
	public function actionIndex(): Response
	{
		$hub = Hub::getInstance();
		$activity = $hub->feed;
		$path = 'hub/manage/activity';

		$batchId = Fill::number($this->queryInt('batch'));
		$batch = $batchId !== null ? $activity->populate($activity->getQuery(new ActivityFilter())->where(['id' => $batchId, 'batchId' => null])->all())[0] ?? null : null;

		if ($batchId !== null && $batch === null) {
			throw new NotFoundHttpException('Batch not found.');
		}

		// The log of one item, opened from its page, isn't kept along with the filters, as the batch isn't.
		$item = Fill::number($this->queryInt('item'));

		if ($batch === null && $item === null && ($redirect = $this->keepFilters($path)) instanceof Response) {
			return $redirect;
		}

		// Only the users filtered by are read, which are shown before the options of the filter are loaded.
		$picked = Ids::positive($this->filterParamsOfRequest()->list('user'));
		$userOptions = $picked !== [] ? $activity->getUserOptions($picked) : [];
		$filter = $this->filterFromRequest(array_keys($userOptions));
		$filter->batch = $batch?->id;
		$filter->item = count($filter->subjects) === 1 ? $item : null;

		$sort = $this->listSort($activity->getSortColumns(), ActivityFeedService::SORT_DATE);
		[$pageInfo, $rows] = Hub::getInstance()->queries->paginateRows($this->paginator(), $activity->getQuery($filter, $sort->column, $sort->direction));

		return $this->renderTemplate('hub/pages/activity', [
			'list' => [
				'activities' => $activity->populate($rows, $filter),
				'pageInfo' => $pageInfo,
				'filter' => $filter,
				'batch' => $batch,
				'userOptions' => array_intersect_key($this->activityUserOptions($userOptions), array_flip($filter->users)),
				'optionsUrls' => ['user' => $this->filterOptionsUrl('user')],
				'actions' => ActivityGroup::cases(),
				'subjects' => ActivitySubject::cases(),
				'retentionDays' => $hub->settings->activityRetentionDays,
				'listPath' => $path,
				// Single-site installs have no site to tell.
				'columns' => $hub->columns->getColumns(ColumnService::LIST_ACTIVITY, Craft::$app->getIsMultiSite() ? [] : ['site']),
				...$sort->variables($path),
			],
		]);
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
		$filter->actions = $params->list('event', array_map(fn(ActivityGroup $group) => $group->value, ActivityGroup::cases()));
		$filter->subjects = $params->list('type', array_map(fn(ActivitySubject $subject) => $subject->value, ActivitySubject::cases()));
		$filter->users = $params->list('user', [...array_map(strval(...), $userIds), ActivityFilter::USER_NONE]);
		$filter->dateFrom = $params->date('dateFrom');
		$filter->dateTo = $params->date('dateTo');

		return $filter;
	}
}
