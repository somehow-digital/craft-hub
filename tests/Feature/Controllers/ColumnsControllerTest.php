<?php

use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\ColumnsController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\services\ColumnService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

beforeEach(function () {
	$this->createContentModel();

	// Posts the body params to the save action as a signed-in user, asking for JSON, and returns the JSON data of the response.
	$this->save = function (array $body): array {
		$this->request();
		$request = Craft::$app->getRequest();
		$request->enableCsrfValidation = false;
		$request->enableCookieValidation = false;
		$request->setBodyParams($body);
		$request->getHeaders()->set('Accept', 'application/json');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

		try {
			$response = Craft::createObject(ColumnsController::class, ['columns', $this->hub()])->runAction('save');
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}

		expect($response)->toBeInstanceOf(Response::class);

		return $response->data;
	};
});

it('keeps the columns of a list', function () {
	$data = ($this->save)(['list' => ColumnService::LIST_SECTIONS, 'order' => ['updated', 'type', 7], 'hidden' => ['type']]);

	expect($this->preferences[1][ColumnService::PREFERENCE][ColumnService::LIST_SECTIONS]['hidden'])->toBe(['type'])
		->and($data['columns'][0])->toBe(['key' => 'updated', 'visible' => true])
		->and(array_column($data['columns'], 'visible', 'key')['type'])->toBeFalse();
});

it('resets the columns of a list', function () {
	($this->save)(['list' => ColumnService::LIST_SECTIONS, 'order' => ['updated', 'type'], 'hidden' => ['type']]);
	$data = ($this->save)(['list' => ColumnService::LIST_SECTIONS, 'reset' => '1']);

	expect($this->preferences[1][ColumnService::PREFERENCE])->toBe([])
		->and($data['columns'][0])->toBe(['key' => 'type', 'visible' => true]);
});

it('treats missing columns as none', function () {
	$data = ($this->save)(['list' => ColumnService::LIST_FIELDS, 'order' => 'type', 'hidden' => 'type']);

	expect(array_column($data['columns'], 'visible'))->each->toBeTrue();
});

it('rejects unknown lists', function () {
	($this->save)(['list' => 'bogus']);
})->throws(BadRequestHttpException::class);

it('requires a list', function () {
	($this->save)([]);
})->throws(BadRequestHttpException::class);

it('requires a post request', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1]));

	Craft::createObject(ColumnsController::class, ['columns', $this->hub()])->runAction('save');
})->throws(MethodNotAllowedHttpException::class);

it('requires access to the hub', function () {
	$this->actingAs(permissions: []);
	($this->save)(['list' => ColumnService::LIST_SECTIONS]);
})->throws(ForbiddenHttpException::class);

it('renders the lists with the columns of the user', function () {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_SECTIONS => ['order' => ['updated'], 'hidden' => ['uri']]]];

	$columns = $this->runAction(SectionsController::class, 'index')->variables['columns'];

	expect($columns[0]['key'])->toBe('updated')
		->and(array_column($columns, 'visible', 'key')['uri'])->toBeFalse();
});
