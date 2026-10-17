<?php

use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\ChecksController;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

beforeEach(function () {
	$this->createContentModel();

	$this->db()->respond('FROM {{%hub_checks}}', [
		['id' => 1, 'kind' => 'sections', 'handle' => 'empty', 'filter' => null, 'severity' => null, 'enabled' => 1, 'sortOrder' => 1, 'uid' => 'empty'],
		[
			'id' => 2, 'kind' => 'sections', 'handle' => null, 'filter' => 'withoutUrls', 'name' => 'Public', 'description' => 'No URLs.', 'passedDescription' => null,
			'icon' => null, 'severity' => 'warning', 'enabled' => 1, 'sortOrder' => 2, 'uid' => 'public',
		],
	]);

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
			return Craft::createObject(ChecksController::class, ['checks', $this->hub()])->runAction($action);
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}
	};
});

it('lists the checks of each kind, without writing anything', function () {
	$variables = $this->runAction(ChecksController::class, 'index')->variables;

	expect(array_keys($variables['kinds']))->toBe(array_map(fn (CheckKind $kind) => $kind->value, CheckKind::cases()))
		->and($variables['kinds']['sections']['kind'])->toBe(CheckKind::Sections)
		->and($variables['enabled'])->toBeTrue()
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_checks}}'))->toBeEmpty();
});

it('edits a new custom check of a kind, or an existing check', function () {
	$new = $this->runAction(ChecksController::class, 'edit', ['kind' => 'entries'])->variables;
	$existing = $this->runAction(ChecksController::class, 'edit', ['checkId' => 2])->variables;

	expect($new['check']->id)->toBeNull()
		->and($new['check']->kind)->toBe(CheckKind::Entries)
		->and($new['severities'])->toBe(Severity::sorted())
		->and(array_map(fn ($filter) => $filter->getKey(), $new['filters']))->toContain('entries.drafts')
		->and($existing['check']->name)->toBe('Public')
		->and($existing['check']->getFilter()?->getKey())->toBe('sections.withoutUrls');
});

it('edits no unknown check or kind', function (array $params) {
	$this->runAction(ChecksController::class, 'edit', $params);
})->with([[['checkId' => 9]], [['kind' => 'bogus']]])->throws(NotFoundHttpException::class);

it('switches diagnostics on and off along with the checks, for admins only', function () {
	$admin = $this->runAction(ChecksController::class, 'index')->variables;

	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Hub::PERMISSION_MANAGE_DIAGNOSTICS]);
	$other = $this->runAction(ChecksController::class, 'index')->variables;

	expect($admin['settings'])->toBe($this->hub()->getSettings())
		->and($admin['readOnly'])->toBeFalse()
		->and($other)->not->toHaveKey('settings');
});

it('only lets users with the permission manage the checks', function (string $action) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Hub::PERMISSION_MANAGE_LABELS]);
	$this->runAction(ChecksController::class, $action, ['kind' => 'entries']);
})->with(['index', 'edit'])->throws(ForbiddenHttpException::class);

it('saves a new custom check of a filter of its kind', function () {
	$response = ($this->post)('save', [
		'kind' => 'sections',
		'filter' => 'withoutUrls',
		'name' => 'Pages',
		'description' => 'Pages without URLs.',
		'severity' => 'error',
		'enabled' => '1',
	]);
	$insert = $this->db()->queriesMatching('INSERT INTO {{%hub_checks}}')[0];

	expect($response->data['check']['name'])->toBe('Pages')
		->and($insert)->toContain("'Pages'")->toContain("'error'")->toContain("'withoutUrls'");
});

it('fails to save a custom check without a filter of its kind', function () {
	$response = ($this->post)('save', ['kind' => 'entries', 'filter' => 'withoutUrls', 'name' => 'Empty', 'description' => 'Nothing.']);

	expect($response->getStatusCode())->toBe(400)
		->and($response->data['errors'])->toHaveKey('filter');
});

it('keeps the filter of a default check', function () {
	($this->post)('save', ['checkId' => 1, 'filter' => 'withoutUrls', 'enabled' => '1']);

	expect($this->db()->queriesMatching('UPDATE {{%hub_checks}}')[0])->not->toContain('withoutUrls');
});

it('saves only whether a default check is enabled and its severity', function () {
	($this->post)('save', ['checkId' => 1, 'name' => 'Renamed', 'enabled' => '', 'severity' => '']);
	$update = $this->db()->queriesMatching('UPDATE {{%hub_checks}}')[0];

	expect($update)->toContain('`enabled`=FALSE')->toContain('`severity`=NULL')->not->toContain('Renamed');
});

it('saves no check of an unknown kind', function () {
	($this->post)('save', ['kind' => 'bogus', 'name' => 'Bogus']);
})->throws(BadRequestHttpException::class);

it('deletes custom checks, but no default ones', function () {
	($this->post)('delete', ['id' => 2]);

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_checks}}')[0])->toContain('`id`=2');

	($this->post)('delete', ['id' => 1]);
})->throws(BadRequestHttpException::class);

it('toggles and reorders the checks', function () {
	$toggled = ($this->post)('toggle', ['id' => 2, 'enabled' => '0'])->data;
	($this->post)('reorder', ['ids' => '["2","1"]']);
	$updates = $this->db()->queriesMatching('UPDATE {{%hub_checks}}');

	expect($toggled['enabled'])->toBeFalse()
		->and($toggled['message'])->toBe('Check disabled.')
		->and($updates[0])->toContain('`enabled`=FALSE')->toContain('`id`=2')
		->and($updates[1])->toContain('`sortOrder`=1')->toContain('`id`=2');
});

it('only lets users with the permission change the checks', function (string $action) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA]);
	($this->post)($action, ['id' => 2, 'ids' => '[]', 'enabled' => '1', 'kind' => 'entries']);
})->with(['save', 'delete', 'toggle', 'reorder'])->throws(ForbiddenHttpException::class);

it('passes a check that failed to save back to its form', function () {
	$check = new CheckDefinition(['kind' => CheckKind::Users]);

	expect($this->runAction(ChecksController::class, 'edit', ['checkId' => 2, 'check' => $check])->variables['check'])->toBe($check);
});
