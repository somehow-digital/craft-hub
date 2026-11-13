<?php

declare(strict_types=1);

use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\ColumnsController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\services\ColumnService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

beforeEach(function (): void {
	$this->createContentModel();

	// Posts the body params to the save action as a signed-in user, asking for JSON, and returns the JSON data of the response.
	$this->save = function (array $body): array {
		$this->request();
		$request = webRequest();
		$request->enableCsrfValidation = false;
		$request->enableCookieValidation = false;
		$request->setBodyParams($body);
		$request->getHeaders()->set('Accept', 'application/json');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

		try {
			$response = Craft::createObject(ColumnsController::class, ['columns', $this->hub()])->runAction('save');
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}

		if (!$response instanceof Response) {
			throw new LogicException('Not a response.');
		}

		$data = $response->data;
		if (!is_array($data)) {
			throw new LogicException('Not an array.');
		}

		return $data;
	};
});

it('keeps the columns of a list', function (): void {
	$data = ($this->save)(['list' => ColumnService::LIST_SECTIONS, 'order' => ['updated', 'type', 7], 'hidden' => ['type']]);

	expect(at($this->preferences, 1, ColumnService::PREFERENCE, ColumnService::LIST_SECTIONS, 'hidden'))->toBe(['type'])
		->and(at($data, 'columns', 0))->toBe(['key' => 'updated', 'visible' => true])
		->and(array_column(arrayAt($data, 'columns'), 'visible', 'key')['type'])->toBeFalse();
});

it('resets the columns of a list', function (): void {
	($this->save)(['list' => ColumnService::LIST_SECTIONS, 'order' => ['updated', 'type'], 'hidden' => ['type']]);
	$data = ($this->save)(['list' => ColumnService::LIST_SECTIONS, 'reset' => '1']);

	expect($this->preferences[1][ColumnService::PREFERENCE])->toBe([])
		->and(at($data, 'columns', 0))->toBe(['key' => 'type', 'visible' => true]);
});

it('treats missing columns as none', function (): void {
	$data = ($this->save)(['list' => ColumnService::LIST_FIELDS, 'order' => 'type', 'hidden' => 'type']);

	expect(array_column(arrayAt($data, 'columns'), 'visible'))->each->toBeTrue();
});

it('rejects unknown lists', function (): void {
	($this->save)(['list' => 'bogus']);
})->throws(BadRequestHttpException::class);

it('requires a list', function (): void {
	($this->save)([]);
})->throws(BadRequestHttpException::class);

it('requires a post request', function (): void {
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1]));

	Craft::createObject(ColumnsController::class, ['columns', $this->hub()])->runAction('save');
})->throws(MethodNotAllowedHttpException::class);

it('requires access to the hub', function (): void {
	$this->actingAs(permissions: []);
	($this->save)(['list' => ColumnService::LIST_SECTIONS]);
})->throws(ForbiddenHttpException::class);

it('renders the lists with the columns of the user', function (): void {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_SECTIONS => ['order' => ['updated'], 'hidden' => ['uri']]]];

	$columns = arrayAt($this->runAction(SectionsController::class, 'index')->variables, 'list', 'columns');

	expect(at($columns, 0, 'key'))->toBe('updated')
		->and(array_column($columns, 'visible', 'key')['uri'])->toBeFalse();
});

it('keeps no columns of lists the user may not view', function (): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Page::Entries->viewPermission()]);

	($this->save)(['list' => ColumnService::LIST_SECTIONS]);
})->throws(ForbiddenHttpException::class);
