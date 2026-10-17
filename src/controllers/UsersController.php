<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\ListSelection;
use SomehowDigital\Craft\Hub\helpers\ListSort;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\UserGroupFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use SomehowDigital\Craft\Hub\services\UserService;
use SomehowDigital\Craft\Hub\statistics\UsersSummary;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class UsersController extends BaseController
{
	protected ?Page $page = Page::Users;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/data/users')) !== null) {
			return $redirect;
		}

		return $this->renderTemplate('hub/pages/users/index', [
			...$this->userListVariables('hub/data/users'),
			'extraTabs' => $this->extraTabs(),
		]);
	}

	public function actionGroups(): Response
	{
		$hub = Hub::getInstance();
		/** @var UserService $users */
		$users = $hub->users;
		/** @var ListService $lists */
		$lists = $hub->lists;
		$path = 'hub/data/users/groups';
		$all = $users->getGroups();

		// Craft Solo has no groups, so neither is the tab offered.
		if ($all === []) {
			throw new NotFoundHttpException('No user groups.');
		}

		if (($redirect = $this->keepFilters($path)) !== null) {
			return $redirect;
		}

		// While reviewing the selection, the filters don't apply.
		$selection = ListSelection::fromRequest(ColumnService::LIST_USER_GROUPS, $path);
		$filter = $selection->review ? new UserGroupFilter() : $this->groupFilterFromRequest();
		$sort = ListSort::fromRequest($lists->getUserGroupSortColumns(), ListService::SORT_NAME);
		[$pageInfo, $groups] = Paginator::fromRequest()->items($selection->limitItems($lists->getUserGroups($all, $filter, $sort->column, $sort->direction)));

		return $this->renderTemplate('hub/pages/users/groups', [
			'groups' => $groups,
			'pageInfo' => $pageInfo,
			'filter' => $filter,
			'listPath' => $path,
			'columns' => $hub->columns->getColumns(ColumnService::LIST_USER_GROUPS),
			...$this->labelVariables(ColumnService::LIST_USER_GROUPS, array_keys($groups)),
			...$selection->variables($filter),
			'extraTabs' => $this->extraTabs(),
			...$sort->variables($path),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Users, (new UsersSummary())->index(), siteScoped: false, extraTabs: $this->extraTabs());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::Users, siteScoped: false, extraTabs: $this->extraTabs());
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$tab = $this->detailTab($tab, [self::TAB_ENTRIES, self::TAB_ASSETS]);
		$hub = Hub::getInstance();
		/** @var UserService $users */
		$users = $hub->users;

		$user = $users->getUser($id) ?? throw new NotFoundHttpException('User not found.');
		$twoFactor = $users->getTwoFactor([$user->id])[$user->id] ?? false;

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/users/view', [
			'user' => $user,
			'selectedTab' => $tab,
			'groups' => $user->getGroups(),
			'twoFactor' => $twoFactor,
			'requires2fa' => $users->requires2fa($user),
			'idleMonths' => $hub->filters->getThreshold(CheckKind::Users, 'idle'),
			'checks' => $hub->checks->forUser($user),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/data/users/$id/entries", author: $user),
				self::TAB_ASSETS => $this->fileListVariables("hub/data/users/$id/assets", uploader: $user),
				self::TAB_DIAGNOSTICS => [],
				default => $this->overviewVariables($user),
			},
		]);
	}

	/**
	 * Variables of the overview tab of a user page.
	 *
	 * @return array<string,mixed>
	 */
	private function overviewVariables(User $user): array
	{
		$hub = Hub::getInstance();
		/** @var UserService $users */
		$users = $hub->users;
		/** @var ReferenceService $references */
		$references = $hub->references;

		return [
			'permissions' => $user->admin ? [] : $users->getPermissions($user),
			'references' => $references->getReferences($user),
		];
	}

	/**
	 * Filters arrive as query params, like those of the other lists.
	 */
	private function groupFilterFromRequest(): UserGroupFilter
	{
		$params = $this->filterParamsOfRequest();

		$filter = new UserGroupFilter();
		$filter->search = $params->search();
		$filter->labels = $this->labelsParam();
		$filter->usersMin = $params->int('usersMin');
		$filter->usersMax = $params->int('usersMax');
		$filter->permissionsMin = $params->int('permissionsMin');
		$filter->permissionsMax = $params->int('permissionsMax');
		$filter->twoFactor = $params->choice('twoFactor', [UserGroupFilter::TWO_FACTOR_REQUIRED, UserGroupFilter::TWO_FACTOR_OPTIONAL]);
		$filter->empty = $params->flag('empty');

		return $filter;
	}

	/**
	 * Tabs of the index page between the users list and the statistics.
	 *
	 * @return array<string,string>
	 */
	private function extraTabs(): array
	{
		/** @var UserService $users */
		$users = Hub::getInstance()->users;

		return $users->getGroups() === [] ? [] : ['groups' => Craft::t('app', 'Groups')];
	}
}
