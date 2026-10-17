<?php

use SomehowDigital\Craft\Hub\controllers\FieldsController;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->createContentModel();
});

it('lists the fields', function () {
	$response = $this->runAction(FieldsController::class, 'index', query: ['type' => 'assets,bogus', 'owner' => 'entry type', 'sort' => 'layouts', 'dir' => 'asc']);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/fields/index')
		->and(array_keys($variables['fields']))->toBe([5, 9])
		->and($variables['total'])->toBe(9)
		->and($variables['types'])->toBe(['assets' => 'Assets', 'entries' => 'Entries', 'link' => 'Link', 'matrix' => 'Matrix', 'plain text' => 'Plain Text'])
		->and(array_keys($variables['owners']))->toBe(['entry type', 'volume', 'users'])
		->and($variables['filter']->types)->toBe(['assets'])
		->and($variables['data'])->toBe([5 => 3, 9 => 0])
		->and($variables['counts']['types'])->toBe(['assets' => 3, 'entries' => 1, 'link' => 1, 'matrix' => 2, 'plain text' => 2])
		->and($variables['counts']['kinds'])->toBe(['nested' => 1, 'relational' => 5, 'plain' => 3])
		->and($variables['dir'])->toBe('asc')
		->and($variables['summary'])->toHaveCount(4)
		->and($variables['summary'][1]['note'])->toBe('7 nested entries · 8 relations')
		->and($variables['summary'][3]['total'])->toBe(10);
});

it('filters the fields by their usage and layouts', function () {
	$variables = $this->runAction(FieldsController::class, 'index', query: ['unused' => '1', 'empty' => '1', 'layoutsMin' => '0', 'layoutsMax' => '9'])->variables;

	expect($variables['filter']->unused)->toBeTrue()
		->and($variables['filter']->empty)->toBeTrue()
		->and($variables['filter']->layoutsMin)->toBe(0)
		->and($variables['fields'])->toBe([]);
});

it('shows nested entry fields', function () {
	$response = $this->runAction(FieldsController::class, 'view', ['id' => 2]);
	$summary = $response->variables['summary'];

	expect($response->template)->toBe('hub/pages/fields/view')
		->and($summary)->toHaveCount(3)
		->and($summary[0]['note'])->toBe('One instance')
		->and($summary[1]['total'])->toBe(7)
		->and(array_column($summary[2]['segments'], 'value'))->toBe([1, 0]);
});

it('shows relational fields', function () {
	$summary = $this->runAction(FieldsController::class, 'view', ['id' => 3])->variables['summary'];

	expect($summary)->toHaveCount(2)
		->and($summary[1]['total'])->toBe(4)
		->and($summary[1]['note'])->toBe('2 source elements · 3 referenced elements');
});

it('shows plain fields', function () {
	$summary = $this->runAction(FieldsController::class, 'view', ['id' => 1])->variables['summary'];

	expect($summary)->toHaveCount(1)
		->and($summary[0]['total'])->toBe(5)
		->and($summary[0]['note'])->toBe('6 instances');
});

it('fails for fields that do not exist', function () {
	$this->runAction(FieldsController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);
