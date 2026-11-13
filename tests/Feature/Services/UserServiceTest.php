<?php

declare(strict_types=1);

use craft\elements\db\UserQuery;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\services\ProjectConfig;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\services\UserService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

/**
 * Sets what the project config says is required.
 *
 * @param string|array<mixed>|null $required
 */
function requiring2fa(string|array|null $required): void
{
	currentTest()->mockComponent('projectConfig', ProjectConfig::class)->shouldReceive('get')->with('users.require2fa')->andReturn($required);
	currentTest()->resetServices();
}

beforeEach(function (): void {
	$this->editors = $this->userGroup(1, 'editors', ['accessCp', 'utility:updates']);
	$this->userGroup(2, 'authors');
});

it('loads users to show with their photos and thumbnails at once', function (): void {
	ElementQueries::respond(User::class, fn(): array => [new User(['id' => 1])]);

	expect($this->hub()->users->getUsers([]))->toBe([])
		->and(ElementQueries::executed(User::class))->toBe([])
		->and($this->hub()->users->getUsers([1, 2, 1]))->toHaveCount(1);

	$query = ElementQueries::executed(User::class)[0];

	expect($query->id)->toBe([1, 2])
		->and($query->status)->toBeNull()
		->and(at($query->with, 0, 0))->toBe('photo')
		->and(at($query->with, 0, 1, 'withTransforms', 0))->toBe(['width' => 30, 'height' => 30, 'mode' => 'crop'])
		->and($this->hub()->users->photoPlan('authors.photo')[0])->toBe('authors.photo');
});

/**
 * @param array<string,mixed> $values
 */
function userFilter(array $values): UserFilter
{
	$filter = new UserFilter();

	Craft::configure($filter, $values);

	return $filter;
}

it('queries all users, most recently created first', function (): void {
	$query = $this->hub()->users->getQuery(new UserFilter());

	expect($query->status)->toBeNull()
		->and($query->orderBy)->toBe(['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC])
		->and($query->where)->toBeNull()
		->and($query->admin)->toBeNull()
		->and($query->lastLoginDate)->toBeNull();
});

it('narrows the users by the filters', function (): void {
	$query = $this->hub()->users->getQuery(userFilter([
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

it('narrows the users by their groups, or to those in none', function (): void {
	$where = $this->hub()->users->getQuery(userFilter(['groups' => ['editors', 'none']]))->where;

	expect(at($where, 0))->toBe('or')
		->and(at($where, 1, 0))->toBe('exists')
		->and(rawSql(at($where, 1, 1)))->toContain('`hubGroups`.`userId` = `elements`.`id`')->toContain('`hubGroups`.`groupId`=1')
		->and(at($where, 2, 0))->toBe('not exists')
		->and(rawSql(at($where, 2, 1)))->not->toContain('`groupId`=');
});

it('matches no users for groups that do not exist', function (): void {
	expect($this->hub()->users->getQuery(userFilter(['groups' => ['bogus']]))->where)->toBe('0=1');
});

it('narrows the users by how recently they signed in, active users only', function (): void {
	[$or, $recent, $idle, $never] = parts($this->hub()->users->getQuery(userFilter(['logins' => UserFilter::getLogins()]))->where);
	$active = ['users.active' => true, 'users.suspended' => false];

	expect($or)->toBe('or')
		->and(at($recent, 1))->toBe($active)
		->and(at($recent, 2, 0))->toBe('>=')
		->and(at($recent, 2, 1))->toBe('users.lastLoginDate')
		->and($idle)->toBe($this->hub()->users->idleCondition())
		->and(at($idle, 2, 1, 0))->toBe('<')
		// Users who never signed in are idle once they were created before the threshold.
		->and(at($idle, 2, 2, 1))->toBe(['users.lastLoginDate' => null])
		->and(at($idle, 2, 2, 2, 1))->toBe('elements.dateCreated')
		->and($never)->toBe(['and', $active, ['users.lastLoginDate' => null]]);
});

it('narrows the users by two-step verification', function (string $twoFactor, string $operator): void {
	$where = $this->hub()->users->getQuery(userFilter(['twoFactor' => $twoFactor]))->where;
	$condition = $operator === 'not' ? at($where, 1) : $where;

	expect(at($where, 0))->toBe($operator)
		->and(at($condition, 0))->toBe('or')
		->and(rawSql(at($condition, 1, 1)))->toContain('FROM `authenticator`')->toContain('NOT (`hubAuthenticator`.`auth2faSecret` IS NULL)')
		->and(rawSql(at($condition, 2, 1)))->toContain('FROM `webauthn`');
})->with([
	'with' => [UserFilter::TWO_FACTOR_WITH, 'or'],
	'without' => [UserFilter::TWO_FACTOR_WITHOUT, 'not'],
]);

it('narrows the users by the number of entries they are an author of', function (): void {
	$where = $this->hub()->users->getQuery(userFilter(['entriesMin' => 2]))->where;

	expect(at($where, 0))->toBe('and')
		->and(at($where, 1, 0))->toBe('>=')
		->and(rawSql(at($where, 1, 1)))->toContain('`hubAuthors`.`authorId` = `elements`.`id`')->toContain('`hubElements`.`draftId` IS NULL')
		->and(at($where, 1, 2))->toBe(2);
});

it('narrows the users by the number of assets they uploaded', function (): void {
	$where = $this->hub()->users->getQuery(userFilter(['assetsMin' => 1, 'assetsMax' => 3]))->where;

	expect(at($where, 0))->toBe('and')
		->and(at($where, 1, 0))->toBe('>=')
		->and(rawSql(at($where, 1, 1)))->toContain('`hubAssets`.`uploaderId` = `elements`.`id`')->toContain('`hubAssetElements`.`dateDeleted` IS NULL')
		->and(at($where, 1, 2))->toBe(1)
		->and(at($where, 2, 0))->toBe('<=')
		->and(at($where, 2, 2))->toBe(3);
});

it('sorts the users', function (string $sort, int $direction, array $order): void {
	expect($this->hub()->users->getQuery(new UserFilter(), $sort, $direction)->orderBy)->toBe($order);
})->with([
	'by name, falling back to the username' => [UserService::SORT_USER, SORT_ASC, ["(COALESCE(NULLIF([[users.fullName]], ''), [[users.username]]))" => SORT_ASC, 'elements.id' => SORT_ASC]],
	'by email' => [UserService::SORT_EMAIL, SORT_DESC, ['users.email' => SORT_DESC, 'elements.id' => SORT_DESC]],
	'by sign-in, never last' => [UserService::SORT_LOGIN, SORT_ASC, ['(CASE WHEN [[users.lastLoginDate]] IS NULL THEN 1 ELSE 0 END)' => SORT_ASC, 'users.lastLoginDate' => SORT_ASC, 'elements.id' => SORT_ASC]],
]);

it('sorts the users by the number of entries they are an author of', function (): void {
	$order = $this->hub()->users->getQuery(new UserFilter(), UserService::SORT_ENTRIES)->orderBy;

	expect(array_key_first(listOf($order)))->toStartWith('(SELECT COUNT(*)')->toContain('`hubAuthors`.`authorId` = `elements`.`id`')
		->and(array_values(listOf($order)))->toBe([SORT_DESC, SORT_DESC]);
});

it('sorts the users by the number of assets they uploaded', function (): void {
	$order = $this->hub()->users->getQuery(new UserFilter(), UserService::SORT_ASSETS)->orderBy;

	expect(array_key_first(listOf($order)))->toStartWith('(SELECT COUNT(*)')->toContain('`hubAssets`.`uploaderId` = `elements`.`id`')
		->and(array_values(listOf($order)))->toBe([SORT_DESC, SORT_DESC]);
});

it('sorts names ascending, numbers and dates descending', function (): void {
	expect($this->hub()->users->getSortColumns())->toBe([
		'user' => SORT_ASC,
		'email' => SORT_ASC,
		'entries' => SORT_DESC,
		'assets' => SORT_DESC,
		'login' => SORT_DESC,
		'created' => SORT_DESC,
	]);
});

it('finds a user regardless of their status', function (): void {
	$user = new User(['id' => 3]);
	ElementQueries::respond(User::class, fn(UserQuery $query): array => $query->id === 3 && $query->status === null ? [$user] : []);

	expect($this->hub()->users->getUser(3))->toBe($user)
		->and($this->hub()->users->getUser(4))->toBeNull();
});

it('counts the users once', function (): void {
	$this->db()->respond('FROM {{%users}} {{users}}', [[
		'total' => '9', 'active' => '5', 'pending' => '1', 'suspended' => '1', 'locked' => '1', 'inactive' => '2', 'admins' => '2',
		'recentLogin' => '3', 'idle' => '1', 'twoFactor' => '2', 'adminsWithoutTwoFactor' => '1', 'unverifiedEmail' => '1',
	]]);

	$stats = $this->hub()->users->getStatistics();
	$this->hub()->users->getStatistics();
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

it('counts no users without any', function (): void {
	expect($this->hub()->users->getStatistics()->total)->toBe(0);
});

it('lists the groups by name, with their users, permissions and whether they require two-step verification', function (): void {
	$this->db()->respond('{{%usergroups_users}}', [['groupId' => '1', 'count' => '3']]);
	requiring2fa(['admins', 'group-authors']);

	$groups = $this->hub()->users->getGroups();

	expect(array_keys($groups))->toBe([2, 1])
		->and(at($groups, 1, 'group'))->toBe($this->editors)
		->and(at($groups, 1, 'users'))->toBe(3)
		->and(at($groups, 1, 'permissions'))->toBe(2)
		->and(at($groups, 1, 'requires2fa'))->toBeFalse()
		->and(at($groups, 2, 'users'))->toBe(0)
		->and(at($groups, 2, 'requires2fa'))->toBeTrue();
});

it('lists no groups without any', function (): void {
	$this->userGroups = [];

	expect($this->hub()->users->getGroups())->toBe([])
		->and($this->db()->queriesMatching('{{%usergroups_users}}'))->toBe([]);
});

it('requires two-step verification of all users, admins or the users of groups', function (string|array|null $required, bool $admin, array $groups, bool $expected): void {
	Craft::$app->edition = CmsEdition::Pro;
	requiring2fa($required);
	$user = new User(['id' => 3, 'admin' => $admin]);
	$user->setGroups(array_map(fn(mixed $id) => $this->userGroups[asInt($id)], $groups));

	expect($this->hub()->users->requires2fa($user))->toBe($expected);
})->with([
	'all' => ['all', false, [], true],
	'none' => [null, true, [1], false],
	'admins' => [['admins'], true, [], true],
	'not admins' => [['admins'], false, [1], false],
	'a group' => [['group-editors'], false, [1, 2], true],
]);

it('never requires two-step verification on Craft Solo', function (): void {
	requiring2fa('all');

	expect($this->hub()->users->requires2fa(new User(['id' => 3])))->toBeFalse();
});

it('counts the entries the users are an author of', function (): void {
	$this->db()->respond('{{%entries_authors}}', [['authorId' => '3', 'count' => '2']]);

	expect($this->hub()->users->getEntryCounts([3, 4]))->toBe([3 => 2])
		->and($this->db()->queriesMatching('{{%entries_authors}}')[0])->toContain('`hubAuthors`.`authorId` IN (3, 4)')->toContain('`hubElements`.`revisionId` IS NULL')
		->and($this->hub()->users->getEntryCounts([]))->toBe([]);
});

it('counts the assets the users uploaded', function (): void {
	$this->db()->respond('{{%assets}}', [['uploaderId' => '3', 'count' => '5']]);

	expect($this->hub()->users->getAssetCounts([3, 4]))->toBe([3 => 5])
		->and($this->db()->queriesMatching('{{%assets}}')[0])->toContain('`hubAssets`.`uploaderId` IN (3, 4)')->toContain('`hubAssetElements`.`dateDeleted` IS NULL')
		->and($this->hub()->users->getAssetCounts([]))->toBe([]);
});

it('tells which users have set up an authenticator app or a passkey', function (): void {
	$this->db()->respond('{{%authenticator}}', [['userId' => '3']]);
	$this->db()->respond('{{%webauthn}}', [['userId' => '4']]);

	expect($this->hub()->users->getTwoFactor([3, 4, 5]))->toEqualCanonicalizing([3 => true, 4 => true, 5 => false])
		->and($this->db()->queriesMatching('{{%authenticator}}')[0])->toContain('NOT (`auth2faSecret` IS NULL)')
		->and($this->hub()->users->getTwoFactor([]))->toBe([]);
});

it('lists the permissions of a user by heading, with what grants them', function (): void {
	Craft::$app->edition = CmsEdition::Pro;
	$this->db()->respond('{{%userpermissions_users}}', [['name' => 'viewusers'], ['name' => 'legacy:do']]);
	$user = new User(['id' => 3]);
	$user->setGroups([$this->editors]);

	$permissions = $this->hub()->users->getPermissions($user);

	expect(array_column($permissions, 'heading'))->toBe(['General', 'Users', 'Other'])
		->and($permissions[0]['permissions'])->toBe([
			['name' => 'accesscp', 'label' => 'Access the control panel', 'direct' => false, 'groups' => [$this->editors]],
			['name' => 'utility:updates', 'label' => 'Updates', 'direct' => false, 'groups' => [$this->editors]],
		])
		->and($permissions[1]['permissions'][0])->toMatchArray(['name' => 'viewusers', 'direct' => true, 'groups' => []])
		// Permissions Craft no longer knows are listed by their name.
		->and($permissions[2]['permissions'][0])->toMatchArray(['name' => 'legacy:do', 'label' => 'legacy:do']);
});

it('lists the permissions of the team on Craft Team', function (): void {
	Craft::$app->edition = CmsEdition::Team;
	mocked(Craft::$app->getUserGroups())->shouldReceive('getTeamGroup')->andReturn($this->editors);

	$permissions = $this->hub()->users->getPermissions(new User(['id' => 3]));

	expect(array_column(arrayAt($permissions, 0, 'permissions'), 'name'))->toBe(['accesscp', 'utility:updates'])
		->and($permissions[0]['permissions'][0]['groups'])->toBe([$this->editors]);
});

it('lists the permissions of a group by heading', function (): void {
	$permissions = $this->hub()->users->getGroupPermissions($this->editors);

	expect(array_column($permissions, 'heading'))->toBe(['General'])
		->and(array_column(arrayAt($permissions, 0, 'permissions'), 'label'))->toBe(['Access the control panel', 'Updates']);
});

it('lists no permissions of groups without any', function (): void {
	expect($this->hub()->users->getGroupPermissions($this->userGroup(9, 'empty')))->toBe([]);
});

it('lists no permissions of users without any', function (): void {
	$user = new User(['id' => 3]);
	$user->setGroups([]);

	expect($this->hub()->users->getPermissions($user))->toBe([]);
});

it('offers the groups the current user may assign, all of them to admins and none in Craft Solo', function (): void {
	Craft::$app->edition = CmsEdition::Pro;
	$editor = new User(['id' => 5, 'username' => 'editor']);
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn($editor);
	mocked(Craft::$app->getUserPermissions())->shouldReceive('doesUserHavePermission')->andReturnUsing(fn(int $id, string $permission): bool => $permission === 'assignUserGroup:group-authors');

	expect(array_keys($this->hub()->users->getAssignableGroups()))->toBe([2]);

	$editor->admin = true;
	expect(array_keys($this->hub()->users->getAssignableGroups()))->toBe([2, 1]);

	Craft::$app->edition = CmsEdition::Solo;
	expect($this->hub()->users->getAssignableGroups())->toBe([]);
});

it('deletes no users for users who may not delete users', function (): void {
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 5, 'username' => 'editor']));
	mocked(Craft::$app->getUserPermissions())->shouldReceive('doesUserHavePermission')->andReturnFalse();

	expect($this->hub()->users->getDeletableUsers([1, 2]))->toBe([]);
});

it('offers the active users other than those deleted to inherit their content, by name', function (): void {
	ElementQueries::respond(User::class, [new User(['id' => 3, 'username' => 'zed']), new User(['id' => 4, 'username' => 'amy'])]);

	$inheritors = $this->hub()->users->getInheritors([1, 2]);
	$query = ElementQueries::executed(User::class)[0];

	expect(array_map(fn(User $user): ?int => $user->id, $inheritors))->toBe([4, 3])
		->and($query->id)->toBe(['not', 1, 2])
		->and($query->status)->toBe(User::STATUS_ACTIVE);
});

it('counts the users in each group', function (): void {
	$this->db()->respond('FROM {{%usergroups_users}}', [['groupId' => '1', 'count' => '2']]);

	expect($this->hub()->users->getGroupCounts([1, 2]))->toBe([1 => 2])
		->and($this->hub()->users->getGroupCounts([]))->toBe([]);
});
