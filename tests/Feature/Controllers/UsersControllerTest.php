<?php

use craft\elements\Asset;
use craft\elements\db\UserQuery;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\UsersController;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\UserService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->userGroup(1, 'editors', ['accessCp']);

	// The lists of single users, not the subqueries of the counts.
	$this->db()->respond('{{%entries_authors}}', fn (string $sql) => str_starts_with($sql, 'SELECT `hubAuthors`.`authorId`') ? [['authorId' => '3', 'count' => '5']] : []);
	$this->db()->respond('{{%authenticator}}', fn (string $sql) => str_starts_with($sql, 'SELECT `userId`') ? [['userId' => '4']] : []);
	$this->db()->respond('FROM {{%users}} {{users}}', fn (string $sql) => str_contains($sql, 'JOIN `usergroups_users`')
		? [['groupId' => '1', 'count' => '2']]
		: [[
			'total' => '6', 'active' => '4', 'pending' => '1', 'suspended' => '0', 'locked' => '1', 'inactive' => '1', 'admins' => '1',
			'recentLogin' => '2', 'idle' => '1', 'twoFactor' => '1', 'adminsWithoutTwoFactor' => '1', 'unverifiedEmail' => '2',
		]]);
});

/**
 * Headings of the checks, failed ones only unless said otherwise.
 *
 * @param Check[] $checks
 * @return string[]
 */
function checkHeadings(array $checks, bool $failed = true): array
{
	return array_values(array_map(fn (Check $check) => $check->heading, array_filter($checks, fn (Check $check) => !$failed || !$check->passed)));
}

/**
 * Users with the given IDs, the last one an admin.
 *
 * @return User[]
 */
function listedUsers(int ...$ids): array
{
	return array_map(fn (int $id) => new User([
		'id' => $id,
		'siteId' => 1,
		'username' => "user$id",
		'active' => true,
		'admin' => $id === max($ids),
		'lastLoginDate' => new DateTime('-1 day'),
	]), $ids);
}

it('lists the users', function () {
	$users = listedUsers(3, 4);
	ElementQueries::respond(User::class, $users);

	$response = $this->runAction(UsersController::class, 'index');
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/users/index')
		->and($variables['users'])->toBe($users)
		->and($variables['pageInfo']->total)->toBe(2)
		->and($variables['entryCounts'])->toBe([3 => 5])
		->and($variables['twoFactor'])->toEqualCanonicalizing([3 => false, 4 => true])
		->and(array_keys($variables['groups']))->toBe([1])
		->and(array_keys($variables['statuses']))->toBe(['active', 'pending', 'suspended', 'locked', 'inactive'])
		->and($variables['idleMonths'])->toBe(12)
		->and(array_column($variables['columns'], 'key'))->toContain('groups')
		->and($variables['sort'])->toBeNull();

	// Photos are shown with all users, and groups with the column showing them.
	$query = ElementQueries::executed(User::class)[0];
	expect($query->with)->toBe(['photo'])
		->and($query->withGroups)->toBeTrue();
});

it('reads the filters of the users list', function () {
	$filter = $this->runAction(UsersController::class, 'index', query: [
		'q' => ' jane ',
		'group' => 'editors,none,bogus',
		'status' => 'active,bogus',
		'admin' => '1',
		'login' => 'idle,bogus',
		'loginFrom' => '2024-01-01',
		'createdTo' => '2024-02-01',
		'twoFactor' => 'without',
		'unverified' => '1',
		'entriesMin' => '2',
		'entriesMax' => 'many',
	])->variables['filter'];

	expect($filter->search)->toBe('jane')
		->and($filter->groups)->toBe(['editors', 'none'])
		->and($filter->statuses)->toBe(['active'])
		->and($filter->admin)->toBeTrue()
		->and($filter->logins)->toBe(['idle'])
		->and($filter->loginFrom->format('Y-m-d'))->toBe('2024-01-01')
		->and($filter->createdTo->format('Y-m-d'))->toBe('2024-02-01')
		->and($filter->twoFactor)->toBe('without')
		->and($filter->unverifiedEmail)->toBeTrue()
		->and($filter->entriesMin)->toBe(2)
		->and($filter->entriesMax)->toBeNull();
});

it('offers neither groups nor their column without any groups', function () {
	$this->userGroups = [];
	ElementQueries::respond(User::class, listedUsers(3));

	$variables = $this->runAction(UsersController::class, 'index', query: ['group' => 'none'])->variables;

	expect($variables['filter']->groups)->toBe([])
		->and(array_column($variables['columns'], 'key'))->not->toContain('groups')
		->and(ElementQueries::executed(User::class)[0]->withGroups)->toBeFalse();
});

it('leaves out the data of hidden columns', function () {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_USERS => ['order' => [], 'hidden' => ['entries', 'twoFactor', 'groups']]]];
	$users = Mockery::mock(UserService::class)->makePartial();
	$users->shouldNotReceive('getEntryCounts', 'getTwoFactor');
	$this->hub()->set('users', $users);
	ElementQueries::respond(User::class, listedUsers(3));

	$variables = $this->runAction(UsersController::class, 'index')->variables;

	expect($variables['entryCounts'])->toBe([])
		->and($variables['twoFactor'])->toBe([])
		->and(ElementQueries::executed(User::class)[0]->withGroups)->toBeFalse();
});

it('summarizes the users', function () {
	$cards = loadCards($this->runAction(UsersController::class, 'statistics')->variables['cards']);
	[$users, $groups, $logins, $twoFactor] = $cards;

	expect($cards)->toHaveCount(4)
		->and($users['total'])->toBe(6)
		->and(array_column($users['segments'], 'value', 'label'))->toBe(['Active' => 4, 'Pending' => 1, 'Suspended' => 0, 'Inactive' => 1, 'Locked' => 1])
		->and($users['segments'][4]['bar'])->toBeFalse()
		->and($users['segments'][1]['url'])->toContain('hub/data/users')->toContain('status=pending')
		->and(array_column($users['details'], 'value', 'label'))->toBe(['Admins' => 1, 'Unverified email addresses' => 2])
		->and(array_column($groups['table']['rows'], 'cells', 'label')['Editors'][0])->toBeInt()
		->and(array_column($groups['segments'], 'value', 'label'))->toBe(['Editors' => 2])
		->and($groups['segments'][0]['url'])->toContain('group=editors')
		->and(array_column($logins['segments'], 'value', 'label'))->toBe(['Recently' => 2, 'Idle' => 1, 'Not yet' => 1])
		->and($logins['segments'][1]['url'])->toContain('login=idle')
		->and($twoFactor['total'])->toBe(4)
		->and(array_column($twoFactor['segments'], 'value'))->toBe([1, 3])
		->and($twoFactor['segments'][1]['url'])->toContain('twoFactor=without');
});

it('summarizes the users without groups', function () {
	$this->userGroups = [];

	$labels = array_column(loadCards($this->runAction(UsersController::class, 'statistics')->variables['cards']), 'label');

	expect($labels)->toBe(['Users', 'Sign-ins', 'Two-step verification']);
});

it('lists the users to do, the ones to do first, regardless of the selected sites', function () {
	$this->db()->respondToCounts(fn (UserQuery $query) => [['total' => $query->status === [User::STATUS_PENDING] ? 2 : 0]]);

	$variables = $this->runAction(UsersController::class, 'diagnostics')->variables;

	expect(array_column($variables['tasks'], 'value', 'label'))->toBe([
		'Pending users' => 2,
		'Locked users' => 0,
		'Admins without two-step verification' => 0,
		'Idle users' => 0,
		'Suspended users' => 0,
		'Users with unverified email' => 0,
	])
		->and($variables['tasks'][0]['severity'])->toBe(Severity::Notice)
		->and($variables['tasks'][2]['url'])->toContain('admin=1')->toContain('twoFactor=without')
		->and($variables['tasks'][5]['url'])->toContain('unverified=1')
		->and($variables['siteScoped'])->toBeFalse();
});

it('shows a user', function () {
	[$user] = listedUsers(3);
	ElementQueries::respond(User::class, fn (UserQuery $query) => $query->id === 3 ? [$user] : []);

	$response = $this->runAction(UsersController::class, 'view', ['id' => 3]);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/users/view')
		->and($variables['user'])->toBe($user)
		->and($variables['selectedTab'])->toBe('overview')
		->and($variables['groups'])->toBe([])
		->and($variables['permissions'])->toBe([])
		->and($variables['references'])->toBe([])
		->and($variables['twoFactor'])->toBeFalse()
		->and($variables['requires2fa'])->toBeFalse()
		->and(checkHeadings($variables['checks'], failed: false))->toBe(['Locked', 'Two-step verification', 'Sign-ins', 'Pending', 'Suspended', 'Email'])
		->and($variables)->not->toHaveKeys(['entries', 'assets']);
});

it('does not list the permissions of admins', function () {
	$user = new User(['id' => 3, 'siteId' => 1, 'admin' => true]);
	ElementQueries::respond(User::class, [$user]);
	$users = Mockery::mock(UserService::class)->makePartial();
	$users->shouldNotReceive('getPermissions');
	$this->hub()->set('users', $users);

	expect($this->runAction(UsersController::class, 'view', ['id' => 3])->variables['permissions'])->toBe([]);
});

it('lists the entries of a user on a tab of their own', function () {
	[$user] = listedUsers(3);
	ElementQueries::respond(User::class, [$user]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1, 'title' => 'News', 'sectionId' => 1, 'typeId' => 10])]);

	$variables = $this->runAction(UsersController::class, 'view', ['id' => 3, 'tab' => 'entries'], ['author' => '4'])->variables;

	expect($variables['selectedTab'])->toBe('entries')
		->and($variables['listFixed'])->toBe('author')
		->and($variables['listPath'])->toBe('hub/data/users/3/entries')
		->and($variables['filter']->authors)->toBe([3])
		->and($variables['authorOptions'])->toBe([])
		->and($variables['entries'])->toHaveCount(1)
		->and($variables['checks'])->not->toBeEmpty()
		->and($variables)->not->toHaveKeys(['permissions', 'references', 'assets']);
});

it('lists the files a user uploaded on a tab of their own', function () {
	[$user] = listedUsers(3);
	ElementQueries::respond(User::class, [$user]);
	$asset = new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg', 'size' => 10]);
	ElementQueries::respond(Asset::class, [$asset]);

	$variables = $this->runAction(UsersController::class, 'view', ['id' => 3, 'tab' => 'assets'])->variables;

	expect($variables['selectedTab'])->toBe('assets')
		->and($variables['listFixed'])->toBe('uploader')
		->and($variables['listPath'])->toBe('hub/data/users/3/assets')
		->and($variables['filter']->uploaders)->toBe([3])
		->and($variables['uploaderOptions'])->toBe([])
		->and($variables['assets'])->toBe([$asset])
		->and($variables)->not->toHaveKeys(['permissions', 'references', 'entries']);
});

it('fails for tabs that users do not have', function () {
	ElementQueries::respond(User::class, listedUsers(3));

	$this->runAction(UsersController::class, 'view', ['id' => 3, 'tab' => 'transforms']);
})->throws(NotFoundHttpException::class);

it('fails for users that do not exist', function () {
	$this->runAction(UsersController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);

it('lists the user groups on a tab of their own', function () {
	$response = $this->runAction(UsersController::class, 'groups');
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/users/groups')
		->and(array_keys($variables['groups']))->toBe([1])
		->and($variables['groups'][1]['users'])->toBe(2)
		->and($variables['pageInfo']->total)->toBe(1)
		->and($variables['listPath'])->toBe('hub/data/users/groups')
		->and(array_column($variables['columns'], 'key'))->toBe(['description', 'users', 'permissions', 'twoFactor', 'settings'])
		->and($variables['sort'])->toBeNull()
		->and($variables['extraTabs'])->toBe(['groups' => 'Groups']);

	// The list and the diagnostics offer the tab too.
	expect($this->runAction(UsersController::class, 'index')->variables['extraTabs'])->toBe(['groups' => 'Groups'])
		->and($this->runAction(UsersController::class, 'diagnostics')->variables['extraTabs'])->toBe(['groups' => 'Groups']);
});

it('offers no groups tab without groups', function () {
	$this->userGroups = [];

	expect($this->runAction(UsersController::class, 'index')->variables['extraTabs'])->toBe([])
		->and(fn () => $this->runAction(UsersController::class, 'groups'))->toThrow(NotFoundHttpException::class);
});

it('filters the user groups', function () {
	$this->userGroup(2, 'guests');

	$variables = $this->runAction(UsersController::class, 'groups', [], ['empty' => '1', 'twoFactor' => 'optional', 'usersMin' => 'x', 'sort' => 'users'])->variables;

	expect(array_keys($variables['groups']))->toBe([2])
		->and($variables['filter']->empty)->toBeTrue()
		->and($variables['filter']->twoFactor)->toBe('optional')
		->and($variables['filter']->usersMin)->toBeNull()
		->and($variables['sort'])->toBe('users');
});

it('requires the permission to view the users', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Users->labelPermission(), Hub::PERMISSION_ACCESS_STRUCTURE, Page::Sections->viewPermission()]);

	$this->runAction(UsersController::class, 'index');
})->throws(ForbiddenHttpException::class);
