<?php

declare(strict_types=1);

use craft\helpers\Json;
use SomehowDigital\Craft\Hub\conditions\rules\HandleConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\HasUrlsConditionRule;
use SomehowDigital\Craft\Hub\conditions\SectionCondition;
use SomehowDigital\Craft\Hub\controllers\FiltersController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\models\SavedFilter;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/** The condition by which queries match the custom filter that the tests answer with. */
const CUSTOM_FILTER = '`id`=2';

beforeEach(function (): void {
	$this->createContentModel();

	// Filters offered to everyone have no user; the others are those of user 1.
	$this->db()->respond(sqlFrom(Tables::FILTERS), fn(string $sql): array => str_contains($sql, GLOBAL_FILTERS) ? [
		['id' => 1, 'kind' => 'sections', 'handle' => 'empty', 'userId' => null, 'name' => null, 'description' => null, 'icon' => null, 'params' => null, 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 1, 'uid' => 'empty'],
		[
			'id' => 2, 'kind' => 'sections', 'handle' => null, 'userId' => null, 'name' => 'Public', 'description' => 'With URLs.', 'icon' => null, 'params' => null,
			'matchCondition' => Json::encode(['class' => SectionCondition::class, 'conditionRules' => [['class' => HasUrlsConditionRule::class, 'value' => true]]]),
			'enabled' => 1, 'sortOrder' => 2, 'uid' => 'public',
		],
	] : [
		['id' => 5, 'kind' => 'sections', 'handle' => null, 'userId' => 1, 'name' => 'Channels', 'description' => null, 'icon' => null, 'params' => Json::encode(['type' => 'channel']), 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 0, 'uid' => 'channels'],
	]);

});

it('lists the filters of each kind, without writing anything', function (): void {
	$variables = $this->runAction(FiltersController::class, 'index')->variables;

	expect(array_keys(arrayAt($variables, 'kinds')))->toBe(array_map(fn(CheckKind $kind) => $kind->value, CheckKind::cases()))
		->and(at($variables, 'kinds', 'sections', 'kind'))->toBe(CheckKind::Sections)
		->and(array_map(fn(SavedFilter $filter): string => $filter->getName(), objectsAt(SavedFilter::class, $variables, 'kinds', 'sections', 'filters')))->toBe(['Stale sections', 'Sections without URLs', 'Empty sections', 'Public'])
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_filters}}'))->toBeEmpty();
});

it('edits a new custom filter of a kind, or an existing filter', function (): void {
	$new = $this->runAction(FiltersController::class, 'edit', ['kind' => 'entries'])->variables;
	$existing = $this->runAction(FiltersController::class, 'edit', ['filterId' => 2])->variables;

	expect(at($new, 'filter', 'id'))->toBeNull()
		->and(at($new, 'filter', 'kind'))->toBe(CheckKind::Entries)
		->and(at($existing, 'filter', 'name'))->toBe('Public');
});

it('edits no unknown filter or kind, nor the own filters of users', function (array $params): void {
	$this->runAction(FiltersController::class, 'edit', $params);
})->with([[['filterId' => 9]], [['filterId' => 5]], [['kind' => 'bogus']]])->throws(NotFoundHttpException::class);

it('only lets users with the permission manage the filters', function (string $action): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessStructure->value, 'hub-viewSections', Permission::ManageDiagnostics->value]);
	$this->runAction(FiltersController::class, $action, ['kind' => 'entries']);
})->with(['index', 'edit'])->throws(ForbiddenHttpException::class);

it('saves a new custom filter with the rules of its condition, whatever else was posted', function (): void {
	$this->db()->tables = [Tables::ACTIVITY];

	$response = $this->postTo(FiltersController::class, 'save', [
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

	expect(at($response->data, 'filter', 'name'))->toBe('Pages')
		->and(array_map(fn(array $row): array => [$row[0], $row[1], $row[3], $row[4]], activities()))->toBe([[ActivityAction::Created, ActivitySubject::Filter, 'Pages', ['kind' => 'Sections']]])
		->and($insert)->toContain("'Pages'")->toContain("'The pages.'")
		->and($insert)->toContain('SectionCondition')->toContain('HandleConditionRule')
		->and($insert)->not->toContain('Bogus');
});

it('fails to save a custom filter without a rule', function (): void {
	$response = $this->postTo(FiltersController::class, 'save', ['kind' => 'entries', 'name' => 'All', 'condition' => ['conditionRules' => []]]);

	expect($response->getStatusCode())->toBe(400)
		->and(at($response->data, 'errors'))->toHaveKey('condition');
});

it('saves only the name and whether a predefined filter is enabled', function (): void {
	$this->postTo(FiltersController::class, 'save', ['filterId' => 1, 'name' => 'Nothing in it', 'enabled' => '', 'description' => 'Bogus']);
	$update = $this->db()->queriesMatching(sqlUpdate(Tables::FILTERS))[0];

	expect($update)->toContain("`name`='Nothing in it'")->toContain('`enabled`=FALSE')->not->toContain('Bogus');
});

it('saves the threshold of a predefined filter, or clears it for its default', function (): void {
	$this->db()->respond(sqlFrom(Tables::FILTERS), [thresholdRow('sections', 'stale', 6)]);
	$id = crc32('sections.stale');

	$this->postTo(FiltersController::class, 'save', ['filterId' => $id, 'threshold' => '3']);
	$this->postTo(FiltersController::class, 'save', ['filterId' => $id, 'threshold' => '']);
	$updates = $this->db()->queriesMatching(sqlUpdate(Tables::FILTERS));

	expect($updates[0])->toContain('`threshold`=3')
		->and($updates[1])->toContain('`threshold`=NULL');
});

it('saves no threshold of filters without one', function (): void {
	$this->postTo(FiltersController::class, 'save', ['filterId' => 1, 'threshold' => '3']);

	expect($this->db()->queriesMatching(sqlUpdate(Tables::FILTERS))[0])->not->toContain('`threshold`=3');
});

it('deletes no custom filter whose items checks flag', function (): void {
	$this->db()->respond('FROM {{%hub_checks}}', [[
		'id' => 7, 'kind' => 'sections', 'handle' => null, 'filter' => 'public', 'name' => 'Public', 'description' => 'With URLs.', 'passedDescription' => null,
		'icon' => null, 'severity' => null, 'enabled' => 1, 'sortOrder' => 1, 'uid' => 'public-check',
	]]);

	$response = $this->postTo(FiltersController::class, 'delete', ['id' => 2]);

	expect($response->getStatusCode())->toBe(400)
		->and(at($response->data, 'message'))->toContain('Public')
		->and($this->db()->queriesMatching(sqlDeleteFrom(Tables::FILTERS)))->toBe([]);
});

it('saves no filter of an unknown kind', function (): void {
	$this->postTo(FiltersController::class, 'save', ['kind' => 'bogus', 'name' => 'Bogus']);
})->throws(BadRequestHttpException::class);

it('deletes custom filters, but no predefined ones', function (): void {
	$this->postTo(FiltersController::class, 'delete', ['id' => 2]);

	expect($this->db()->queriesMatching(sqlDeleteFrom(Tables::FILTERS))[0])->toContain(CUSTOM_FILTER);

	$this->postTo(FiltersController::class, 'delete', ['id' => 1]);
})->throws(BadRequestHttpException::class);

it('toggles and reorders the filters', function (): void {
	$toggled = $this->postTo(FiltersController::class, 'toggle', ['id' => 2, 'enabled' => '0'])->data;
	$this->postTo(FiltersController::class, 'reorder', ['ids' => '["2","1"]']);
	$updates = $this->db()->queriesMatching(sqlUpdate(Tables::FILTERS));

	expect(at($toggled, 'enabled'))->toBeFalse()
		->and(at($toggled, 'message'))->toBe('Filter disabled.')
		->and($updates[0])->toContain('`enabled`=FALSE')->toContain(CUSTOM_FILTER)
		->and($updates[1])->toContain('`sortOrder`=1')->toContain(CUSTOM_FILTER);
});

it('only lets users with the permission change the filters', function (string $action): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessStructure->value, 'hub-viewSections']);
	$this->postTo(FiltersController::class, $action, ['id' => 2, 'ids' => '[]', 'enabled' => '1', 'kind' => 'entries']);
})->with(['save', 'delete', 'toggle', 'reorder'])->throws(ForbiddenHttpException::class);

it('saves the filters a list shows as an own filter of the user, rendering the saved filters again', function (): void {
	// Users' own filters aren't recorded.
	$this->db()->tables = [Tables::ACTIVITY];

	$response = $this->postTo(FiltersController::class, 'save-own', [
		'kind' => 'sections',
		'path' => 'hub/structure/sections',
		'current' => 'public',
		'name' => 'Empty channels',
		'params' => ['type' => 'channel', 'empty' => '1', 'sort' => 'name', 'hubSite' => 'en'],
	])->data;
	$insert = $this->db()->queriesMatching('INSERT INTO {{%hub_filters}}')[0];

	expect(at($response, 'message'))->toBe('Filter saved.')
		->and(activities())->toBe([])
		->and($insert)->toContain("'Empty channels'")->toContain('{\"empty\":\"1\",\"type\":\"channel\"}')->toContain('`userId`')
		->and(at($response, 'html'))->toContain('data-hub-saved')
		->and(at($response, 'html'))->toContain('value="public"')
		->and(at($response, 'html'))->toContain('Channels')
		->and(at($response, 'html'))->toContain('hub/structure/sections');
});

it('replaces the filters of the own filter of the same name', function (): void {
	$this->postTo(FiltersController::class, 'save-own', ['kind' => 'sections', 'name' => 'channels', 'params' => ['type' => 'single']]);

	expect($this->db()->queriesMatching('INSERT INTO {{%hub_filters}}'))->toBe([])
		->and($this->db()->queriesMatching(sqlUpdate(Tables::FILTERS))[0])->toContain('{\"type\":\"single\"}')->toContain('`id`=5');
});

it('fails to save an own filter without filters or a name', function (array $body, string $message): void {
	$response = $this->postTo(FiltersController::class, 'save-own', ['kind' => 'sections', ...$body]);

	expect($response->getStatusCode())->toBe(400)
		->and(at($response->data, 'message'))->toContain($message);
})->with([
	'no filters' => [['name' => 'All', 'params' => ['sort' => 'name']], 'Set at least one filter'],
	'no name' => [['name' => ' ', 'params' => ['type' => 'channel']], 'Name cannot be blank'],
]);

it('renames and deletes the own filters of the user only', function (): void {
	$this->db()->respond(sqlDeleteFrom(Tables::FILTERS), 1);

	$renamed = $this->postTo(FiltersController::class, 'rename-own', ['kind' => 'sections', 'id' => 5, 'name' => 'All channels'])->data;
	$deleted = $this->postTo(FiltersController::class, 'delete-own', ['kind' => 'sections', 'id' => 5])->data;

	expect(at($renamed, 'message'))->toBe('Filter renamed.')
		->and($this->db()->queriesMatching(sqlUpdate(Tables::FILTERS))[0])->toContain("'All channels'")
		->and(at($deleted, 'message'))->toBe('Filter deleted.')
		->and($this->db()->queriesMatching(sqlDeleteFrom(Tables::FILTERS))[0])->toContain('`id`=5')->toContain('`userId`=1');
});

it('renames no own filter of another kind', function (): void {
	$this->postTo(FiltersController::class, 'rename-own', ['kind' => 'entries', 'id' => 5, 'name' => 'Other']);
})->throws(NotFoundHttpException::class);

it('only lets users who may view the list change their own filters', function (string $action): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, 'hub-viewEntries']);
	$this->postTo(FiltersController::class, $action, ['kind' => 'sections', 'id' => 5, 'name' => 'Mine', 'params' => ['type' => 'channel']]);
})->with(['save-own', 'rename-own', 'delete-own'])->throws(ForbiddenHttpException::class);

it('offers the saved filters in the list, applying the condition of a custom one', function (): void {
	$variables = $this->runAction(SectionsController::class, 'index', query: ['filter' => 'public']);

	expect(at($variables->variables, 'list', 'savedFilters', 'current'))->toBe('public')
		->and(at($variables->variables, 'list', 'filter', 'savedFilter'))->toBe('public')
		->and(array_keys(arrayAt($variables->variables, 'list', 'sections')))->toBe([1, 2])
		->and(array_column(arrayAt($variables->variables, 'list', 'savedFilters', 'own'), 'name'))->toBe(['Channels']);
});

it('ignores unknown custom filters in the list', function (): void {
	$variables = $this->runAction(SectionsController::class, 'index', query: ['filter' => 'bogus']);

	expect(at($variables->variables, 'list', 'filter', 'savedFilter'))->toBeNull()
		->and(at($variables->variables, 'list', 'savedFilters', 'current'))->toBeNull();
});

it('renames no own filter to the name of another one', function (): void {
	$this->db()->respond(sqlFrom(Tables::FILTERS), fn(string $sql): array => str_contains($sql, GLOBAL_FILTERS) ? [] : [
		['id' => 5, 'kind' => 'sections', 'handle' => null, 'userId' => 1, 'name' => 'Channels', 'description' => null, 'icon' => null, 'params' => Json::encode(['type' => 'channel']), 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 0, 'uid' => 'channels'],
		['id' => 6, 'kind' => 'sections', 'handle' => null, 'userId' => 1, 'name' => 'Singles', 'description' => null, 'icon' => null, 'params' => Json::encode(['type' => 'single']), 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 1, 'uid' => 'singles'],
	]);

	$response = $this->postTo(FiltersController::class, 'rename-own', ['kind' => 'sections', 'id' => 5, 'name' => 'singles']);

	expect($response->getStatusCode())->toBe(400)
		->and(at($response->data, 'message'))->toBe('You already have a filter named “Singles”.')
		->and($this->db()->queriesMatching(sqlUpdate(Tables::FILTERS)))->toBe([]);
});

it('renames no own filter to a blank name', function (): void {
	$response = $this->postTo(FiltersController::class, 'rename-own', ['kind' => 'sections', 'id' => 5, 'name' => ' ']);

	expect($response->getStatusCode())->toBe(400)
		->and(at($response->data, 'message'))->toContain('Name cannot be blank');
});

it('deletes no own filter that does not exist', function (): void {
	$this->postTo(FiltersController::class, 'delete-own', ['kind' => 'sections', 'id' => 99]);
})->throws(NotFoundHttpException::class);
