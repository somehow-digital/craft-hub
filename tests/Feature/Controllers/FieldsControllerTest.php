<?php

use craft\fields\Matrix;
use craft\fields\PlainText;
use SomehowDigital\Craft\Hub\controllers\FieldsController;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\services\ColumnService;
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
		->and($variables['pageInfo']->total)->toBe(2)
		->and($variables['pageInfo']->totalPages)->toBe(1)
		->and(array_column($variables['types'], 'label'))->toBe(['Assets', 'Entries', 'Link', 'Matrix', 'Plain Text'])
		->and(array_keys($variables['types']))->toBe(['assets', 'entries', 'link', 'matrix', 'plain text'])
		->and($variables['types']['assets']['icon'])->toBeString()->not->toBeEmpty()
		->and(array_keys($variables['owners']))->toBe(['entry type', 'volume', 'users'])
		->and($variables['filter']->types)->toBe(['assets'])
		->and($variables['data'])->toBe([5 => 3, 9 => 0])
		->and($variables['dir'])->toBe('asc')
		->and($variables)->not->toHaveKey('summary');
});

it('summarizes the fields on their statistics tab', function () {
	$cards = loadCards($this->runAction(FieldsController::class, 'statistics')->variables['cards']);

	expect($cards)->toHaveCount(4)
		->and($cards[1]['note'])->toBe('7 nested entries · 8 references')
		->and($cards[3]['total'])->toBe(10);
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
	$summary = loadCards($this->runAction(FieldsController::class, 'view', ['id' => 2, 'tab' => 'statistics'])->variables['summary']);

	expect($response->template)->toBe('hub/pages/fields/view')
		->and($response->variables)->not->toHaveKey('summary')
		->and($summary)->toHaveCount(3)
		->and($summary[0]['note'])->toBe('One instance')
		->and($summary[1]['total'])->toBe(7)
		->and(array_column($summary[2]['segments'], 'value'))->toBe([1, 0]);
});

it('lists the entries of nested entry fields on a tab of their own', function () {
	$variables = $this->runAction(FieldsController::class, 'view', ['id' => 2, 'tab' => 'entries'], query: ['section' => 'news'])->variables;

	expect($variables['selectedTab'])->toBe('entries')
		->and($variables['listFixed'])->toBe('field')
		// Entries of a section don't widen the list of a field.
		->and($variables['filter']->sections)->toBe([])
		->and($variables['listPath'])->toBe('hub/structure/fields/2/entries')
		->and($variables['filter']->fields)->toBe(['blocks'])
		->and($variables['sections'])->toBe([])
		->and(array_map(fn ($info) => $info->entryType->handle, array_values($variables['entryTypes'])))->toBe(['block'])
		->and(array_column($variables['columns'], 'key'))->not->toContain('origin')
		->and($variables)->not->toHaveKey('summary');
});

it('fails for the entries tab of fields without nested entries', function () {
	$this->runAction(FieldsController::class, 'view', ['id' => 1, 'tab' => 'entries']);
})->throws(NotFoundHttpException::class);

it('shows relational fields', function () {
	$summary = loadCards($this->runAction(FieldsController::class, 'view', ['id' => 3, 'tab' => 'statistics'])->variables['summary']);

	expect($summary)->toHaveCount(2)
		->and($summary[1]['total'])->toBe(4)
		->and($summary[1]['note'])->toBe('2 source elements · 3 referenced elements');
});

it('shows plain fields', function () {
	$summary = loadCards($this->runAction(FieldsController::class, 'view', ['id' => 1, 'tab' => 'statistics'])->variables['summary']);

	expect($summary)->toHaveCount(1)
		->and($summary[0]['total'])->toBe(5)
		->and($summary[0]['note'])->toBe('6 instances');
});

it('fails for fields that do not exist', function () {
	$this->runAction(FieldsController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);

it('leaves out the data of hidden columns', function () {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_FIELDS => ['order' => [], 'hidden' => ['data']]]];

	$variables = $this->runAction(FieldsController::class, 'index')->variables;

	expect($variables['data'])->toBe([])
		->and($variables['fields'])->not->toBeEmpty();
});

it('counts the fields in a snapshot by whether they were placed and held data then', function () {
	$metrics = ['fields' => [
		1 => ['type' => PlainText::class, 'kind' => 'plain', 'entryTypes' => [], 'owners' => ['Entry Type' => 1]],
		2 => ['type' => Matrix::class, 'kind' => 'nested', 'entryTypes' => [10], 'owners' => ['Entry Type' => 2]],
		3 => ['type' => Matrix::class, 'kind' => 'nested', 'entryTypes' => [10], 'owners' => ['Entry Type' => 1]],
		4 => ['type' => PlainText::class, 'kind' => 'plain', 'entryTypes' => [], 'owners' => []],
	]];
	$entries = [['sectionId' => null, 'typeId' => '10', 'fieldId' => '2', 'sites' => '1', 'enabledSites' => '1', 'total' => '2', 'enabled' => '2', 'pending' => '0', 'expired' => '0', 'drafts' => '0', 'lastUpdated' => null]];
	$snapshot = new Snapshot(1, new DateTime('2026-10-20'), fn (string $part) => ['metrics' => $metrics, 'entries' => $entries][$part] ?? []);

	$cards = array_column($this->runAction(FieldsController::class, 'statistics')->variables['cards'], 'history', 'key');
	$segments = fn (array $card) => array_column($card['segments'], 'value', 'label');

	expect($cards['fields']($snapshot)['total'])->toBe(4)
		->and($segments($cards['fields']($snapshot)))->toBe(['In use' => 2, 'Empty' => 1, 'Unused' => 1])
		->and($segments($cards['data']($snapshot)))->toBe(['Nested entries' => 2, 'References' => 0, 'Plain values' => 2])
		->and($segments($cards['field-types']($snapshot)))->toBe(['Plain Text' => 2, 'Matrix' => 2])
		->and($cards['relations']($snapshot))->toBe(['total' => 4, 'segments' => [['label' => 'Entry Type', 'value' => 4]]]);
});
