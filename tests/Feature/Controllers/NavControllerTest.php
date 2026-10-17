<?php

use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\NavController;

it('redirects the pages of the navigation items to their first page', function (string $action, string $path) {
	$this->request(['hubSite' => 'en']);
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

	Craft::createObject(NavController::class, ['test', Craft::$app->getModule('hub')])->runAction($action);
	$response = Craft::$app->getResponse();

	expect($response->getIsRedirection())->toBeTrue()
		->and($response->getHeaders()->get('Location'))->toContain("$path&")
		->and($response->getHeaders()->get('Location'))->toContain('hubSite=en');
})->with([
	['data', 'hub/data/entries'],
	['structure', 'hub/structure/entry-types'],
]);
