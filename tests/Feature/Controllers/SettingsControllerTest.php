<?php

use craft\elements\User;
use craft\services\Plugins;
use SomehowDigital\Craft\Hub\controllers\SettingsController;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Settings;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

beforeEach(function () {
	// Posts the body params to an action of the controller as a signed-in user, asking for JSON.
	$this->post = function (string $action, array $body): ?Response {
		$this->request();
		$request = Craft::$app->getRequest();
		$request->enableCsrfValidation = false;
		$request->enableCookieValidation = false;
		$request->setBodyParams($body);
		$request->getHeaders()->set('Accept', 'application/json');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => $this->isAdmin]));

		try {
			return Craft::createObject(SettingsController::class, ['settings', $this->hub()])->runAction($action);
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}
	};
});

it('sends admins on to the filters tab', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

	Craft::createObject(SettingsController::class, ['test', $this->hub()])->runAction('index');

	expect(Craft::$app->getResponse()->getHeaders()->get('Location'))->toContain('hub/manage/settings/filters');
});

it('edits the settings along with the ones overridden by the config file', function () {
	$file = Craft::getAlias('@config/hub.php');
	file_put_contents($file, '<?php return ["activityRetentionDays" => 30];');

	try {
		$variables = $this->runAction(SettingsController::class, 'activity')->variables;
	} finally {
		unlink($file);
	}

	expect($variables['settings'])->toBe($this->hub()->getSettings())
		->and($variables['overrides'])->toBe(['activityRetentionDays'])
		->and($variables['readOnly'])->toBeFalse();
});

it('shows the settings read-only while admin changes aren\'t allowed', function () {
	Craft::$app->getConfig()->getGeneral()->allowAdminChanges = false;

	try {
		expect($this->runAction(SettingsController::class, 'statistics')->variables['readOnly'])->toBeTrue();
	} finally {
		Craft::$app->getConfig()->getGeneral()->allowAdminChanges = true;
	}
});

it('edits settings that failed to save as they were sent', function () {
	$settings = new Settings(['activityRetentionDays' => -1]);

	expect($this->runAction(SettingsController::class, 'activity', ['settings' => $settings])->variables['settings'])->toBe($settings);
});

it('edits the settings of the statistics and of the activity log on their own tabs', function (string $action) {
	$response = $this->runAction(SettingsController::class, $action);

	expect($response->template)->toBe('hub/pages/settings/' . $action)
		->and($response->variables['settings'])->toBe($this->hub()->getSettings())
		->and($response->variables['readOnly'])->toBeFalse();
})->with(['statistics', 'activity']);

it('only lets admins edit the settings of the statistics and of the activity log', function (string $action) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Hub::PERMISSION_MANAGE_LABELS, Hub::PERMISSION_MANAGE_DIAGNOSTICS]);
	$this->runAction(SettingsController::class, $action);
})->with(['statistics', 'activity'])->throws(ForbiddenHttpException::class);

it('sends users who may only manage the labels on to the labels tab', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Hub::PERMISSION_MANAGE_LABELS]);
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'editor']));

	Craft::createObject(SettingsController::class, ['test', $this->hub()])->runAction('index');

	expect(Craft::$app->getResponse()->getHeaders()->get('Location'))->toContain('hub/manage/settings/labels');
});

it('sends users who may only manage the checks on to the diagnostics tab', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Hub::PERMISSION_MANAGE_DIAGNOSTICS]);
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'editor']));

	Craft::createObject(SettingsController::class, ['test', $this->hub()])->runAction('index');

	expect(Craft::$app->getResponse()->getHeaders()->get('Location'))->toContain('hub/manage/settings/diagnostics')
		->and(Hub::getInstance()->getManageNavItem())->not->toBeNull();
});

it('sends users who may only manage the saved filters on to the filters tab', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Hub::PERMISSION_MANAGE_FILTERS]);
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'editor']));

	Craft::createObject(SettingsController::class, ['test', $this->hub()])->runAction('index');

	expect(Craft::$app->getResponse()->getHeaders()->get('Location'))->toContain('hub/manage/settings/filters')
		->and(Hub::getInstance()->getManageNavItem())->not->toBeNull();
});

it('shows the settings page to no users who may not manage anything', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA]);
	$this->runAction(SettingsController::class, 'index');
})->throws(ForbiddenHttpException::class);

it('saves the settings', function () {
	$plugins = $this->mockComponent('plugins', Plugins::class);
	$plugins->shouldReceive('savePluginSettings')->once()->with($this->hub(), ['activityRetentionDays' => '30'])->andReturnTrue();

	$response = ($this->post)('save', ['settings' => ['activityRetentionDays' => '30']]);

	expect($response->data)->toHaveKey('settings')
		->and($response->getStatusCode())->toBe(200);
});

it('goes back to the tab the settings were saved from', function () {
	$plugins = $this->mockComponent('plugins', Plugins::class);
	$plugins->shouldReceive('savePluginSettings')->once()->with($this->hub(), ['enableLabels' => ''])->andReturnTrue();

	$response = ($this->post)('save', ['settings' => ['enableLabels' => ''], 'redirect' => Craft::$app->getSecurity()->hashData('hub/manage/settings/labels')]);

	expect($response->data['redirect'])->toContain('hub/manage/settings/labels');
});

it('fails to save invalid settings', function () {
	$plugins = $this->mockComponent('plugins', Plugins::class);
	$plugins->shouldReceive('savePluginSettings')->once()->andReturnFalse();

	expect(($this->post)('save', ['settings' => ['activityRetentionDays' => '-1']])->getStatusCode())->toBe(400);
});

it('only lets admins save the settings', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Hub::PERMISSION_MANAGE_LABELS]);
	($this->post)('save', ['settings' => []]);
})->throws(ForbiddenHttpException::class);

it('sends admins who may manage nothing else on to the statistics tab', function () {
	$this->actingAs(admin: true, permissions: ['accessCp', 'accessCpWhenSystemIsOff']);
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

	Craft::createObject(SettingsController::class, ['test', $this->hub()])->runAction('index');

	expect(Craft::$app->getResponse()->getHeaders()->get('Location'))->toContain('hub/manage/settings/statistics');
});
