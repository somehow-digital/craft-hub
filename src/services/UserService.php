<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use Closure;
use Craft;
use craft\db\Connection;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\UserQuery;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\helpers\Db;
use craft\models\UserGroup;
use craft\services\Elements;
use craft\services\ProjectConfig;
use craft\services\UserGroups;
use craft\services\UserPermissions;
use craft\web\User as WebUser;
use DateTime;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\helpers\Range;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\models\FilterParams;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\models\UserStatistics;
use yii\base\Component;

/**
 * Lists the users and their groups, and collects the details shown for a single user.
 * Users aren't site-specific, so they aren't scoped to the selected sites.
 *
 * @internal
 */
final class UserService extends Component
{
	public const SORT_USER = 'user';
	public const SORT_EMAIL = 'email';
	public const SORT_ENTRIES = 'entries';
	public const SORT_ASSETS = 'assets';
	public const SORT_LOGIN = 'login';
	public const SORT_CREATED = 'created';

	/** sizes in pixels the photos of users are shown in, by the `avatar` macro and by element chips, at twice their size */
	private const PHOTO_SIZES = [30, 36, 56, 60];

	private ?UserStatistics $statistics = null;

	/** @var array<int,array{group:UserGroup,users:int,permissions:int,requires2fa:bool}>|null */
	private ?array $groups = null;

	/**
	 * @param Closure(): CmsEdition $edition the edition of Craft, which can change while it runs
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly QueryService $queries,
		private readonly LabelService $labels,
		private readonly FilterService $filters,
		private readonly UserGroups $craftUserGroups,
		private readonly UserPermissions $craftPermissions,
		private readonly Elements $craftElements,
		private readonly ProjectConfig $projectConfig,
		private readonly Connection $db,
		private readonly Closure $edition,
		private readonly ?WebUser $user = null,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * Users narrowed by the given filters.
	 *
	 * @param string $sort one of the columns of {@see getSortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 *
	 * @return UserQuery<array-key,\craft\elements\User>
	 */
	public function getQuery(UserFilter $filter, string $sort = self::SORT_CREATED, int $direction = SORT_DESC): UserQuery
	{
		$query = User::find()
			->status($filter->statuses !== [] ? $filter->statuses : null)
			->orderBy($this->sortOrder($sort, $direction));

		if ($filter->search !== '') {
			// PostgreSQL's `like` is case-sensitive, unlike MySQL's with Craft's collation.
			$operator = $this->queries->likeOperator();
			$query->andWhere([
				'or',
				[$operator, 'users.username', $filter->search],
				[$operator, 'users.fullName', $filter->search],
				[$operator, 'users.email', $filter->search],
			]);
		}

		if ($filter->labels !== []) {
			$query->andWhere($this->labels->condition(ColumnService::LIST_USERS, $filter->labels));
		}

		if ($filter->savedFilter !== null) {
			$query->andWhere($this->filters->elementCondition(CheckKind::Users, $filter->savedFilter));
		}

		if ($filter->groups !== []) {
			$query->andWhere($this->groupCondition($filter->groups));
		}

		if ($filter->admin) {
			$query->admin();
		}

		if ($filter->logins !== []) {
			$query->andWhere(['or', ...array_map($this->loginCondition(...), $filter->logins)]);
		}

		if (($login = Range::dateParam($filter->loginFrom, $filter->loginTo)) !== null) {
			$query->lastLoginDate($login);
		}

		if (($created = Range::dateParam($filter->createdFrom, $filter->createdTo)) !== null) {
			$query->dateCreated($created);
		}

		if ($filter->twoFactor === UserFilter::TWO_FACTOR_WITH || $filter->twoFactor === UserFilter::TWO_FACTOR_WITHOUT) {
			$query->andWhere($filter->twoFactor === UserFilter::TWO_FACTOR_WITH ? $this->twoFactorCondition() : ['not', $this->twoFactorCondition()]);
		}

		if ($filter->unverifiedEmail) {
			$query->andWhere(['not', ['users.unverifiedEmail' => null]]);
		}

		if (($entries = Range::condition($this->entriesQuery()->select('COUNT(*)'), $filter->entriesMin, $filter->entriesMax)) !== null) {
			$query->andWhere($entries);
		}

		if (($assets = Range::condition($this->assetsQuery()->select('COUNT(*)'), $filter->assetsMin, $filter->assetsMax)) !== null) {
			$query->andWhere($assets);
		}

		return $query;
	}

	/**
	 * Columns the users can be sorted by, with the direction they sort in first: names ascending, numbers and dates descending.
	 *
	 * @return array<string,int> column => `SORT_ASC` or `SORT_DESC`
	 */
	public function getSortColumns(): array
	{
		return [
			self::SORT_USER => SORT_ASC,
			self::SORT_EMAIL => SORT_ASC,
			self::SORT_ENTRIES => SORT_DESC,
			self::SORT_ASSETS => SORT_DESC,
			self::SORT_LOGIN => SORT_DESC,
			self::SORT_CREATED => SORT_DESC,
		];
	}

	public function getUser(int $id): ?User
	{
		$user = User::find()->id($id)->status(null)->one();

		return $user instanceof User ? $user : null;
	}

	/**
	 * Users to show by their ID, including suspended and pending ones, with their photos and the thumbnails of those loaded
	 * at once, as lists show many users, and filters even more. Only their names and photos are shown, so not their fields.
	 *
	 * @param int[] $ids
	 * @return array<int,User>
	 */
	public function getUsers(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		/** @var array<int,User> $users */
		$users = User::find()->id(array_values(array_unique($ids)))->status(null)->withCustomFields(false)->with([$this->photoPlan()])->indexBy('id')->all();

		return $users;
	}

	/**
	 * What eager-loads the photos of users, with their thumbnails, under the given path, like `authors.photo`.
	 *
	 * @return array{0:string,1:array{withTransforms:array<int,array{width:int,height:int,mode:string}>}}
	 */
	public function photoPlan(string $path = 'photo'): array
	{
		return [$path, ['withTransforms' => array_map(fn(int $size): array => ['width' => $size, 'height' => $size, 'mode' => 'crop'], self::PHOTO_SIZES)]];
	}

	/**
	 * Counts of all users, by status and by how they sign in.
	 */
	public function getStatistics(): UserStatistics
	{
		return $this->statistics ??= $this->countUsers();
	}

	private function countUsers(): UserStatistics
	{
		$conditions = [
			'active' => $this->activeCondition(),
			'pending' => ['users.pending' => true],
			'suspended' => ['users.suspended' => true],
			'locked' => ['users.locked' => true],
			'inactive' => ['users.active' => false, 'users.pending' => false],
			'admins' => ['users.admin' => true],
			'recentLogin' => $this->loginCondition(UserFilter::LOGIN_RECENT),
			'idle' => $this->idleCondition(),
			'twoFactor' => ['and', $this->activeCondition(), $this->twoFactorCondition()],
			'adminsWithoutTwoFactor' => $this->adminWithoutTwoFactorCondition(),
			'unverifiedEmail' => ['not', ['users.unverifiedEmail' => null]],
		];

		$select = ['total' => 'COUNT(*)'];
		foreach ($conditions as $alias => $condition) {
			$select[$alias] = sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END)', $this->sql($condition));
		}

		$row = $this->usersQuery()->select($select)->one() ?? [];

		$stats = new UserStatistics();
		$stats->total = Fill::int((Row::value($row, 'total') ?? 0));

		foreach (array_keys($conditions) as $alias) {
			$stats->{$alias} = Fill::int(($row[$alias] ?? 0));
		}

		return $stats;
	}

	/**
	 * All user groups, by name, with how many users they have, how many permissions they grant,
	 * and whether their users must set up two-step verification.
	 *
	 * @return array<int,array{group:UserGroup,users:int,permissions:int,requires2fa:bool}> keyed by group ID
	 */
	public function getGroups(): array
	{
		return $this->groups ??= $this->collectGroups();
	}

	/**
	 * @return array<int,array{group:UserGroup,users:int,permissions:int,requires2fa:bool}>
	 */
	private function collectGroups(): array
	{
		$groups = $this->craftUserGroups->getAllGroups();

		if ($groups === []) {
			return [];
		}

		$counts = $this->usersQuery()
			->select(['hubGroups.groupId', 'count' => 'COUNT(*)'])
			->innerJoin(['hubGroups' => Table::USERGROUPS_USERS], '[[hubGroups.userId]] = [[users.id]]')
			->groupBy(['hubGroups.groupId'])
			->pairs();

		$required = $this->getRequired2fa();
		$permissions = $this->craftPermissions;
		$infos = [];

		foreach ($groups as $group) {
			$infos[(int) $group->id] = [
				'group' => $group,
				'users' => Fill::int(($counts[Fill::int($group->id)] ?? 0)),
				'permissions' => count($permissions->getPermissionsByGroupId($group->id ?? 0)),
				'requires2fa' => $required === 'all' || (is_array($required) && in_array($group->uid, $required, true)),
			];
		}

		uasort($infos, fn(array $a, array $b): int => strnatcasecmp((string) $a['group']->name, (string) $b['group']->name));

		return $infos;
	}

	/**
	 * User groups the current user may add users to and remove them from, by name, as Craft lets them on a user's edit
	 * page: all of them for admins, others those they may assign. Craft Solo has no groups.
	 *
	 * @return array<int,UserGroup> keyed by group ID
	 */
	public function getAssignableGroups(): array
	{
		$user = $this->user?->getIdentity();

		if ($user === null || ($this->edition)() === CmsEdition::Solo) {
			return [];
		}

		$groups = [];

		foreach ($this->getGroups() as $id => $info) {
			if ($user->admin || $user->can('assignUserGroup:' . ($info['group']->uid ?? ''))) {
				$groups[$id] = $info['group'];
			}
		}

		return $groups;
	}

	/**
	 * Number of the users in each group they're in.
	 *
	 * @param int[] $userIds
	 * @return array<int,int> group ID => users
	 */
	public function getGroupCounts(array $userIds): array
	{
		if ($userIds === []) {
			return [];
		}

		$counts = (new Query())
			->select(['groupId', 'count' => 'COUNT(*)'])
			->from([Table::USERGROUPS_USERS])
			->where(['userId' => $userIds])
			->groupBy(['groupId'])
			->pairs();

		return Fill::counts($counts);
	}

	/**
	 * Users with the IDs that the current user may delete, as Craft lets them: users they may delete, and admins only if
	 * they're an admin themselves. They're left out, so they can't delete themselves along with others.
	 *
	 * @param int[] $ids
	 * @return list<User>
	 */
	public function getDeletableUsers(array $ids): array
	{
		$current = $this->user?->getIdentity();

		if ($current === null || $ids === [] || !$current->can('deleteUsers')) {
			return [];
		}

		$service = $this->craftElements;

		return array_values(array_filter(
			User::find()->id($ids)->status(null)->all(),
			fn(User $user): bool => $user->id !== $current->id && $service->canDelete($user),
		));
	}

	/**
	 * Active users the content of deleted users can be transferred to, by name, leaving out those deleted.
	 *
	 * @param int[] $deletedIds
	 * @return list<User>
	 */
	public function getInheritors(array $deletedIds): array
	{
		$query = User::find()->status(User::STATUS_ACTIVE)->withCustomFields(false);

		if ($deletedIds !== []) {
			$query->id(['not', ...$deletedIds]);
		}

		$users = $query->all();
		usort($users, fn(User $a, User $b): int => strnatcasecmp($a->getName(), $b->getName()));

		return $users;
	}

	/**
	 * Who must set up two-step verification: `all`, or the UIDs of the groups along with `admins`.
	 * Craft Solo has no groups, so it can't require it.
	 *
	 * @return string|string[]|null
	 */
	public function getRequired2fa(): string|array|null
	{
		$value = $this->projectConfig->get(sprintf('%s.require2fa', ProjectConfig::PATH_USERS));

		if (is_array($value)) {
			return array_values(array_filter($value, is_string(...)));
		}

		return is_string($value) ? $value : null;
	}

	/**
	 * Whether the user must set up two-step verification, as all users, admins or one of their groups must.
	 */
	public function requires2fa(User $user): bool
	{
		if (($this->edition)() === CmsEdition::Solo) {
			return false;
		}

		$required = $this->getRequired2fa();

		if (!is_array($required)) {
			return $required === 'all';
		}

		$uids = array_map(fn(UserGroup $group): ?string => $group->uid, $user->getGroups());

		return ($user->admin && in_array('admins', $required, true)) || array_intersect($uids, $required) !== [];
	}

	/**
	 * Number of live entries each of the users is an author of.
	 *
	 * @param int[] $userIds
	 * @return array<int,int> user ID => entries
	 */
	public function getEntryCounts(array $userIds): array
	{
		if ($userIds === []) {
			return [];
		}

		$counts = $this->authoredQuery()
			->select(['hubAuthors.authorId', 'count' => 'COUNT(*)'])
			->andWhere(['hubAuthors.authorId' => $userIds])
			->groupBy(['hubAuthors.authorId'])
			->pairs();

		return Fill::counts($counts);
	}

	/**
	 * Number of assets each of the users uploaded, that aren't deleted.
	 *
	 * @param int[] $userIds
	 * @return array<int,int> user ID => assets
	 */
	public function getAssetCounts(array $userIds): array
	{
		if ($userIds === []) {
			return [];
		}

		$counts = $this->uploadedQuery()
			->select(['hubAssets.uploaderId', 'count' => 'COUNT(*)'])
			->andWhere(['hubAssets.uploaderId' => $userIds])
			->groupBy(['hubAssets.uploaderId'])
			->pairs();

		return Fill::counts($counts);
	}

	/**
	 * Whether each of the users has set up an authenticator app or a passkey.
	 *
	 * @param int[] $userIds
	 * @return array<int,bool> user ID => whether they have
	 */
	public function getTwoFactor(array $userIds): array
	{
		if ($userIds === []) {
			return [];
		}

		$ids = [
			...(new Query())->select(['userId'])->from([Table::AUTHENTICATOR])->where(['userId' => $userIds])->andWhere(['not', ['auth2faSecret' => null]])->column(),
			...(new Query())->select(['userId'])->from([Table::WEBAUTHN])->where(['userId' => $userIds])->column(),
		];

		return array_fill_keys(array_map(Fill::int(...), $ids), true) + array_fill_keys(array_map(Fill::int(...), $userIds), false);
	}

	/**
	 * The permissions of the user, under the headings Craft lists them by on the user's edit page, along with
	 * whether they are granted to the user directly and the groups granting them. Permissions Craft no longer
	 * knows, e.g. of uninstalled plugins, are listed last by their name.
	 *
	 * @return list<array{heading:string,permissions:array<int,array{name:string,label:string,direct:bool,groups:UserGroup[]}>}>
	 */
	public function getPermissions(User $user): array
	{
		return $this->permissionHeadings($this->permissionSources($user));
	}

	/**
	 * Number of the permissions of a user, like those {@see getPermissions()} lists, without reading all the permissions
	 * Craft knows to list them by.
	 */
	public function countPermissions(User $user): int
	{
		return count($this->permissionSources($user));
	}

	/**
	 * What grants each permission of a user: the user directly and the groups granting it.
	 *
	 * @return array<string,array{direct?:bool,groups?:UserGroup[]}> by the lowercased name of the permission
	 */
	private function permissionSources(User $user): array
	{
		$service = $this->craftPermissions;
		$sources = [];

		// Craft Team grants its permissions through a group users aren't listed in.
		$groups = ($this->edition)() === CmsEdition::Team ? [$this->craftUserGroups->getTeamGroup()] : $user->getGroups();

		foreach ($groups as $group) {
			foreach ($service->getPermissionsByGroupId($group->id ?? 0) as $name) {
				$sources[strtolower($name)]['groups'][] = $group;
			}
		}

		$direct = (new Query())
			->select(['hubPermissions.name'])
			->from(['hubPermissions' => Table::USERPERMISSIONS])
			->innerJoin(['hubUsers' => Table::USERPERMISSIONS_USERS], '[[hubUsers.permissionId]] = [[hubPermissions.id]]')
			->where(['hubUsers.userId' => $user->id])
			->column();

		foreach ($direct as $name) {
			$sources[strtolower(Fill::text($name) ?? '')]['direct'] = true;
		}

		return $sources;
	}

	/**
	 * The permissions a user group grants, under the headings Craft lists them by, like those of {@see getPermissions()}.
	 *
	 * @return list<array{heading:string,permissions:array<int,array{name:string,label:string,direct:bool,groups:UserGroup[]}>}>
	 */
	public function getGroupPermissions(UserGroup $group): array
	{
		$names = $this->craftPermissions->getPermissionsByGroupId($group->id ?? 0);

		return $this->permissionHeadings(array_fill_keys(array_map(strtolower(...), $names), []));
	}

	/**
	 * Permissions under their headings, in the order Craft lists them, those it no longer knows last.
	 *
	 * @param array<string,array{direct?:bool,groups?:UserGroup[]}> $sources what grants each permission, by its lowercased name
	 * @return list<array{heading:string,permissions:array<int,array{name:string,label:string,direct:bool,groups:UserGroup[]}>}>
	 */
	private function permissionHeadings(array $sources): array
	{
		if ($sources === []) {
			return [];
		}

		$service = $this->craftPermissions;
		$headings = [];
		$permission = fn(string $name, string $label): array => [
			'name' => $name,
			'label' => $label,
			'direct' => $sources[$name]['direct'] ?? false,
			'groups' => $sources[$name]['groups'] ?? [],
		];

		foreach ($this->flattenPermissions($service->getAllPermissions()) as $name => [$heading, $label]) {
			if (isset($sources[$name])) {
				$headings[$heading][] = $permission($name, $label);
				unset($sources[$name]);
			}
		}

		foreach (array_keys($sources) as $name) {
			$headings[Craft::t('hub', 'Other')][] = $permission($name, $name);
		}

		return array_map(fn(string $heading, array $permissions): array => ['heading' => $heading, 'permissions' => $permissions], array_keys($headings), $headings);
	}

	/**
	 * Labels and headings of all permissions, by their lowercased name, in the order Craft lists them.
	 *
	 * @param array<mixed> $groups headings with their permissions, see {@see \craft\services\UserPermissions::getAllPermissions()}
	 * @return array<string,array{0:string,1:string}> name => heading and label
	 */
	private function flattenPermissions(array $groups): array
	{
		$flat = [];

		$walk = function (string $heading, array $permissions) use (&$walk, &$flat): void {
			foreach ($permissions as $name => $permission) {
				$flat[strtolower((string) $name)] ??= [$heading, Fill::text(Row::value($permission, 'label')) ?? (string) $name];
				$nested = Row::value($permission, 'nested');
				$walk($heading, is_array($nested) ? $nested : []);
			}
		};

		foreach ($groups as $group) {
			$permissions = Row::value($group, 'permissions');
			$walk(Fill::text(Row::value($group, 'heading')) ?? '', is_array($permissions) ? $permissions : []);
		}

		return $flat;
	}

	/**
	 * Condition matching users who can sign in: active and not suspended, like Craft's `active` status.
	 *
	 * @return array<string,bool>
	 */
	public function activeCondition(): array
	{
		return ['users.active' => true, 'users.suspended' => false];
	}

	/**
	 * Condition matching active users who haven't signed in within the idle threshold,
	 * or never did since they were created before it.
	 *
	 * @return list<mixed>
	 */
	public function idleCondition(): array
	{
		$date = Db::prepareDateForDb($this->idleDate());

		return [
			'and',
			$this->activeCondition(),
			[
				'or',
				['<', 'users.lastLoginDate', $date],
				['and', ['users.lastLoginDate' => null], ['<', 'elements.dateCreated', $date]],
			],
		];
	}

	/**
	 * Condition matching users who have set up an authenticator app or a passkey.
	 *
	 * @return list<mixed>
	 */
	public function twoFactorCondition(): array
	{
		return [
			'or',
			['exists', (new Query())
				->from(['hubAuthenticator' => Table::AUTHENTICATOR])
				->where('[[hubAuthenticator.userId]] = [[elements.id]]')
				->andWhere(['not', ['hubAuthenticator.auth2faSecret' => null]])],
			['exists', (new Query())
				->from(['hubPasskeys' => Table::WEBAUTHN])
				->where('[[hubPasskeys.userId]] = [[elements.id]]')],
		];
	}

	/**
	 * Condition matching active admins without an authenticator app or a passkey.
	 *
	 * @return list<mixed>
	 */
	public function adminWithoutTwoFactorCondition(): array
	{
		return ['and', $this->activeCondition(), ['users.admin' => true], ['not', $this->twoFactorCondition()]];
	}

	/**
	 * Users who haven't signed in since this date are idle.
	 */
	public function idleDate(): DateTime
	{
		return (new DateTime())->modify(sprintf('-%d months', $this->filters->getThreshold(CheckKind::Users, 'idle')));
	}

	/**
	 * Condition matching active users by how recently they signed in.
	 *
	 * @param string $login see the `LOGIN_*` constants of {@see UserFilter}
	 * @return list<mixed>
	 */
	private function loginCondition(string $login): array
	{
		return match ($login) {
			UserFilter::LOGIN_RECENT => ['and', $this->activeCondition(), ['>=', 'users.lastLoginDate', Db::prepareDateForDb($this->idleDate())]],
			UserFilter::LOGIN_IDLE => $this->idleCondition(),
			default => ['and', $this->activeCondition(), ['users.lastLoginDate' => null]],
		};
	}

	/**
	 * Condition matching the users in any of the groups, or in none with `none`.
	 *
	 * @param string[] $handles
	 * @return array<int,mixed>|string
	 */
	private function groupCondition(array $handles): array|string
	{
		$groups = $this->craftUserGroups;
		$ids = array_values(Fill::truthy(array_map(fn(string $handle): ?int => $groups->getGroupByHandle($handle)?->id, $handles)));
		$member = (new Query())
			->from(['hubGroups' => Table::USERGROUPS_USERS])
			->where('[[hubGroups.userId]] = [[elements.id]]');

		$conditions = [];

		if ($ids !== []) {
			$conditions[] = ['exists', (clone $member)->andWhere(['hubGroups.groupId' => $ids])];
		}

		if (in_array(UserFilter::GROUP_NONE, $handles, true)) {
			$conditions[] = ['not exists', $member];
		}

		return $conditions !== [] ? ['or', ...$conditions] : '0=1';
	}

	/**
	 * Live entries the queried user is an author of.
	 *
	 * @return Query<array-key,mixed>
	 */
	private function entriesQuery(): Query
	{
		return $this->authoredQuery()->andWhere('[[hubAuthors.authorId]] = [[elements.id]]');
	}

	/**
	 * Assets the queried user uploaded, that aren't deleted.
	 *
	 * @return Query<array-key,mixed>
	 */
	private function assetsQuery(): Query
	{
		return $this->uploadedQuery()->andWhere('[[hubAssets.uploaderId]] = [[elements.id]]');
	}

	/**
	 * Assets that aren't deleted, along with their uploaders.
	 *
	 * @return Query<array-key,mixed>
	 */
	private function uploadedQuery(): Query
	{
		return (new Query())
			->from(['hubAssets' => Table::ASSETS])
			->innerJoin(['hubAssetElements' => Table::ELEMENTS], '[[hubAssetElements.id]] = [[hubAssets.id]]')
			->where(['hubAssetElements.dateDeleted' => null]);
	}

	/**
	 * Authors of live entries, along with the entries.
	 *
	 * @return Query<array-key,mixed>
	 */
	private function authoredQuery(): Query
	{
		return (new Query())
			->from(['hubAuthors' => Table::ENTRIES_AUTHORS])
			->innerJoin(['hubEntries' => Table::ENTRIES], '[[hubEntries.id]] = [[hubAuthors.entryId]]')
			->innerJoin(['hubElements' => Table::ELEMENTS], '[[hubElements.id]] = [[hubEntries.id]]')
			->where($this->liveCondition('hubElements'));
	}

	/**
	 * Users that aren't deleted, to aggregate their columns.
	 *
	 * @return Query<array-key,mixed>
	 */
	private function usersQuery(): Query
	{
		return (new Query())
			->from(['users' => Table::USERS])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[users.id]]')
			->where(['elements.dateDeleted' => null]);
	}

	/**
	 * @return array<string,null>
	 */
	private function liveCondition(string $alias): array
	{
		return ["{$alias}.dateDeleted" => null, "{$alias}.draftId" => null, "{$alias}.revisionId" => null];
	}

	/**
	 * Sorting by name falls back to the username for users without a name, and sorting by sign-in lists those who never
	 * signed in last, as databases put nulls at different ends.
	 *
	 * @return array<string,int>
	 */
	private function sortOrder(string $sort, int $direction): array
	{
		$columns = match ($sort) {
			self::SORT_USER => ["(COALESCE(NULLIF([[users.fullName]], ''), [[users.username]]))" => $direction],
			self::SORT_EMAIL => ['users.email' => $direction],
			self::SORT_ENTRIES => ['(' . $this->entriesQuery()->select('COUNT(*)')->createCommand()->getRawSql() . ')' => $direction],
			self::SORT_ASSETS => ['(' . $this->assetsQuery()->select('COUNT(*)')->createCommand()->getRawSql() . ')' => $direction],
			self::SORT_LOGIN => ['(CASE WHEN [[users.lastLoginDate]] IS NULL THEN 1 ELSE 0 END)' => SORT_ASC, 'users.lastLoginDate' => $direction],
			default => ['elements.dateCreated' => $direction],
		};

		return [...$columns, 'elements.id' => $direction];
	}

	/**
	 * SQL of a condition with its values inlined, to aggregate it.
	 *
	 * @param array<int|string,mixed> $condition
	 */
	private function sql(array $condition): string
	{
		$db = $this->db;
		$params = [];
		$sql = $db->getQueryBuilder()->buildCondition($condition, $params);

		return $db->createCommand($sql, $params)->getRawSql();
	}

	/**
	 * IDs of the users of a filter by users, `me` being the current user. Without one, e.g. in the console, `me` is `0`,
	 * which matches no one, so the filter doesn't match the items of everyone instead.
	 *
	 * @param array<int|string> $values IDs, and `me`
	 * @return int[]
	 */
	public function resolveIds(array $values): array
	{
		return array_map(fn(int|string $value): int => $value === FilterParams::ME ? (int) $this->user?->getId() : (int) $value, $values);
	}
}
