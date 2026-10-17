<?php

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\BulkController;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

beforeEach(function () {
	$this->identity = new User(['id' => 1, 'username' => 'admin', 'admin' => true]);

	// Posts the body params to the action applying bulk actions as the signed-in user, asking for JSON.
	$this->apply = function (array $body): Response {
		$this->request();
		$request = Craft::$app->getRequest();
		$request->enableCsrfValidation = false;
		$request->enableCookieValidation = false;
		$request->setBodyParams($body);
		$request->getHeaders()->set('Accept', 'application/json');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);

		try {
			return Craft::createObject(BulkController::class, ['bulk', $this->hub()])->runAction('apply');
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}
	};
});

it('offers the actions of each list', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);
	$bulk = $this->hub()->bulk;

	expect(array_keys($bulk->getActions(ColumnService::LIST_ENTRIES)))->toBe([BulkService::ACTION_ENABLE, BulkService::ACTION_DISABLE, BulkService::ACTION_DELETE])
		->and(array_keys($bulk->getActions(ColumnService::LIST_FILES)))->toBe([BulkService::ACTION_DOWNLOAD, BulkService::ACTION_DELETE_TRANSFORMS, BulkService::ACTION_DELETE])
		->and(array_keys($bulk->getActions(ColumnService::LIST_USERS)))->toBe([BulkService::ACTION_SUSPEND, BulkService::ACTION_UNSUSPEND])
		->and(array_keys($bulk->getActions(ColumnService::LIST_SECTIONS)))->toBe([BulkService::ACTION_DELETE])
		->and($bulk->getActions(ColumnService::LIST_SECTIONS)[BulkService::ACTION_DELETE])->toHaveKey('confirm')
		->and($bulk->getActions('bogus'))->toBe([]);
});

it('offers deleting structure only to admins where admin changes are allowed', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 2, 'username' => 'editor']));
	$bulk = $this->hub()->bulk;

	expect($bulk->getActions(ColumnService::LIST_SECTIONS))->toBe([])
		->and($bulk->getActions(ColumnService::LIST_FIELDS))->toBe([])
		->and($bulk->getActions(ColumnService::LIST_USER_GROUPS))->toBe([]);

	Craft::$app->getConfig()->getGeneral()->allowAdminChanges = false;

	try {
		$this->identity = new User(['id' => 1, 'username' => 'admin', 'admin' => true]);
		Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);

		expect($bulk->getActions(ColumnService::LIST_VOLUMES))->toBe([]);
	} finally {
		Craft::$app->getConfig()->getGeneral()->allowAdminChanges = true;
	}
});

it('deletes the selected entries the user may delete', function () {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1])]);
	$elements = Craft::$app->getElements();
	$elements->shouldReceive('canDelete')->andReturnUsing(fn (Entry $entry) => $entry->id === 1);
	$elements->shouldReceive('deleteElement')->once()->andReturn(true);

	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'delete', 'ids' => ['1', '2', 'bogus']]);

	expect($response->data['count'])->toBe(1)
		->and($response->data['skipped'])->toBe(1)
		->and($response->data['message'])->toContain('applied to one item')
		->and($response->data['message'])->toContain('One item was skipped');
});

it('disables and enables the selected entries', function () {
	$entry = new Entry(['id' => 1, 'siteId' => 1, 'enabled' => true]);
	ElementQueries::respond(Entry::class, [$entry]);
	Craft::$app->getElements()->shouldReceive('saveElement')->twice()->andReturn(true);

	($this->apply)(['list' => 'entries', 'bulkAction' => 'disable', 'ids' => ['1']]);
	expect($entry->enabled)->toBeFalse();

	($this->apply)(['list' => 'entries', 'bulkAction' => 'enable', 'ids' => ['1']]);
	expect($entry->enabled)->toBeTrue()
		->and($entry->getEnabledForSite())->toBeTrue()
		->and($entry->getScenario())->toBe(Entry::SCENARIO_LIVE);
});

it('deletes the transforms of the selected files', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);
	$row = ['id' => '11', 'assetId' => '1', 'transformer' => 'other\\Transformer', 'filename' => 'a.jpg', 'format' => null, 'transformString' => '_100x100', 'fileExists' => '1', 'inProgress' => '0', 'error' => '0', 'dateIndexed' => null];
	$this->db()->respond('SELECT `id`, `assetId`', [$row]);

	$response = ($this->apply)(['list' => 'files', 'bulkAction' => 'deleteTransforms', 'ids' => ['1']]);

	expect($response->data['count'])->toBe(1)
		->and($this->db()->queriesMatching('DELETE FROM {{%imagetransformindex}}')[0])->toContain('`id`=11');
});

it('suspends the selected users, but not the current one', function () {
	ElementQueries::respond(User::class, [new User(['id' => 1, 'username' => 'admin']), new User(['id' => 2, 'username' => 'editor'])]);
	$users = Craft::$app->getUsers();
	$users->shouldReceive('canSuspend')->andReturn(true);
	$users->shouldReceive('suspendUser')->once()->withArgs(fn (User $user) => $user->id === 2);

	$response = ($this->apply)(['list' => 'users', 'bulkAction' => 'suspend', 'ids' => ['1', '2']]);

	expect($response->data['count'])->toBe(1)
		->and($response->data['skipped'])->toBe(1);
});

it('deletes the selected sections', function () {
	Craft::$app->getEntries()->shouldReceive('deleteSectionById')->twice()->andReturnUsing(fn (int $id) => $id === 3);

	$response = ($this->apply)(['list' => 'sections', 'bulkAction' => 'delete', 'ids' => ['3', '4']]);

	expect($response->data['count'])->toBe(1);
});

it('fails when the action applies to none of the selected items', function () {
	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'delete', 'ids' => []]);

	expect($response->getStatusCode())->toBe(400)
		->and($response->data['message'])->toContain('couldn’t be applied');
});

it('rejects actions the list does not offer', function () {
	($this->apply)(['list' => 'users', 'bulkAction' => 'delete', 'ids' => ['1']]);
})->throws(BadRequestHttpException::class);

it('rejects deleting structure for users who are no admins', function () {
	$this->identity = new User(['id' => 2, 'username' => 'editor']);

	($this->apply)(['list' => 'fields', 'bulkAction' => 'delete', 'ids' => ['1']]);
})->throws(BadRequestHttpException::class);

it('requires a post request to apply bulk actions', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);

	Craft::createObject(BulkController::class, ['bulk', $this->hub()])->runAction('apply');
})->throws(MethodNotAllowedHttpException::class);
