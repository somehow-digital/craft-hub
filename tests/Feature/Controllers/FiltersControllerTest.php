<?php

use craft\elements\User;
use craft\helpers\Json;
use SomehowDigital\Craft\Hub\conditions\rules\HandleConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\HasUrlsConditionRule;
use SomehowDigital\Craft\Hub\conditions\SectionCondition;
use SomehowDigital\Craft\Hub\controllers\FiltersController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\SavedFilter;
use SomehowDigital\Craft\Hub\services\ActivityService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

beforeEach(function () {
	$this->createContentModel();

	// Filters offered to everyone have no user; the others are those of user 1.
	$this->db()->respond('FROM {{%hub_filters}}', fn (string $sql) => str_contains($sql, '`userId` IS NULL') ? [
		['id' => 1, 'kind' => 'sections', 'handle' => 'empty', 'userId' => null, 'name' => null, 'description' => null, 'icon' => null, 'params' => null, 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 1, 'uid' => 'empty'],
		[
			'id' => 2, 'kind' => 'sections', 'handle' => null, 'userId' => null, 'name' => 'Public', 'description' => 'With URLs.', 'icon' => null, 'params' => null,
			'matchCondition' => Json::encode(['class' => SectionCondition::class, 'conditionRules' => [['class' => HasUrlsConditionRule::class, 'value' => true]]]),
			'enabled' => 1, 'sortOrder' => 2, 'uid' => 'public',
		],
	] : [
		['id' => 5, 'kind' => 'sections', 'handle' => null, 'userId' => 1, 'name' => 'Channels', 'description' => null, 'icon' => null, 'params' => Json::encode(['type' => 'channel']), 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 0, 'uid' => 'channels'],
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
			return Craft::createObject(FiltersController::class, ['filters', $this->hub()])->runAction($action);
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}
	};
});

it('lists the filters of each kind, giving the predefined filters without a row one first', function () {
	$variables = $this->runAction(FiltersController::class, 'index')->variables;

	expect(array_keys($variables['kinds']))->toBe(array_map(fn (CheckKind $kind) => $kind->value, CheckKind::cases()))
		->and($variables['kinds']['sections']['kind'])->toBe(CheckKind::Sections)
		->and(array_map(fn (SavedFilter $filter) => $filter->getName(), $variables['kinds']['sections']['filters']))->toBe(['Stale sections', 'Sections without URLs', 'Empty sections', 'Public'])
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_filters}}'))->toHaveCount(32);
});

it('edits a new custom filter of a kind, or an existing filter', function () {
	$new = $this->runAction(FiltersController::class, 'edit', ['kind' => 'entries'])->variables;
	$existing = $this->runAction(FiltersController::class, 'edit', ['filterId' => 2])->variables;

	expect($new['filter']->id)->toBeNull()
		->and($new['filter']->kind)->toBe(CheckKind::Entries)
		->and($existing['filter']->name)->toBe('Public');
});

it('edits no unknown filter or kind, nor the own filters of users', function (array $params) {
	$this->runAction(FiltersController::class, 'edit', $params);
})->with([[['filterId' => 9]], [['filterId' => 5]], [['kind' => 'bogus']]])->throws(NotFoundHttpException::class);

it('only lets users with the permission manage the filters', function (string $action) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_STRUCTURE, 'hub-viewSections', Hub::PERMISSION_MANAGE_DIAGNOSTICS]);
	$this->runAction(FiltersController::class, $action, ['kind' => 'entries']);
})->with(['index', 'edit'])->throws(ForbiddenHttpException::class);

it('saves a new custom filter with the rules of its condition, whatever else was posted', function () {
	$this->mockComponent('hub.activity', ActivityService::class)->shouldReceive('record')->once()->with(ActivityAction::Created, ActivitySubject::Filter, Mockery::any(), 'Pages', ['kind' => 'Sections']);

	$response = ($this->post)('save', [
		'kind' => 'sections',
		'name' => 'Pages',
		'description' => 'The pages.',
		'enabled' => '1',
		'condition' => [
			'class' => 'Bogus\\Condition',
			'conditionRules' => [['class' => HandleConditionRule::class, 'operator' => '=', 'value' => 'pages'], ['class' => 'Bogus\\Rule']],
		],
	]);
	$insert = $this->db()->queriesMatching('INSERT INTO {{%hub_filters}}')[0];

	expect($response->data['filter']['name'])->toBe('Pages')
		->and($insert)->toContain("'Pages'")->toContain("'The pages.'")
		->and($insert)->toContain('SectionCondition')->toContain('HandleConditionRule')
		->and($insert)->not->toContain('Bogus');
});

it('fails to save a custom filter without a rule', function () {
	$response = ($this->post)('save', ['kind' => 'entries', 'name' => 'All', 'condition' => ['conditionRules' => []]]);

	expect($response->getStatusCode())->toBe(400)
		->and($response->data['errors'])->toHaveKey('condition');
});

it('saves only the name and whether a predefined filter is enabled', function () {
	($this->post)('save', ['filterId' => 1, 'name' => 'Nothing in it', 'enabled' => '', 'description' => 'Bogus']);
	$update = $this->db()->queriesMatching('UPDATE {{%hub_filters}}')[0];

	expect($update)->toContain("`name`='Nothing in it'")->toContain('`enabled`=FALSE')->not->toContain('Bogus');
});

it('saves the threshold of a predefined filter, or clears it for its default', function () {
	$this->db()->respond('FROM {{%hub_filters}}', [thresholdRow('sections', 'stale', 6)]);
	$id = crc32('sections.stale');

	($this->post)('save', ['filterId' => $id, 'threshold' => '3']);
	($this->post)('save', ['filterId' => $id, 'threshold' => '']);
	$updates = $this->db()->queriesMatching('UPDATE {{%hub_filters}}');

	expect($updates[0])->toContain('`threshold`=3')
		->and($updates[1])->toContain('`threshold`=NULL');
});

it('saves no threshold of filters without one', function () {
	($this->post)('save', ['filterId' => 1, 'threshold' => '3']);

	expect($this->db()->queriesMatching('UPDATE {{%hub_filters}}')[0])->not->toContain('`threshold`=3');
});

it('deletes no custom filter whose items checks flag', function () {
	$this->db()->respond('FROM {{%hub_checks}}', [[
		'id' => 7, 'kind' => 'sections', 'handle' => null, 'filter' => 'public', 'name' => 'Public', 'description' => 'With URLs.', 'passedDescription' => null,
		'icon' => null, 'severity' => null, 'enabled' => 1, 'sortOrder' => 1, 'uid' => 'public-check',
	]]);

	$response = ($this->post)('delete', ['id' => 2]);

	expect($response->getStatusCode())->toBe(400)
		->and($response->data['message'])->toContain('Public')
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_filters}}'))->toBe([]);
});

it('saves no filter of an unknown kind', function () {
	($this->post)('save', ['kind' => 'bogus', 'name' => 'Bogus']);
})->throws(BadRequestHttpException::class);

it('deletes custom filters, but no predefined ones', function () {
	($this->post)('delete', ['id' => 2]);

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_filters}}')[0])->toContain('`id`=2');

	($this->post)('delete', ['id' => 1]);
})->throws(BadRequestHttpException::class);

it('toggles and reorders the filters', function () {
	$toggled = ($this->post)('toggle', ['id' => 2, 'enabled' => '0'])->data;
	($this->post)('reorder', ['ids' => '["2","1"]']);
	$updates = $this->db()->queriesMatching('UPDATE {{%hub_filters}}');

	expect($toggled['enabled'])->toBeFalse()
		->and($toggled['message'])->toBe('Filter disabled.')
		->and($updates[0])->toContain('`enabled`=FALSE')->toContain('`id`=2')
		->and($updates[1])->toContain('`sortOrder`=1')->toContain('`id`=2');
});

it('only lets users with the permission change the filters', function (string $action) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_STRUCTURE, 'hub-viewSections']);
	($this->post)($action, ['id' => 2, 'ids' => '[]', 'enabled' => '1', 'kind' => 'entries']);
})->with(['save', 'delete', 'toggle', 'reorder'])->throws(ForbiddenHttpException::class);

it('saves the filters a list shows as an own filter of the user, rendering the saved filters again', function () {
	// Users' own filters aren't recorded.
	$this->mockComponent('hub.activity', ActivityService::class)->shouldNotReceive('record');

	$response = ($this->post)('save-own', [
		'kind' => 'sections',
		'path' => 'hub/structure/sections',
		'current' => 'public',
		'name' => 'Empty channels',
		'params' => ['type' => 'channel', 'empty' => '1', 'sort' => 'name', 'hubSite' => 'en'],
	])->data;
	$insert = $this->db()->queriesMatching('INSERT INTO {{%hub_filters}}')[0];

	expect($response['message'])->toBe('Filter saved.')
		->and($insert)->toContain("'Empty channels'")->toContain('{\"empty\":\"1\",\"type\":\"channel\"}')->toContain('`userId`')
		->and($response['html'])->toContain('data-hub-saved')
		->and($response['html'])->toContain('value="public"')
		->and($response['html'])->toContain('Channels')
		->and($response['html'])->toContain('hub/structure/sections');
});

it('replaces the filters of the own filter of the same name', function () {
	($this->post)('save-own', ['kind' => 'sections', 'name' => 'channels', 'params' => ['type' => 'single']]);

	expect($this->db()->queriesMatching('INSERT INTO {{%hub_filters}}'))->toBe([])
		->and($this->db()->queriesMatching('UPDATE {{%hub_filters}}')[0])->toContain('{\"type\":\"single\"}')->toContain('`id`=5');
});

it('fails to save an own filter without filters or a name', function (array $body, string $message) {
	$response = ($this->post)('save-own', ['kind' => 'sections', ...$body]);

	expect($response->getStatusCode())->toBe(400)
		->and($response->data['message'])->toContain($message);
})->with([
	'no filters' => [['name' => 'All', 'params' => ['sort' => 'name']], 'Set at least one filter'],
	'no name' => [['name' => ' ', 'params' => ['type' => 'channel']], 'Name cannot be blank'],
]);

it('renames and deletes the own filters of the user only', function () {
	$this->db()->respond('DELETE FROM {{%hub_filters}}', 1);

	$renamed = ($this->post)('rename-own', ['kind' => 'sections', 'id' => 5, 'name' => 'All channels'])->data;
	$deleted = ($this->post)('delete-own', ['kind' => 'sections', 'id' => 5])->data;

	expect($renamed['message'])->toBe('Filter renamed.')
		->and($this->db()->queriesMatching('UPDATE {{%hub_filters}}')[0])->toContain("'All channels'")
		->and($deleted['message'])->toBe('Filter deleted.')
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_filters}}')[0])->toContain('`id`=5')->toContain('`userId`=1');
});

it('renames no own filter of another kind', function () {
	($this->post)('rename-own', ['kind' => 'entries', 'id' => 5, 'name' => 'Other']);
})->throws(NotFoundHttpException::class);

it('only lets users who may view the list change their own filters', function (string $action) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, 'hub-viewEntries']);
	($this->post)($action, ['kind' => 'sections', 'id' => 5, 'name' => 'Mine', 'params' => ['type' => 'channel']]);
})->with(['save-own', 'rename-own', 'delete-own'])->throws(ForbiddenHttpException::class);

it('offers the saved filters in the list, applying the condition of a custom one', function () {
	$variables = $this->runAction(SectionsController::class, 'index', query: ['filter' => 'public']);

	expect($variables->variables['savedFilters']['current'])->toBe('public')
		->and($variables->variables['filter']->savedFilter)->toBe('public')
		->and(array_keys($variables->variables['sections']))->toBe([1, 2])
		->and(array_column($variables->variables['savedFilters']['own'], 'name'))->toBe(['Channels']);
});

it('ignores unknown custom filters in the list', function () {
	$variables = $this->runAction(SectionsController::class, 'index', query: ['filter' => 'bogus']);

	expect($variables->variables['filter']->savedFilter)->toBeNull()
		->and($variables->variables['savedFilters']['current'])->toBeNull();
});

it('renames no own filter to the name of another one', function () {
	$this->db()->respond('FROM {{%hub_filters}}', fn (string $sql) => str_contains($sql, '`userId` IS NULL') ? [] : [
		['id' => 5, 'kind' => 'sections', 'handle' => null, 'userId' => 1, 'name' => 'Channels', 'description' => null, 'icon' => null, 'params' => Json::encode(['type' => 'channel']), 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 0, 'uid' => 'channels'],
		['id' => 6, 'kind' => 'sections', 'handle' => null, 'userId' => 1, 'name' => 'Singles', 'description' => null, 'icon' => null, 'params' => Json::encode(['type' => 'single']), 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 1, 'uid' => 'singles'],
	]);

	$response = ($this->post)('rename-own', ['kind' => 'sections', 'id' => 5, 'name' => 'singles']);

	expect($response->getStatusCode())->toBe(400)
		->and($response->data['message'])->toBe('You already have a filter named “Singles”.')
		->and($this->db()->queriesMatching('UPDATE {{%hub_filters}}'))->toBe([]);
});

it('renames no own filter to a blank name', function () {
	$response = ($this->post)('rename-own', ['kind' => 'sections', 'id' => 5, 'name' => ' ']);

	expect($response->getStatusCode())->toBe(400)
		->and($response->data['message'])->toContain('Name cannot be blank');
});

it('deletes no own filter that does not exist', function () {
	($this->post)('delete-own', ['kind' => 'sections', 'id' => 99]);
})->throws(NotFoundHttpException::class);
