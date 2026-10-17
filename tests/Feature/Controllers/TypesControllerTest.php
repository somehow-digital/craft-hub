<?php

use SomehowDigital\Craft\Hub\controllers\TypesController;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->createContentModel();
	$this->countableQueries();
});

it('lists the entry types', function () {
	$response = $this->runAction(TypesController::class, 'index', query: ['kind' => 'section,bogus', 'entriesMin' => '1', 'sort' => 'entries']);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/types/index')
		->and(array_keys($variables['entryTypes']))->toBe([10, 11])
		->and($variables['total'])->toBe(5)
		->and($variables['filter']->kinds)->toBe(['section'])
		->and($variables['activities'])->toBe([10 => 'recent', 11 => 'recent'])
		->and($variables['counts']['kinds']['unused'])->toBe(1)
		->and($variables['counts']['activities'])->toBe(['recent' => 3, 'stale' => 0, 'none' => 2])
		->and($variables['sort'])->toBe('entries')
		->and($variables['summary'])->toHaveCount(4)
		->and($variables['summary'][0]['segments'][2]['value'])->toBe(1);
});

it('shows an entry type with entries in sections', function () {
	$response = $this->runAction(TypesController::class, 'view', ['id' => 10], ['list' => 'stale', 'months' => '6']);
	$variables = $response->variables;
	[$entries, $relations, $fields] = $variables['summary'];

	expect($response->template)->toBe('hub/pages/types/view')
		->and($variables['info']->entryType->handle)->toBe('article')
		->and($variables['listFixed'])->toBe('type')
		->and($variables['listPath'])->toBe('hub/entries/types/10')
		->and($entries['total'])->toBe(5)
		->and($relations['total'])->toBe(4)
		->and(array_column($fields['segments'], 'value', 'label'))->toBe(['Nested entries' => 1, 'Relations' => 3, 'Plain values' => 1])
		->and($variables['reviewList'])->toBe('stale')
		->and($variables['reviewMonths'])->toBe(6)
		->and($variables['reviewQuery']->typeId)->toBe(10);
});

it('shows nested entry types without an entries list', function () {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 12])->variables;

	expect($variables)->not->toHaveKey('entries')
		->and($variables['summary'][1]['segments'][1]['value'])->toBe(1);
});

it('counts fields that are not among all fields as plain fields', function () {
	$this->entryTypes[13]->getFieldLayout()->setTabs([]);
	$stray = $this->field(craft\fields\PlainText::class, 50, 'stray');
	unset($this->fields[50]);
	$this->entryType(13, 'orphan', [$stray]);

	expect(array_column($this->runAction(TypesController::class, 'view', ['id' => 13])->variables['summary'][2]['segments'], 'value', 'label'))->toBe(['Nested entries' => 0, 'Relations' => 0, 'Plain values' => 1]);
});

it('fails for entry types that do not exist', function () {
	$this->runAction(TypesController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);
