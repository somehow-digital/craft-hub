<?php

use SomehowDigital\Craft\Hub\controllers\TypesController;
use SomehowDigital\Craft\Hub\services\ColumnService;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->createContentModel();
});

it('lists the entry types', function () {
	$response = $this->runAction(TypesController::class, 'index', query: ['kind' => 'section,bogus', 'entriesMin' => '1', 'sort' => 'entries']);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/types/index')
		->and(array_keys($variables['entryTypes']))->toBe([10, 11])
		->and($variables['total'])->toBe(5)
		->and($variables['pageInfo']->total)->toBe(2)
		->and($variables['pageInfo']->totalPages)->toBe(1)
		->and($variables['filter']->kinds)->toBe(['section'])
		->and($variables['activities'])->toBe([10 => 'recent', 11 => 'recent'])
		->and($variables['sort'])->toBe('entries')
		->and($variables)->not->toHaveKey('summary');
});

it('summarizes the entry types on their statistics tab', function () {
	$response = $this->runAction(TypesController::class, 'statistics');

	expect($response->template)->toBe('hub/pages/statistics')
		->and($response->variables['cards'])->toHaveCount(4)
		->and(loadCards($response->variables['cards'])[0]['segments'][2]['value'])->toBe(1);
});

it('shows an entry type with entries in sections', function () {
	$response = $this->runAction(TypesController::class, 'view', ['id' => 10]);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/types/view')
		->and($variables['selectedTab'])->toBe('overview')
		->and($variables['info']->entryType->handle)->toBe('article')
		->and($variables)->not->toHaveKeys(['summary', 'entries', 'reviewList']);
});

it('summarizes an entry type on its statistics tab', function () {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 10, 'tab' => 'statistics'])->variables;
	[$entries, $relations, $fields] = loadCards($variables['summary']);

	expect($variables['selectedTab'])->toBe('statistics')
		->and($entries['total'])->toBe(5)
		->and($relations['total'])->toBe(4)
		->and(array_column($fields['segments'], 'value', 'label'))->toBe(['Nested entries' => 1, 'References' => 3, 'Plain values' => 1])
		->and($variables)->not->toHaveKeys(['entries', 'reviewList']);
});

it('lists the entries of an entry type on a tab of its own', function () {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 10, 'tab' => 'entries'])->variables;

	expect($variables['selectedTab'])->toBe('entries')
		->and($variables['listFixed'])->toBe('type')
		->and($variables['listPath'])->toBe('hub/structure/entry-types/10/entries')
		->and($variables)->toHaveKey('entries')
		->and($variables)->not->toHaveKey('summary');
});

it('shows the checks of an entry type on its diagnostics tab', function () {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 10, 'tab' => 'diagnostics'])->variables;

	expect(array_map(fn ($check) => $check->key, $variables['checks']))->toContain('entryTypes.unused', 'entries.drafts')
		->and($variables)->not->toHaveKeys(['summary', 'reviewList']);
});

it('shows nested entry types without an entries list', function () {
	expect($this->runAction(TypesController::class, 'view', ['id' => 12])->variables)->not->toHaveKey('entries')
		->and(loadCards($this->runAction(TypesController::class, 'view', ['id' => 12, 'tab' => 'statistics'])->variables['summary'])[1]['segments'][1]['value'])->toBe(1);
});

it('lists the nested entries of an entry type on a tab of its own', function () {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 12, 'tab' => 'entries'])->variables;

	expect($variables['listFixed'])->toBe('type')
		->and($variables['sections'])->toBe([])
		->and(array_keys($variables['nestedFields']))->toBe([2])
		->and(array_column($variables['columns'], 'key'))->toContain('origin', 'owner');
});

it('counts the flagged nested entries of nested entry types on their diagnostics tab', function () {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 12, 'tab' => 'diagnostics'])->variables;

	expect(array_map(fn ($check) => $check->key, $variables['checks']))->toContain('entries.stale');
});

it('fails for the entries tab of unused entry types', function () {
	$this->runAction(TypesController::class, 'view', ['id' => 13, 'tab' => 'entries']);
})->throws(NotFoundHttpException::class);

it('counts fields that are not among all fields as plain fields', function () {
	$this->entryTypes[13]->getFieldLayout()->setTabs([]);
	$stray = $this->field(craft\fields\PlainText::class, 50, 'stray');
	unset($this->fields[50]);
	$this->entryType(13, 'orphan', [$stray]);

	expect(array_column(loadCards($this->runAction(TypesController::class, 'view', ['id' => 13, 'tab' => 'statistics'])->variables['summary'])[2]['segments'], 'value', 'label'))->toBe(['Nested entries' => 0, 'References' => 0, 'Plain values' => 1]);
});

it('fails for entry types that do not exist', function () {
	$this->runAction(TypesController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);

it('leaves out the data of hidden columns', function () {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_ENTRY_TYPES => ['order' => [], 'hidden' => ['updated']]]];

	$variables = $this->runAction(TypesController::class, 'index')->variables;

	expect($variables['activities'])->toBe([])
		->and($variables['entryTypes'])->not->toBeEmpty();
});
