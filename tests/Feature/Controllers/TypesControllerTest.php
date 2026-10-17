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

it('summarizes the entry types on their own', function () {
	$response = $this->runAction(TypesController::class, 'summary');

	expect($response->template)->toBe('hub/_components/summary')
		->and($response->variables['cards'])->toHaveCount(4)
		->and($response->variables['cards'][0]['segments'][2]['value'])->toBe(1);
});

it('shows an entry type with entries in sections', function () {
	$response = $this->runAction(TypesController::class, 'view', ['id' => 10]);
	$variables = $response->variables;
	[$entries, $relations, $fields] = $variables['summary'];

	expect($response->template)->toBe('hub/pages/types/view')
		->and($variables['selectedTab'])->toBe('overview')
		->and($variables['info']->entryType->handle)->toBe('article')
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

it('shows the review lists of an entry type on its diagnostics tab', function () {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 10, 'tab' => 'diagnostics'], ['list' => 'stale', 'months' => '6'])->variables;

	expect($variables['reviewList'])->toBe('stale')
		->and($variables['reviewMonths'])->toBe(6)
		->and($variables['reviewQuery']->typeId)->toBe(10)
		->and($variables)->not->toHaveKey('summary');
});

it('shows nested entry types without an entries list', function () {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 12])->variables;

	expect($variables)->not->toHaveKey('entries')
		->and($variables['summary'][1]['segments'][1]['value'])->toBe(1);
});

it('lists the nested entries of an entry type on a tab of its own', function () {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 12, 'tab' => 'entries'])->variables;

	expect($variables['listFixed'])->toBe('type')
		->and($variables['sections'])->toBe([])
		->and(array_keys($variables['nestedFields']))->toBe([2])
		// Nested entries have no section.
		->and(array_column($variables['columns'], 'key'))->not->toContain('section')
		->and(array_column($variables['columns'], 'key'))->toContain('field', 'owner');
});

it('lists only the stale entries of nested entry types on their diagnostics tab', function () {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 12, 'tab' => 'diagnostics'], ['list' => 'unreferenced'])->variables;

	expect($variables['reviewList'])->toBe('stale')
		->and($variables['reviewUnreferenced'])->toBeFalse();
});

it('fails for the entries tab of unused entry types', function () {
	$this->runAction(TypesController::class, 'view', ['id' => 13, 'tab' => 'entries']);
})->throws(NotFoundHttpException::class);

it('counts fields that are not among all fields as plain fields', function () {
	$this->entryTypes[13]->getFieldLayout()->setTabs([]);
	$stray = $this->field(craft\fields\PlainText::class, 50, 'stray');
	unset($this->fields[50]);
	$this->entryType(13, 'orphan', [$stray]);

	expect(array_column($this->runAction(TypesController::class, 'view', ['id' => 13])->variables['summary'][2]['segments'], 'value', 'label'))->toBe(['Nested entries' => 0, 'References' => 0, 'Plain values' => 1]);
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
