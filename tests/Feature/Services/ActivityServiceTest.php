<?php

use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\ActivityFilter;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->createContentModel();

	// The table of the activities exists, which the fake connection can't tell.
	$this->activity = Mockery::mock(ActivityService::class)->makePartial()->shouldAllowMockingProtectedMethods();
	$this->activity->shouldReceive('tableExists')->andReturn(true)->byDefault();
	$this->hub()->set('activity', $this->activity);

	$this->row = fn (array $row) => [
		'id' => 1,
		'action' => 'updated',
		'subject' => 'entry',
		'subjectId' => null,
		'subjectTitle' => null,
		'siteId' => null,
		'userId' => null,
		'userName' => null,
		'ipAddress' => null,
		'data' => null,
		'dateCreated' => '2026-10-20 12:00:00',
		...$row,
	];
});

it('records activities of the current user, with their IP address and what else is known', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 4, 'username' => 'jane', 'fullName' => 'Jane Doe']));
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

	try {
		$this->activity->record(ActivityAction::Updated, ActivitySubject::Entry, 5, 'Hello', ['fields' => ['body']], 1);
	} finally {
		unset($_SERVER['REMOTE_ADDR']);
	}

	expect($this->db()->queriesMatching('INSERT INTO {{%hub_activity}}'))->toHaveCount(1)
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_activity}}')[0])
		->toContain("'updated'")
		->toContain("'entry'")
		->toContain("'Hello'")
		->toContain("'Jane Doe'")
		->toContain("'203.0.113.7'")
		->toContain('{\"fields\":[\"body\"]}');
});

it('records activities of the given user, or of none', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 4, 'username' => 'jane']));

	$this->activity->record(ActivityAction::SignedIn, ActivitySubject::User, 7, 'Max', user: new User(['id' => 7, 'username' => 'max']));
	$this->activity->record(ActivityAction::SignInFailed, ActivitySubject::User, null, 'mallory', anonymous: true);

	[$signedIn, $failed] = $this->db()->queriesMatching('INSERT INTO {{%hub_activity}}');

	expect($signedIn)->toContain("'max'")->not->toContain("'jane'")
		->and($failed)->not->toContain("'jane'");
});

it('records nothing while the table is missing, as before its migration ran', function () {
	$this->activity->shouldReceive('tableExists')->andReturn(false);

	$this->activity->record(ActivityAction::Created, ActivitySubject::Entry, 5, 'Hello');

	expect($this->db()->queriesMatching('{{%hub_activity}}'))->toBe([]);
});

it('never fails what was done for an activity that couldn’t be recorded', function () {
	$this->db()->respond('INSERT INTO {{%hub_activity}}', fn () => throw new yii\db\Exception('Table is gone'));

	$this->activity->record(ActivityAction::Created, ActivitySubject::Entry, 5, 'Hello');
})->throwsNoExceptions();

it('narrows the activities by the filters', function () {
	$filter = new ActivityFilter();
	$filter->search = 'jane';
	$filter->actions = ['restored', 'exported'];
	$filter->subjects = ['entry'];
	$filter->users = ['4', ActivityFilter::USER_NONE];
	$filter->dateFrom = new DateTime('2026-10-01');

	$sql = rawSql($this->activity->getQuery($filter));

	expect($sql)
		->toContain("LIKE '%jane%'")
		->toContain("`action` IN ('restored', 'reverted', 'downloaded', 'exported')")
		->toContain("`subject`='entry'")
		->toContain('(`userId`=4) OR (`userId` IS NULL)')
		->toContain('`dateCreated` >=')
		->toContain('ORDER BY `dateCreated` DESC, `id` DESC');
});

it('sorts the activities by their user, subject or action, keeping the order they happened in', function (string $sort, string $order) {
	expect(rawSql($this->activity->getQuery(new ActivityFilter(), $sort, SORT_ASC)))->toContain("ORDER BY $order");
})->with([
	[ActivityService::SORT_USER, '`userName`, `id` DESC'],
	[ActivityService::SORT_SUBJECT, '`subjectTitle`, `id` DESC'],
	[ActivityService::SORT_ACTION, '`action`, `id` DESC'],
]);

it('populates the activities with their users and links to the subjects that still exist', function () {
	$this->request();
	$jane = new User(['id' => 4, 'siteId' => 1, 'username' => 'jane']);
	ElementQueries::respond(User::class, [$jane]);
	$this->db()->respond('FROM {{%elements}}', [['id' => 5]]);

	$activities = $this->activity->populate([
		($this->row)(['id' => 3, 'subjectId' => 5, 'subjectTitle' => 'Hello', 'userId' => 4, 'userName' => 'Jane', 'data' => '{"fields":["summary"],"attributes":["title"]}', 'siteId' => 1]),
		($this->row)(['id' => 2, 'action' => 'deleted', 'subjectId' => 6, 'userId' => 9, 'userName' => 'Gone']),
		($this->row)(['id' => 1, 'action' => 'unknown']),
		($this->row)(['id' => 4, 'subject' => 'section', 'subjectId' => 1, 'subjectTitle' => 'News']),
	]);

	expect(array_map(fn ($activity) => $activity->id, $activities))->toBe([3, 2, 4])
		->and($activities[0]->user)->toBe($jane)
		->and($activities[0]->url)->toContain('hub/data/entries/5')
		->and($activities[0]->getChanges())->toBe(['Title', 'Summary'])
		->and($activities[0]->getSite()?->handle)->toBe('en')
		->and($activities[1]->user)->toBeNull()
		->and($activities[1]->userName)->toBe('Gone')
		->and($activities[1]->url)->toBeNull()
		->and($activities[2]->url)->toContain('hub/structure/sections/1');
});

it('populates batches with their activities matching the filters, or all of them when the batch matches itself', function () {
	$this->request();
	// The second batch matches the search by its own name, the first only by its activities.
	$this->db()->respond('FROM {{%hub_activity}}', function (string $sql) {
		$filtered = str_contains($sql, 'hello');

		return match (true) {
			str_starts_with($sql, "SELECT `id`\n") => [['id' => 2]],
			str_contains($sql, 'GROUP BY') => $filtered ? [['batchId' => 1, 'count' => 3]] : [['batchId' => 2, 'count' => 7]],
			default => [($this->row)(['id' => 10, 'batchId' => $filtered ? 1 : 2])],
		};
	});
	$filter = new ActivityFilter();
	$filter->search = 'hello';

	$activities = $this->activity->populate([
		($this->row)(['id' => 1, 'action' => 'bulk', 'subjectTitle' => 'Delete']),
		($this->row)(['id' => 2, 'action' => 'applied', 'subject' => 'projectConfig', 'subjectTitle' => 'Hello config']),
	], $filter);

	expect($activities[0]->batchCount)->toBe(3)
		->and($activities[0]->batchFiltered)->toBeTrue()
		->and($activities[1]->batchCount)->toBe(7)
		->and($activities[1]->batchFiltered)->toBeFalse()
		->and($activities[1]->batched)->toHaveCount(1);
});

it('links to the edit pages of elements without a Hub page, and to the settings of others for admins only', function (bool $admin) {
	$this->request();
	$this->actingAs(admin: $admin);
	$this->db()->respond('FROM {{%elements}}', [['id' => 8]]);
	$group = $this->userGroup(3, 'editors');
	Craft::$app->getUserGroups()->shouldReceive('getGroupById')->with(3)->andReturn($group);

	$activities = $this->activity->populate([
		($this->row)(['id' => 1, 'subject' => 'category', 'subjectId' => 8]),
		($this->row)(['id' => 2, 'subject' => 'userGroup', 'subjectId' => 3]),
	]);

	expect($activities[0]->url)->toContain('edit/8')
		->and($activities[1]->url === null)->toBe(!$admin);
})->with([true, false]);

it('links to the Hub pages of users and the structure only for users who may view them', function () {
	$this->request();
	$this->actingAs(permissions: [Hub::PERMISSION_ACCESS_DATA, Page::Entries->viewPermission(), Hub::PERMISSION_ACCESS_STRUCTURE, Page::Fields->viewPermission()]);
	$this->db()->respond('FROM {{%elements}}', [['id' => 4]]);
	ElementQueries::respond(User::class, []);

	$activities = $this->activity->populate([
		($this->row)(['id' => 2, 'subject' => 'user', 'subjectId' => 4]),
		($this->row)(['id' => 1, 'subject' => 'section', 'subjectId' => 1, 'subjectTitle' => 'News']),
	]);

	expect($activities[0]->url)->toContain('edit/4')->not->toContain('hub/data/users')
		->and($activities[1]->url)->toBeNull();
});

it('offers the users of any activity to filter by, by their latest names', function () {
	$this->db()->respond('MAX([[id]])', [['userId' => 9, 'userName' => 'Zoe'], ['userId' => 4, 'userName' => 'Jane (old)']]);
	ElementQueries::respond(User::class, [new User(['id' => 4, 'siteId' => 1, 'username' => 'jane', 'fullName' => 'Jane Doe'])]);

	$options = $this->activity->getUserOptions();

	expect(array_keys($options))->toBe([4, 9])
		->and($options[4]['label'])->toBe('Jane Doe')
		->and($options[9])->toBe(['label' => 'Zoe', 'user' => null]);
});

it('deletes the expired activities, unless they are kept forever', function (int $days, int $deletes) {
	Hub::getInstance()->getSettings()->activityRetentionDays = $days;

	$this->activity->deleteExpired();

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_activity}}'))->toHaveCount($deletes);
})->with([[90, 1], [0, 0]]);

it('reads the permissions granted to a user directly', function () {
	$this->db()->respond('{{%userpermissions_users}}', [['name' => 'accesscp'], ['name' => 'editentries']]);

	expect($this->activity->getUserPermissions(4))->toBe(['accesscp', 'editentries']);
});

it('shows the activity log to users who may view it while it is enabled', function (bool $enabled, array $permissions, bool $canView) {
	$this->hub()->getSettings()->enableActivity = $enabled;
	$this->actingAs(permissions: $permissions);

	expect($this->activity->canView())->toBe($canView);
})->with([
	[true, [Hub::PERMISSION_VIEW_ACTIVITY], true],
	[true, [Hub::PERMISSION_ACCESS_DATA], false],
	[false, [Hub::PERMISSION_VIEW_ACTIVITY], false],
]);
