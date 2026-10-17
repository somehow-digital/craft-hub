<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\models\UserGroupFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use SomehowDigital\Craft\Hub\services\UserService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class UsersController extends BaseController
{
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
		$selection = $this->selection(ColumnService::LIST_USER_GROUPS, $path);
		$filter = ($selection['review'] ?? false) ? new UserGroupFilter() : $this->groupFilterFromRequest();
		$sortColumns = $lists->getUserGroupSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		[$pageInfo, $groups] = $this->paginateItems($this->reviewItems($lists->getUserGroups($all, $filter, $sort, $direction), $selection));

		return $this->renderTemplate('hub/pages/users/groups', [
			'groups' => $groups,
			'pageInfo' => $pageInfo,
			'filter' => $filter,
			'listPath' => $path,
			'columns' => $hub->columns->getColumns(ColumnService::LIST_USER_GROUPS),
			...$this->labelVariables(ColumnService::LIST_USER_GROUPS, array_keys($groups)),
			...$this->bulkVariables(ColumnService::LIST_USER_GROUPS, $selection, $filter),
			'extraTabs' => $this->extraTabs(),
			...$this->sortVariables($path, $sortColumns, ListService::SORT_NAME, $sort, $direction),
		]);
	}

	public function actionSummary(): Response
	{
		/** @var UserService $users */
		$users = Hub::getInstance()->users;

		return $this->renderSummary($this->usersSummary($users->getStatistics(), $users->getGroups()));
	}

	public function actionDiagnostics(): Response
	{
		$hub = Hub::getInstance();
		$stats = $hub->users->getStatistics();
		$path = 'hub/data/users';

		return $this->renderDiagnostics('data', 'users', Craft::t('hub', 'Users'), [
			[
				'label' => Craft::t('hub', 'Locked users'),
				'description' => Craft::t('hub', 'Locked after too many invalid sign-in attempts.'),
				'icon' => 'lock',
				'severity' => Severity::Warning,
				'value' => $stats->locked,
				'url' => $this->hubUrl($path, ['status' => User::STATUS_LOCKED]),
			],
			[
				'label' => Craft::t('hub', 'Admins without two-step verification'),
				'description' => Craft::t('hub', 'Neither an authenticator app nor a passkey protects their accounts.'),
				'icon' => 'shield-halved',
				'severity' => Severity::Warning,
				'value' => $stats->adminsWithoutTwoFactor,
				'url' => $this->hubUrl($path, ['admin' => 1, 'status' => User::STATUS_ACTIVE, 'twoFactor' => UserFilter::TWO_FACTOR_WITHOUT]),
			],
			[
				'label' => Craft::t('hub', 'Idle users'),
				'description' => Craft::t('hub', 'Not signed in for {months, plural, =1{one month} other{# months}}.', ['months' => $hub->settings->idleUserThresholdMonths]),
				'icon' => 'clock',
				'severity' => Severity::Notice,
				'value' => $stats->idle,
				'url' => $this->hubUrl($path, ['login' => UserFilter::LOGIN_IDLE]),
			],
			[
				'label' => Craft::t('hub', 'Pending users'),
				'description' => Craft::t('hub', 'Their accounts haven’t been activated.'),
				'icon' => 'envelope',
				'severity' => Severity::Notice,
				'value' => $stats->pending,
				'url' => $this->hubUrl($path, ['status' => User::STATUS_PENDING]),
			],
			[
				'label' => Craft::t('hub', 'Suspended users'),
				'description' => Craft::t('hub', 'Delete those that no longer need an account.'),
				'icon' => 'ban',
				'severity' => Severity::Notice,
				'value' => $stats->suspended,
				'url' => $this->hubUrl($path, ['status' => User::STATUS_SUSPENDED]),
			],
			[
				'label' => Craft::t('hub', 'Unverified email addresses'),
				'description' => Craft::t('hub', 'Changed, but not verified yet.'),
				'icon' => 'at',
				'severity' => Severity::Notice,
				'value' => $stats->unverifiedEmail,
				'url' => $this->hubUrl($path, ['unverified' => 1]),
			],
		], siteScoped: false, extraTabs: $this->extraTabs());
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
			'idleMonths' => $hub->settings->idleUserThresholdMonths,
			'checks' => $hub->checks->forUser($user, $twoFactor),
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
		$request = Craft::$app->getRequest();
		$twoFactor = $request->getQueryParam('twoFactor');

		$filter = new UserGroupFilter();
		$filter->search = trim((string)$request->getQueryParam('q', ''));
		$filter->labels = $this->labelsParam();
		$filter->usersMin = $this->intParam('usersMin');
		$filter->usersMax = $this->intParam('usersMax');
		$filter->permissionsMin = $this->intParam('permissionsMin');
		$filter->permissionsMax = $this->intParam('permissionsMax');
		$filter->twoFactor = in_array($twoFactor, [UserGroupFilter::TWO_FACTOR_REQUIRED, UserGroupFilter::TWO_FACTOR_OPTIONAL], true) ? $twoFactor : null;
		$filter->empty = $request->getQueryParam('empty') === '1';

		return $filter;
	}

	/**
	 * Tabs of the index page between the users list and the diagnostics.
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
