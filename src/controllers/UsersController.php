<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Closure;
use Craft;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\ListSelection;
use SomehowDigital\Craft\Hub\helpers\ListSort;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\UserGroupFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\statistics\UsersSummary;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The users page, below the "Data" navigation item: its list, user groups, statistics and diagnostics, and the detail
 * pages of users and user groups.
 */
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
		$users = $hub->users;
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
			'list' => [
				'groups' => $groups,
				'pageInfo' => $pageInfo,
				'filter' => $filter,
				'listPath' => $path,
				'columns' => $hub->columns->getColumns(ColumnService::LIST_USER_GROUPS),
				...$this->labelVariables(ColumnService::LIST_USER_GROUPS, array_keys($groups)),
				...$selection->variables($filter),
				...$sort->variables($path),
			],
			'extraTabs' => $this->extraTabs(),
		]);
	}

	public function actionGroup(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$hub = Hub::getInstance();
		$info = $hub->users->getGroups()[$id] ?? throw new NotFoundHttpException('User group not found.');

		// User groups have no checks, so neither do they have the diagnostics tab of the other detail pages.
		if (!in_array($tab, [self::TAB_OVERVIEW, self::TAB_USERS], true)) {
			throw new NotFoundHttpException('Tab not found.');
		}

		$group = $info['group'];
		$panel = $this->renderPanel([
			'permissions' => fn () => ['hub/_panels/groups/permissions', ['permissions' => $hub->users->getGroupPermissions($group)]],
			'maintains' => fn () => ['hub/_panels/groups/maintains', ['maintained' => $hub->maintainers->getMaintainedByGroup($group)]],
			'activity' => fn () => ['hub/_panels/activity', ['subject' => ActivitySubject::UserGroup->value, 'id' => $group->id]],
		]);

		if ($panel !== null) {
			return $panel;
		}

		// Only the selected tab is rendered, so only its variables are computed.
		return $this->renderTemplate('hub/pages/users/group', [
			'info' => $info,
			'selectedTab' => $tab,
			...match ($tab) {
				self::TAB_USERS => $this->userListVariables("hub/data/users/groups/$id/users", $info['group']),
				default => ['maintainedCount' => $hub->maintainers->countMaintainedByGroup($group)],
			},
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
		$users = $hub->users;

		$user = $users->getUser($id) ?? throw new NotFoundHttpException('User not found.');

		if (in_array($tab, [self::TAB_ENTRIES, self::TAB_ASSETS], true) && ($redirect = $this->keepFilters("hub/data/users/$id/$tab")) !== null) {
			return $redirect;
		}

		$panel = $this->renderPanel($tab === self::TAB_DIAGNOSTICS ? $this->checksPanel(fn () => $hub->checks->forUser($user)) : $this->overviewPanels($user));

		if ($panel !== null) {
			return $panel;
		}

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
	 * Variables of the overview tab of a user page: what sums up its panels, which load what they list once opened.
	 *
	 * @return array<string,mixed>
	 */
	private function overviewVariables(User $user): array
	{
		$hub = Hub::getInstance();

		return [
			'permissionCount' => $user->admin ? 0 : $hub->users->countPermissions($user),
			'referenceCount' => $hub->references->countReferences($user),
			'referencedCount' => $hub->references->countReferenced($user),
			'maintainedCount' => $hub->maintainers->countMaintainedBy($user),
		];
	}

	/**
	 * Panels of the overview tab of a user page, with what each lists.
	 *
	 * @return array<string,Closure():array{0:string,1:array<string,mixed>}>
	 */
	private function overviewPanels(User $user): array
	{
		$hub = Hub::getInstance();

		return [
			'permissions' => fn () => ['hub/_panels/users/permissions', ['user' => $user, 'permissions' => $user->admin ? [] : $hub->users->getPermissions($user)]],
			'references' => fn () => ['hub/_panels/users/references', ['references' => $hub->references->getReferences($user)]],
			'referenced' => fn () => ['hub/_panels/referenced', ['referenced' => $hub->references->getReferenced($user)]],
			'maintains' => fn () => ['hub/_panels/users/maintains', ['maintained' => $hub->maintainers->getMaintainedBy($user)]],
			'activity' => fn () => ['hub/_panels/activity', ['subject' => ActivitySubject::User->value, 'id' => $user->id]],
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
		$users = Hub::getInstance()->users;

		return $users->getGroups() === [] ? [] : ['groups' => Craft::t('app', 'Groups')];
	}
}
