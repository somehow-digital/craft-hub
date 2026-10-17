<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\UserQuery;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\helpers\Db;
use craft\models\UserGroup;
use craft\services\ProjectConfig;
use DateTime;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\helpers\Range;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\models\UserStatistics;
use yii\base\Component;

/**
 * Lists the users and their groups, and collects the details shown for a single user.
 * Users aren't site-specific, so they aren't scoped to the selected sites.
 */
class UserService extends Component
{
	public const SORT_USER = 'user';
	public const SORT_EMAIL = 'email';
	public const SORT_ENTRIES = 'entries';
	public const SORT_LOGIN = 'login';
	public const SORT_CREATED = 'created';

	private ?UserStatistics $statistics = null;

	/** @var array<int,array{group:UserGroup,users:int,permissions:int,requires2fa:bool}>|null */
	private ?array $groups = null;

	/**
	 * Users narrowed by the given filters.
	 *
	 * @param string $sort one of the columns of {@see getSortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 */
	public function getQuery(UserFilter $filter, string $sort = self::SORT_CREATED, int $direction = SORT_DESC): UserQuery
	{
		$query = User::find()
			->status($filter->statuses !== [] ? $filter->statuses : null)
			->orderBy($this->sortOrder($sort, $direction));

		if ($filter->search !== '') {
			// PostgreSQL's `like` is case-sensitive, unlike MySQL's with Craft's collation.
			$operator = Craft::$app->getDb()->getIsPgsql() ? 'ilike' : 'like';
			$query->andWhere([
				'or',
				[$operator, 'users.username', $filter->search],
				[$operator, 'users.fullName', $filter->search],
				[$operator, 'users.email', $filter->search],
			]);
		}

		if ($filter->labels !== []) {
			$query->andWhere(Hub::getInstance()->labels->condition(ColumnService::LIST_USERS, $filter->labels));
		}

		if ($filter->savedFilter !== null) {
			$query->andWhere(Hub::getInstance()->filters->elementCondition(CheckKind::Users, $filter->savedFilter));
		}

		if ($filter->groups !== []) {
			$query->andWhere($this->groupCondition($filter->groups));
		}

		if ($filter->admin) {
			$query->admin();
		}

		if ($filter->logins !== []) {
			$query->andWhere(['or', ...array_map(fn (string $login) => $this->loginCondition($login), $filter->logins)]);
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
			self::SORT_LOGIN => SORT_DESC,
			self::SORT_CREATED => SORT_DESC,
		];
	}

	public function getUser(int $id): ?User
	{
		return User::find()->id($id)->status(null)->one();
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

		$row = $this->usersQuery()->select($select)->one() ?: [];

		$stats = new UserStatistics();
		$stats->total = (int)($row['total'] ?? 0);

		foreach (array_keys($conditions) as $alias) {
			$stats->$alias = (int)($row[$alias] ?? 0);
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
		$groups = Craft::$app->getUserGroups()->getAllGroups();

		if ($groups === []) {
			return [];
		}

		$counts = $this->usersQuery()
			->select(['hubGroups.groupId', 'count' => 'COUNT(*)'])
			->innerJoin(['hubGroups' => Table::USERGROUPS_USERS], '[[hubGroups.userId]] = [[users.id]]')
			->groupBy(['hubGroups.groupId'])
			->pairs();

		$required = $this->getRequired2fa();
		$permissions = Craft::$app->getUserPermissions();
		$infos = [];

		foreach ($groups as $group) {
			$infos[$group->id] = [
				'group' => $group,
				'users' => (int)($counts[$group->id] ?? 0),
				'permissions' => count($permissions->getPermissionsByGroupId($group->id)),
				'requires2fa' => $required === 'all' || (is_array($required) && in_array($group->uid, $required, true)),
			];
		}

		uasort($infos, fn (array $a, array $b) => strnatcasecmp((string)$a['group']->name, (string)$b['group']->name));

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
		$user = Craft::$app->getUser()->getIdentity();

		if ($user === null || Craft::$app->edition === CmsEdition::Solo) {
			return [];
		}

		$groups = [];

		foreach ($this->getGroups() as $id => $info) {
			if ($user->admin || $user->can('assignUserGroup:' . $info['group']->uid)) {
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

		return array_map('intval', $counts);
	}

	/**
	 * Users with the IDs that the current user may delete, as Craft lets them: users they may delete, and admins only if
	 * they're an admin themselves. They're left out, so they can't delete themselves along with others.
	 *
	 * @param int[] $ids
	 * @return User[]
	 */
	public function getDeletableUsers(array $ids): array
	{
		$current = Craft::$app->getUser()->getIdentity();

		if ($current === null || $ids === [] || !$current->can('deleteUsers')) {
			return [];
		}

		$service = Craft::$app->getElements();

		return array_values(array_filter(
			User::find()->id($ids)->status(null)->all(),
			fn (User $user) => $user->id !== $current->id && $service->canDelete($user),
		));
	}

	/**
	 * Active users the content of deleted users can be transferred to, by name, leaving out those deleted.
	 *
	 * @param int[] $deletedIds
	 * @return User[]
	 */
	public function getInheritors(array $deletedIds): array
	{
		$query = User::find()->status(User::STATUS_ACTIVE);

		if ($deletedIds !== []) {
			$query->id(['not', ...$deletedIds]);
		}

		$users = $query->all();
		usort($users, fn (User $a, User $b) => strnatcasecmp((string)$a->getName(), (string)$b->getName()));

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
		$value = Craft::$app->getProjectConfig()->get(sprintf('%s.require2fa', ProjectConfig::PATH_USERS));

		return is_string($value) || is_array($value) ? $value : null;
	}

	/**
	 * Whether the user must set up two-step verification, as all users, admins or one of their groups must.
	 */
	public function requires2fa(User $user): bool
	{
		if (Craft::$app->edition === CmsEdition::Solo) {
			return false;
		}

		$required = $this->getRequired2fa();

		if (!is_array($required)) {
			return $required === 'all';
		}

		$uids = array_map(fn (UserGroup $group) => $group->uid, $user->getGroups());

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

		return array_map('intval', $counts);
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

		return array_fill_keys(array_map('intval', $ids), true) + array_fill_keys(array_map('intval', $userIds), false);
	}

	/**
	 * The permissions of the user, under the headings Craft lists them by on the user's edit page, along with
	 * whether they are granted to the user directly and the groups granting them. Permissions Craft no longer
	 * knows, e.g. of uninstalled plugins, are listed last by their name.
	 *
	 * @return array<int,array{heading:string,permissions:array<int,array{name:string,label:string,direct:bool,groups:UserGroup[]}>}>
	 */
	public function getPermissions(User $user): array
	{
		$service = Craft::$app->getUserPermissions();
		$sources = [];

		// Craft Team grants its permissions through a group users aren't listed in.
		$groups = Craft::$app->edition === CmsEdition::Team ? [Craft::$app->getUserGroups()->getTeamGroup()] : $user->getGroups();

		foreach ($groups as $group) {
			foreach ($service->getPermissionsByGroupId($group->id) as $name) {
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
			$sources[strtolower((string)$name)]['direct'] = true;
		}

		if ($sources === []) {
			return [];
		}

		$headings = [];
		$permission = fn (string $name, string $label) => [
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
			$headings[Craft::t('hub', 'Other')][] = $permission((string)$name, (string)$name);
		}

		return array_map(fn (string $heading, array $permissions) => ['heading' => $heading, 'permissions' => $permissions], array_keys($headings), $headings);
	}

	/**
	 * Labels and headings of all permissions, by their lowercased name, in the order Craft lists them.
	 *
	 * @param array<int,array<string,mixed>> $groups headings with their permissions, see {@see \craft\services\UserPermissions::getAllPermissions()}
	 * @return array<string,array{0:string,1:string}> name => heading and label
	 */
	private function flattenPermissions(array $groups): array
	{
		$flat = [];

		$walk = function (string $heading, array $permissions) use (&$walk, &$flat) {
			foreach ($permissions as $name => $permission) {
				$flat[strtolower((string)$name)] ??= [$heading, (string)($permission['label'] ?? $name)];
				$walk($heading, $permission['nested'] ?? []);
			}
		};

		foreach ($groups as $group) {
			$walk((string)($group['heading'] ?? ''), $group['permissions'] ?? []);
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
	 * @return array<int,mixed>
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
	 * @return array<int,mixed>
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
	 * @return array<int,mixed>
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
		return (new DateTime())->modify(sprintf('-%d months', Hub::getInstance()->filters->getThreshold(CheckKind::Users, 'idle')));
	}

	/**
	 * Condition matching active users by how recently they signed in.
	 *
	 * @param string $login see the `LOGIN_*` constants of {@see UserFilter}
	 * @return array<int,mixed>
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
		$groups = Craft::$app->getUserGroups();
		$ids = array_values(array_filter(array_map(fn (string $handle) => $groups->getGroupByHandle($handle)?->id, $handles)));
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
	 */
	private function entriesQuery(): Query
	{
		return $this->authoredQuery()->andWhere('[[hubAuthors.authorId]] = [[elements.id]]');
	}

	/**
	 * Authors of live entries, along with the entries.
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
		return ["$alias.dateDeleted" => null, "$alias.draftId" => null, "$alias.revisionId" => null];
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
		$db = Craft::$app->getDb();
		$params = [];
		$sql = $db->getQueryBuilder()->buildCondition($condition, $params);

		return $db->createCommand($sql, $params)->getRawSql();
	}
}
