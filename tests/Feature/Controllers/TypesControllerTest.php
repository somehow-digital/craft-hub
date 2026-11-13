<?php

declare(strict_types=1);

use craft\fields\PlainText;
use craft\web\twig\variables\Paginate;
use SomehowDigital\Craft\Hub\controllers\TypesController;
use SomehowDigital\Craft\Hub\services\ColumnService;
use yii\web\NotFoundHttpException;

beforeEach(function (): void {
	$this->createContentModel();
});

it('lists the entry types', function (): void {
	$response = $this->runAction(TypesController::class, 'index', query: ['kind' => 'section,bogus', 'entriesMin' => '1', 'sort' => 'entries']);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/types/index')
		->and(array_keys(arrayAt($variables, 'list', 'entryTypes')))->toBe([10, 11])
		->and(at($variables, 'list', 'total'))->toBe(5)
		->and(objectAt(Paginate::class, $variables, 'list', 'pageInfo')->total)->toBe(2)
		->and(objectAt(Paginate::class, $variables, 'list', 'pageInfo')->totalPages)->toBe(1)
		->and(at($variables, 'list', 'filter', 'kinds'))->toBe(['section'])
		->and(at($variables, 'list', 'activities'))->toBe([10 => 'recent', 11 => 'recent'])
		->and(at($variables, 'list', 'sort'))->toBe('entries')
		->and($variables)->not->toHaveKey('summary');
});

it('summarizes the entry types on their statistics tab', function (): void {
	$response = $this->runAction(TypesController::class, 'statistics');

	expect($response->template)->toBe('hub/pages/statistics')
		->and($response->variables['cards'])->toHaveCount(4)
		->and(at(loadCards(at($response->variables, 'cards')), 0, 'segments', 2, 'value'))->toBe(1);
});

it('shows an entry type with entries in sections', function (): void {
	$response = $this->runAction(TypesController::class, 'view', ['id' => 10]);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/types/view')
		->and($variables['selectedTab'])->toBe('overview')
		->and(at($variables, 'info', 'entryType', 'handle'))->toBe('article')
		->and($variables)->not->toHaveKeys(['summary', 'entries', 'reviewList']);
});

it('summarizes an entry type on its statistics tab', function (): void {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 10, 'tab' => 'statistics'])->variables;
	[$entries, $relations, $fields] = loadCards($variables['summary']);

	expect($variables['selectedTab'])->toBe('statistics')
		->and($entries['total'])->toBe(5)
		->and($relations['total'])->toBe(4)
		->and(array_column(arrayAt($fields, 'segments'), 'value', 'label'))->toBe(['Nested entries' => 1, 'References' => 3, 'Plain values' => 1])
		->and($variables)->not->toHaveKeys(['entries', 'reviewList']);
});

it('remembers the filters of the entries tab', function (): void {
	$this->runAction(TypesController::class, 'view', ['id' => 10, 'tab' => 'entries'], ['q' => 'news']);
	$this->runAction(TypesController::class, 'view', ['id' => 10, 'tab' => 'entries']);

	expect(webResponse()->getHeaders()->get('Location'))->toContain('hub/structure/entry-types/10/entries&q=news');
});

it('lists the entries of an entry type on a tab of its own', function (): void {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 10, 'tab' => 'entries'])->variables;

	expect($variables['selectedTab'])->toBe('entries')
		->and(at($variables, 'list', 'listFixed'))->toBe('type')
		->and(at($variables, 'list', 'listPath'))->toBe('hub/structure/entry-types/10/entries')
		->and($variables['list'])->toHaveKey('entries')
		->and($variables)->not->toHaveKey('summary');
});

it('shows the checks of an entry type on its diagnostics tab', function (): void {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 10, 'tab' => 'diagnostics'])->variables;

	expect(array_map(fn(mixed $check): mixed => at($check, 'key'), arrayAt($variables, 'checks')))->toContain('entryTypes.unused', 'entries.drafts')
		->and($variables)->not->toHaveKeys(['summary', 'reviewList']);
});

it('shows nested entry types without an entries list', function (): void {
	expect($this->runAction(TypesController::class, 'view', ['id' => 12])->variables)->not->toHaveKey('entries')
		->and(at(loadCards(at($this->runAction(TypesController::class, 'view', ['id' => 12, 'tab' => 'statistics'])->variables, 'summary')), 1, 'segments', 1, 'value'))->toBe(1);
});

it('lists the nested entries of an entry type on a tab of its own', function (): void {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 12, 'tab' => 'entries'])->variables;

	expect(at($variables, 'list', 'listFixed'))->toBe('type')
		->and(at($variables, 'list', 'sections'))->toBe([])
		->and(array_keys(arrayAt($variables, 'list', 'nestedFields')))->toBe([2])
		->and(array_column(arrayAt($variables, 'list', 'columns'), 'key'))->toContain('origin', 'owner');
});

it('counts the flagged nested entries of nested entry types on their diagnostics tab', function (): void {
	$variables = $this->runAction(TypesController::class, 'view', ['id' => 12, 'tab' => 'diagnostics'])->variables;

	expect(array_map(fn(mixed $check): mixed => at($check, 'key'), arrayAt($variables, 'checks')))->toContain('entries.stale');
});

it('fails for the entries tab of unused entry types', function (): void {
	$this->runAction(TypesController::class, 'view', ['id' => 13, 'tab' => 'entries']);
})->throws(NotFoundHttpException::class);

it('counts fields that are not among all fields as plain fields', function (): void {
	$this->entryTypes[13]->getFieldLayout()->setTabs([]);
	$stray = $this->field(PlainText::class, 50, 'stray');
	unset($this->fields[50]);
	$this->entryType(13, 'orphan', [$stray]);

	expect(array_column(arrayAt(loadCards(at($this->runAction(TypesController::class, 'view', ['id' => 13, 'tab' => 'statistics'])->variables, 'summary')), 2, 'segments'), 'value', 'label'))->toBe(['Nested entries' => 0, 'References' => 0, 'Plain values' => 1]);
});

it('fails for entry types that do not exist', function (): void {
	$this->runAction(TypesController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);

it('leaves out the data of hidden columns', function (): void {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_ENTRY_TYPES => ['order' => [], 'hidden' => ['updated']]]];

	$variables = $this->runAction(TypesController::class, 'index')->variables;

	expect(at($variables, 'list', 'activities'))->toBe([])
		->and(at($variables, 'list', 'entryTypes'))->not->toBeEmpty();
});
