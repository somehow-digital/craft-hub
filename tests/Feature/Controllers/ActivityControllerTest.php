<?php

declare(strict_types=1);

use craft\elements\User;
use craft\web\twig\variables\Paginate;
use SomehowDigital\Craft\Hub\controllers\ActivityController;
use SomehowDigital\Craft\Hub\enums\ActivityGroup;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\models\Activity;
use SomehowDigital\Craft\Hub\models\ActivityFilter;
use SomehowDigital\Craft\Hub\services\ActivityFeedService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function (): void {
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

it('lists the activities, newest first, leaving the options of the users filter to be loaded', function (): void {
	$variables = $this->runAction(ActivityController::class, 'index')->variables;

	expect(array_map(fn(Activity $activity): int => $activity->id, objectsAt(Activity::class, $variables, 'list', 'activities')))->toBe([2, 1])
		->and(at($variables, 'list', 'activities', 0, 'user', 'username'))->toBe('jane')
		->and(objectAt(Paginate::class, $variables, 'list', 'pageInfo')->total)->toBe(2)
		->and(at($variables, 'list', 'listPath'))->toBe('hub/manage/activity')
		->and(at($variables, 'list', 'userOptions'))->toBe([])
		->and(at($variables, 'list', 'optionsUrls', 'user'))->toContain('filter=user')
		->and($this->db()->queriesMatching('MAX([[id]])'))->toBe([])
		->and(at($variables, 'list', 'actions'))->toBe(ActivityGroup::cases())
		->and(at($variables, 'list', 'retentionDays'))->toBe(90)
		->and(at($variables, 'list', 'sort'))->toBeNull()
		->and(array_column(arrayAt($variables, 'list', 'columns'), 'key'))->toBe(['action', 'user', 'details', 'site', 'ip', 'date', 'batch']);
});

it('filters the activities by the query params, ignoring unknown values', function (): void {
	$variables = $this->runAction(ActivityController::class, 'index', query: [
		'event' => 'deleted,bogus',
		'type' => 'entry,user',
		'user' => '4,7,none',
		'dateFrom' => '2026-10-01',
		'dateTo' => 'yesterday',
		'q' => ' hello ',
		'sort' => 'user',
	])->variables;

	/** @var ActivityFilter $filter */
	$filter = at($variables, 'list', 'filter');

	expect($filter->actions)->toBe(['deleted'])
		->and($filter->subjects)->toBe(['entry', 'user'])
		->and($filter->users)->toBe(['4', 'none'])
		->and(array_keys(arrayAt($variables, 'list', 'userOptions')))->toBe([4, ActivityFilter::USER_NONE])
		->and($filter->dateFrom?->format('Y-m-d'))->toBe('2026-10-01')
		->and($filter->dateTo)->toBeNull()
		->and($filter->search)->toBe('hello')
		->and(at($variables, 'list', 'sort'))->toBe(ActivityFeedService::SORT_USER)
		->and($this->db()->queriesMatching("`action` IN ('deleted', 'uninstalled', 'transformsDeleted', 'draftDeleted')"))->not->toBe([]);
});

it('leaves out the site column on single-site installs', function (): void {
	unset($this->sites[2]);
	Craft::$app->getIsMultiSite(refresh: true);

	$variables = $this->runAction(ActivityController::class, 'index')->variables;

	expect(array_column(arrayAt($variables, 'list', 'columns'), 'key'))->not->toContain('site');
});

it('shows no activity log while it is disabled', function (): void {
	$this->hub()->settings->enableActivity = false;

	$this->runAction(ActivityController::class, 'index');
})->throws(NotFoundHttpException::class);

it('shows the activity log only to users who may view it', function (): void {
	$this->actingAs(permissions: [Permission::AccessData->value, Permission::ManageLabels->value]);

	$this->runAction(ActivityController::class, 'index');
})->throws(ForbiddenHttpException::class);

it('redirects the activity log to its kept filters', function (): void {
	$this->session['hub:filters:hub/manage/activity'] = ['action' => 'created'];
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

	Craft::createObject(ActivityController::class, ['test', $this->hub()])->runAction('index');

	expect(webResponse()->getHeaders()->get('Location'))->toContain('hub/manage/activity&action=created');
});

it('lists no batch that does not exist', function (): void {
	$this->db()->respond('FROM {{%hub_activity}}', []);

	$this->runAction(ActivityController::class, 'index', query: ['batch' => '99']);
})->throws(NotFoundHttpException::class);
