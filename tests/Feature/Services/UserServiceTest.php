<?php

use craft\elements\db\UserQuery;
use craft\elements\User;
use craft\enums\CmsEdition;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\services\UserService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->editors = $this->userGroup(1, 'editors', ['accessCp', 'utility:updates']);
	$this->userGroup(2, 'authors');
	$this->users = new UserService();
});

function userFilter(array $values): UserFilter
{
	$filter = new UserFilter();

	foreach ($values as $name => $value) {
		$filter->$name = $value;
	}

	return $filter;
}

it('queries all users, most recently created first', function () {
	$query = $this->users->getQuery(new UserFilter());

	expect($query->status)->toBeNull()
		->and($query->orderBy)->toBe(['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC])
		->and($query->where)->toBeNull()
		->and($query->admin)->toBeNull()
		->and($query->lastLoginDate)->toBeNull();
});

it('narrows the users by the filters', function () {
	$query = $this->users->getQuery(userFilter([
		'search' => 'jane',
		'statuses' => ['active', 'locked'],
		'admin' => true,
		'loginFrom' => new DateTime('2024-01-01'),
		'createdTo' => new DateTime('2024-02-01'),
		'unverifiedEmail' => true,
	]));

	expect($query->status)->toBe(['active', 'locked'])
		->and($query->admin)->toBeTrue()
		->and($query->lastLoginDate)->toBe(['and', '>= 2024-01-01T00:00:00+00:00'])
		->and($query->dateCreated)->toBe(['and', '< 2024-02-02T00:00:00+00:00'])
		->and(conditions($query))->toBe([
			['or', ['like', 'users.username', 'jane'], ['like', 'users.fullName', 'jane'], ['like', 'users.email', 'jane']],
			['not', ['users.unverifiedEmail' => null]],
		]);
});

it('narrows the users by their groups, or to those in none', function () {
	$where = $this->users->getQuery(userFilter(['groups' => ['editors', 'none']]))->where;

	expect($where[0])->toBe('or')
		->and($where[1][0])->toBe('exists')
		->and(rawSql($where[1][1]))->toContain('`hubGroups`.`userId` = `elements`.`id`')->toContain('`hubGroups`.`groupId`=1')
		->and($where[2][0])->toBe('not exists')
		->and(rawSql($where[2][1]))->not->toContain('`groupId`=');
});

it('matches no users for groups that do not exist', function () {
	expect($this->users->getQuery(userFilter(['groups' => ['bogus']]))->where)->toBe('0=1');
});

it('narrows the users by how recently they signed in, active users only', function () {
	[$or, $recent, $idle, $never] = $this->users->getQuery(userFilter(['logins' => UserFilter::getLogins()]))->where;
	$active = ['users.active' => true, 'users.suspended' => false];

	expect($or)->toBe('or')
		->and($recent[1])->toBe($active)
		->and($recent[2][0])->toBe('>=')
		->and($recent[2][1])->toBe('users.lastLoginDate')
		->and($idle)->toBe($this->users->idleCondition())
		->and($idle[2][1][0])->toBe('<')
		// Users who never signed in are idle once they were created before the threshold.
		->and($idle[2][2][1])->toBe(['users.lastLoginDate' => null])
		->and($idle[2][2][2][1])->toBe('elements.dateCreated')
		->and($never)->toBe(['and', $active, ['users.lastLoginDate' => null]]);
});

it('narrows the users by two-step verification', function (string $twoFactor, string $operator) {
	$where = $this->users->getQuery(userFilter(['twoFactor' => $twoFactor]))->where;
	$condition = $operator === 'not' ? $where[1] : $where;

	expect($where[0])->toBe($operator)
		->and($condition[0])->toBe('or')
		->and(rawSql($condition[1][1]))->toContain('FROM `authenticator`')->toContain('NOT (`hubAuthenticator`.`auth2faSecret` IS NULL)')
		->and(rawSql($condition[2][1]))->toContain('FROM `webauthn`');
})->with([
	'with' => [UserFilter::TWO_FACTOR_WITH, 'or'],
	'without' => [UserFilter::TWO_FACTOR_WITHOUT, 'not'],
]);

it('narrows the users by the number of entries they are an author of', function () {
	$where = $this->users->getQuery(userFilter(['entriesMin' => 2]))->where;

	expect($where[0])->toBe('and')
		->and($where[1][0])->toBe('>=')
		->and(rawSql($where[1][1]))->toContain('`hubAuthors`.`authorId` = `elements`.`id`')->toContain('`hubElements`.`draftId` IS NULL')
		->and($where[1][2])->toBe(2);
});

it('sorts the users', function (string $sort, int $direction, array $order) {
	expect($this->users->getQuery(new UserFilter(), $sort, $direction)->orderBy)->toBe($order);
})->with([
	'by name, falling back to the username' => [UserService::SORT_USER, SORT_ASC, ["(COALESCE(NULLIF([[users.fullName]], ''), [[users.username]]))" => SORT_ASC, 'elements.id' => SORT_ASC]],
	'by email' => [UserService::SORT_EMAIL, SORT_DESC, ['users.email' => SORT_DESC, 'elements.id' => SORT_DESC]],
	'by sign-in, never last' => [UserService::SORT_LOGIN, SORT_ASC, ['(CASE WHEN [[users.lastLoginDate]] IS NULL THEN 1 ELSE 0 END)' => SORT_ASC, 'users.lastLoginDate' => SORT_ASC, 'elements.id' => SORT_ASC]],
]);

it('sorts the users by the number of entries they are an author of', function () {
	$order = $this->users->getQuery(new UserFilter(), UserService::SORT_ENTRIES)->orderBy;

	expect(array_key_first($order))->toStartWith('(SELECT COUNT(*)')->toContain('`hubAuthors`.`authorId` = `elements`.`id`')
		->and(array_values($order))->toBe([SORT_DESC, SORT_DESC]);
});

it('sorts names ascending, numbers and dates descending', function () {
	expect($this->users->getSortColumns())->toBe([
		'user' => SORT_ASC,
		'email' => SORT_ASC,
		'entries' => SORT_DESC,
		'login' => SORT_DESC,
		'created' => SORT_DESC,
	]);
});

it('finds a user regardless of their status', function () {
	$user = new User(['id' => 3]);
	ElementQueries::respond(User::class, fn (UserQuery $query) => $query->id === 3 && $query->status === null ? [$user] : []);

	expect($this->users->getUser(3))->toBe($user)
		->and($this->users->getUser(4))->toBeNull();
});

it('counts the users once', function () {
	$this->db()->respond('FROM {{%users}} {{users}}', [[
		'total' => '9', 'active' => '5', 'pending' => '1', 'suspended' => '1', 'locked' => '1', 'inactive' => '2', 'admins' => '2',
		'recentLogin' => '3', 'idle' => '1', 'twoFactor' => '2', 'adminsWithoutTwoFactor' => '1', 'unverifiedEmail' => '1',
	]]);

	$stats = $this->users->getStatistics();
	$this->users->getStatistics();
	[$sql] = $this->db()->queriesMatching('FROM {{%users}} {{users}}');

	expect($stats->total)->toBe(9)
		->and($stats->active)->toBe(5)
		->and($stats->inactive)->toBe(2)
		->and($stats->idle)->toBe(1)
		->and($stats->getNew())->toBe(1)
		->and($stats->adminsWithoutTwoFactor)->toBe(1)
		->and($this->db()->queriesMatching('FROM {{%users}} {{users}}'))->toHaveCount(1)
		->and($sql)->toContain('`elements`.`dateDeleted` IS NULL')
		->and($sql)->toContain('SUM(CASE WHEN (`users`.`active`=TRUE) AND (`users`.`suspended`=FALSE) THEN 1 ELSE 0 END) AS `active`');
});

it('counts no users without any', function () {
	expect($this->users->getStatistics()->total)->toBe(0);
});

it('lists the groups by name, with their users, permissions and whether they require two-step verification', function () {
	$this->db()->respond('{{%usergroups_users}}', [['groupId' => '1', 'count' => '3']]);
	$users = Mockery::mock(UserService::class)->makePartial();
	$users->shouldReceive('getRequired2fa')->andReturn(['admins', 'group-authors']);

	$groups = $users->getGroups();

	expect(array_keys($groups))->toBe([2, 1])
		->and($groups[1]['group'])->toBe($this->editors)
		->and($groups[1]['users'])->toBe(3)
		->and($groups[1]['permissions'])->toBe(2)
		->and($groups[1]['requires2fa'])->toBeFalse()
		->and($groups[2]['users'])->toBe(0)
		->and($groups[2]['requires2fa'])->toBeTrue();
});

it('lists no groups without any', function () {
	$this->userGroups = [];

	expect($this->users->getGroups())->toBe([])
		->and($this->db()->queriesMatching('{{%usergroups_users}}'))->toBe([]);
});

it('requires two-step verification of all users, admins or the users of groups', function (mixed $required, bool $admin, array $groups, bool $expected) {
	Craft::$app->edition = CmsEdition::Pro;
	$users = Mockery::mock(UserService::class)->makePartial();
	$users->shouldReceive('getRequired2fa')->andReturn($required);
	$user = new User(['id' => 3, 'admin' => $admin]);
	$user->setGroups(array_map(fn (int $id) => $this->userGroups[$id], $groups));

	expect($users->requires2fa($user))->toBe($expected);
})->with([
	'all' => ['all', false, [], true],
	'none' => [null, true, [1], false],
	'admins' => [['admins'], true, [], true],
	'not admins' => [['admins'], false, [1], false],
	'a group' => [['group-editors'], false, [1, 2], true],
]);

it('never requires two-step verification on Craft Solo', function () {
	$users = Mockery::mock(UserService::class)->makePartial();
	$users->shouldNotReceive('getRequired2fa');

	expect($users->requires2fa(new User(['id' => 3])))->toBeFalse();
});

it('counts the entries the users are an author of', function () {
	$this->db()->respond('{{%entries_authors}}', [['authorId' => '3', 'count' => '2']]);

	expect($this->users->getEntryCounts([3, 4]))->toBe([3 => 2])
		->and($this->db()->queriesMatching('{{%entries_authors}}')[0])->toContain('`hubAuthors`.`authorId` IN (3, 4)')->toContain('`hubElements`.`revisionId` IS NULL')
		->and($this->users->getEntryCounts([]))->toBe([]);
});

it('tells which users have set up an authenticator app or a passkey', function () {
	$this->db()->respond('{{%authenticator}}', [['userId' => '3']]);
	$this->db()->respond('{{%webauthn}}', [['userId' => '4']]);

	expect($this->users->getTwoFactor([3, 4, 5]))->toEqualCanonicalizing([3 => true, 4 => true, 5 => false])
		->and($this->db()->queriesMatching('{{%authenticator}}')[0])->toContain('NOT (`auth2faSecret` IS NULL)')
		->and($this->users->getTwoFactor([]))->toBe([]);
});

it('lists the permissions of a user by heading, with what grants them', function () {
	Craft::$app->edition = CmsEdition::Pro;
	$this->db()->respond('{{%userpermissions_users}}', [['name' => 'viewusers'], ['name' => 'legacy:do']]);
	$user = new User(['id' => 3]);
	$user->setGroups([$this->editors]);

	$permissions = $this->users->getPermissions($user);

	expect(array_column($permissions, 'heading'))->toBe(['General', 'Users', 'Other'])
		->and($permissions[0]['permissions'])->toBe([
			['name' => 'accesscp', 'label' => 'Access the control panel', 'direct' => false, 'groups' => [$this->editors]],
			['name' => 'utility:updates', 'label' => 'Updates', 'direct' => false, 'groups' => [$this->editors]],
		])
		->and($permissions[1]['permissions'][0])->toMatchArray(['name' => 'viewusers', 'direct' => true, 'groups' => []])
		// Permissions Craft no longer knows are listed by their name.
		->and($permissions[2]['permissions'][0])->toMatchArray(['name' => 'legacy:do', 'label' => 'legacy:do']);
});

it('lists the permissions of the team on Craft Team', function () {
	Craft::$app->edition = CmsEdition::Team;
	Craft::$app->getUserGroups()->shouldReceive('getTeamGroup')->andReturn($this->editors);

	$permissions = $this->users->getPermissions(new User(['id' => 3]));

	expect(array_column($permissions[0]['permissions'], 'name'))->toBe(['accesscp', 'utility:updates'])
		->and($permissions[0]['permissions'][0]['groups'])->toBe([$this->editors]);
});

it('lists no permissions of users without any', function () {
	$user = new User(['id' => 3]);
	$user->setGroups([]);

	expect($this->users->getPermissions($user))->toBe([]);
});

it('offers the groups the current user may assign, all of them to admins and none in Craft Solo', function () {
	Craft::$app->edition = CmsEdition::Pro;
	$editor = new User(['id' => 5, 'username' => 'editor']);
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($editor);
	Craft::$app->getUserPermissions()->shouldReceive('doesUserHavePermission')->andReturnUsing(fn (int $id, string $permission) => $permission === 'assignUserGroup:group-authors');

	expect(array_keys($this->users->getAssignableGroups()))->toBe([2]);

	$editor->admin = true;
	expect(array_keys($this->users->getAssignableGroups()))->toBe([2, 1]);

	Craft::$app->edition = CmsEdition::Solo;
	expect($this->users->getAssignableGroups())->toBe([]);
});

it('deletes no users for users who may not delete users', function () {
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 5, 'username' => 'editor']));
	Craft::$app->getUserPermissions()->shouldReceive('doesUserHavePermission')->andReturnFalse();

	expect($this->users->getDeletableUsers([1, 2]))->toBe([]);
});

it('offers the active users other than those deleted to inherit their content, by name', function () {
	ElementQueries::respond(User::class, [new User(['id' => 3, 'username' => 'zed']), new User(['id' => 4, 'username' => 'amy'])]);

	$inheritors = $this->users->getInheritors([1, 2]);
	$query = ElementQueries::executed(User::class)[0];

	expect(array_map(fn (User $user) => $user->id, $inheritors))->toBe([4, 3])
		->and($query->id)->toBe(['not', 1, 2])
		->and($query->status)->toBe(User::STATUS_ACTIVE);
});

it('counts the users in each group', function () {
	$this->db()->respond('FROM {{%usergroups_users}}', [['groupId' => '1', 'count' => '2']]);

	expect($this->users->getGroupCounts([1, 2]))->toBe([1 => 2])
		->and($this->users->getGroupCounts([]))->toBe([]);
});
