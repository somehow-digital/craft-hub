<?php

use craft\elements\User;
use craft\models\Section;
use craft\services\Entries;
use SomehowDigital\Craft\Hub\controllers\MaintainersController;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\MaintainerService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

beforeEach(function () {
	$this->createContentModel();
	$this->hub()->getSettings()->enableMaintainers = true;

	// Posts the body params to the action as a signed-in user, asking for JSON.
	$this->post = function (array $body): ?Response {
		$this->request();
		$request = Craft::$app->getRequest();
		$request->enableCsrfValidation = false;
		$request->enableCookieValidation = false;
		$request->setBodyParams($body);
		$request->getHeaders()->set('Accept', 'application/json');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => $this->isAdmin]));

		try {
			return Craft::createObject(MaintainersController::class, ['maintainers', $this->hub()])->runAction('save');
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}
	};

	$this->mockComponent('entries', Entries::class)
		->shouldReceive('getSectionById')->andReturnUsing(fn (int $id) => $id === 1 ? new Section(['id' => 1, 'name' => 'News']) : null);
});

afterEach(function () {
	$this->hub()->getSettings()->enableMaintainers = false;
});

it('sets the maintainers of an item, ignoring invalid IDs', function () {
	$service = Mockery::mock(MaintainerService::class)->makePartial();
	$service->shouldReceive('setMaintainers')->once()->with(ColumnService::LIST_SECTIONS, 1, [3, 4], [7]);
	$this->hub()->set('maintainers', $service);

	$response = ($this->post)(['list' => ColumnService::LIST_SECTIONS, 'id' => 1, 'users' => ['3', '', '4'], 'groups' => ['7']]);

	expect($response->data['message'] ?? null)->toBe('Maintainers saved.');
});

it('clears the maintainers without any sent', function () {
	$service = Mockery::mock(MaintainerService::class)->makePartial();
	$service->shouldReceive('setMaintainers')->once()->with(ColumnService::LIST_SECTIONS, 1, [], []);
	$this->hub()->set('maintainers', $service);

	($this->post)(['list' => ColumnService::LIST_SECTIONS, 'id' => 1, 'users' => '']);
});

it('rejects maintainers while disabled', function () {
	$this->hub()->getSettings()->enableMaintainers = false;

	($this->post)(['list' => ColumnService::LIST_SECTIONS, 'id' => 1]);
})->throws(ForbiddenHttpException::class);

it('rejects lists without maintainers', function () {
	($this->post)(['list' => ColumnService::LIST_ENTRIES, 'id' => 1]);
})->throws(BadRequestHttpException::class);

it('rejects users who may not manage maintainers', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_STRUCTURE, Page::Sections->viewPermission()]);

	($this->post)(['list' => ColumnService::LIST_SECTIONS, 'id' => 1]);
})->throws(ForbiddenHttpException::class);

it('rejects items that do not exist', function () {
	($this->post)(['list' => ColumnService::LIST_SECTIONS, 'id' => 2]);
})->throws(NotFoundHttpException::class);
