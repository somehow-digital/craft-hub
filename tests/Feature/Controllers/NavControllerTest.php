<?php

declare(strict_types=1);

use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\NavController;
use yii\web\ForbiddenHttpException;

it('redirects the pages of the navigation items to their first page', function (string $action, string $path): void {
	$this->request(['hubSite' => 'en']);
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

	Craft::createObject(NavController::class, ['test', Craft::$app->getModule('hub')])->runAction($action);
	$response = webResponse();

	expect($response->getIsRedirection())->toBeTrue()
		->and($response->getHeaders()->get('Location'))->toContain("{$path}&")
		->and($response->getHeaders()->get('Location'))->toContain('hubSite=en');
})->with([
	['data', 'hub/data/entries'],
	['structure', 'hub/structure/entry-types'],
	['manage', 'hub/manage/work'],
]);

it('lets users open no navigation item without any of its pages', function (string $action): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff']);
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'jane']));

	Craft::createObject(NavController::class, ['test', Craft::$app->getModule('hub')])->runAction($action);
})->with(['data', 'structure', 'manage'])->throws(ForbiddenHttpException::class);
