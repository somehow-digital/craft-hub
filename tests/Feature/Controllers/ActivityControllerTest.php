<?php

use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\ActivityController;
use SomehowDigital\Craft\Hub\enums\ActivityGroup;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\ActivityFilter;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->createContentModel();

	$this->db()->respond('FROM {{%hub_activity}}', [
		['id' => 2, 'action' => 'created', 'subject' => 'entry', 'subjectId' => 5, 'subjectTitle' => 'Hello', 'siteId' => 1, 'userId' => 4, 'userName' => 'Jane', 'ipAddress' => '203.0.113.7', 'data' => null, 'dateCreated' => '2026-10-20 12:00:00'],
		['id' => 1, 'action' => 'signInFailed', 'subject' => 'user', 'subjectId' => null, 'subjectTitle' => 'mallory', 'siteId' => null, 'userId' => null, 'userName' => null, 'ipAddress' => null, 'data' => '{"reason":"invalid_credentials"}', 'dateCreated' => '2026-10-19 12:00:00'],
	]);
	$this->db()->respond('COUNT(*)', 2);
	$this->db()->respond('GROUP BY [[batchId]]', []);
	$this->db()->respond('MAX([[id]])', [['userId' => 4, 'userName' => 'Jane'], ['userId' => 9, 'userName' => 'Gone']]);

	ElementQueries::respond(User::class, [new User(['id' => 4, 'siteId' => 1, 'username' => 'jane', 'fullName' => 'Jane Doe'])]);
});

it('lists the activities, newest first, with the users to filter by in the order of their names', function () {
	$variables = $this->runAction(ActivityController::class, 'index')->variables;

	expect(array_map(fn ($activity) => $activity->id, $variables['activities']))->toBe([2, 1])
		->and($variables['activities'][0]->user?->username)->toBe('jane')
		->and($variables['pageInfo']->total)->toBe(2)
		->and($variables['listPath'])->toBe('hub/manage/activity')
		->and(array_keys($variables['userOptions']))->toBe([9, 4, ActivityFilter::USER_NONE])
		->and($variables['userOptions'][4]['user'])->toBeInstanceOf(User::class)
		->and($variables['userOptions'][9]['icon'])->toBe('user-slash')
		->and($variables['actions'])->toBe(ActivityGroup::cases())
		->and($variables['retentionDays'])->toBe(90)
		->and($variables['sort'])->toBeNull()
		->and(array_column($variables['columns'], 'key'))->toBe(['action', 'user', 'details', 'site', 'ip', 'date', 'batch']);
});

it('filters the activities by the query params, ignoring unknown values', function () {
	$variables = $this->runAction(ActivityController::class, 'index', query: [
		'event' => 'deleted,bogus',
		'type' => 'entry,user',
		'user' => '4,7,none',
		'dateFrom' => '2026-10-01',
		'dateTo' => 'yesterday',
		'q' => ' hello ',
		'sort' => 'user',
	])->variables;

	$filter = $variables['filter'];

	expect($filter->actions)->toBe(['deleted'])
		->and($filter->subjects)->toBe(['entry', 'user'])
		->and($filter->users)->toBe(['4', 'none'])
		->and($filter->dateFrom?->format('Y-m-d'))->toBe('2026-10-01')
		->and($filter->dateTo)->toBeNull()
		->and($filter->search)->toBe('hello')
		->and($variables['sort'])->toBe(ActivityService::SORT_USER)
		->and($this->db()->queriesMatching("`action` IN ('deleted', 'uninstalled', 'transformsDeleted', 'draftDeleted')"))->not->toBe([]);
});

it('leaves out the site column on single-site installs', function () {
	unset($this->sites[2]);
	Craft::$app->getIsMultiSite(refresh: true);

	$variables = $this->runAction(ActivityController::class, 'index')->variables;

	expect(array_column($variables['columns'], 'key'))->not->toContain('site');
});

it('shows no activity log while it is disabled', function () {
	$this->hub()->getSettings()->enableActivity = false;

	$this->runAction(ActivityController::class, 'index');
})->throws(NotFoundHttpException::class);

it('shows the activity log only to users who may view it', function () {
	$this->actingAs(permissions: [Hub::PERMISSION_ACCESS_DATA, Hub::PERMISSION_MANAGE_LABELS]);

	$this->runAction(ActivityController::class, 'index');
})->throws(ForbiddenHttpException::class);

it('redirects the activity log to its kept filters', function () {
	$this->session['hub:filters:hub/manage/activity'] = ['action' => 'created'];
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

	Craft::createObject(ActivityController::class, ['test', $this->hub()])->runAction('index');

	expect(Craft::$app->getResponse()->getHeaders()->get('Location'))->toContain('hub/manage/activity&action=created');
});

it('lists no batch that does not exist', function () {
	$this->db()->respond('FROM {{%hub_activity}}', []);

	$this->runAction(ActivityController::class, 'index', query: ['batch' => '99']);
})->throws(NotFoundHttpException::class);
