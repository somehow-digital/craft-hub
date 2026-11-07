<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\elements\User;
use craft\services\Elements;
use craft\web\View;
use SomehowDigital\Craft\Hub\controllers\WorkController;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\DiagnosticsService;
use SomehowDigital\Craft\Hub\services\WorkService;
use SomehowDigital\Craft\Hub\widgets\MyWork;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function (): void {
	$this->definition = new CheckDefinition(['kind' => CheckKind::Entries, 'handle' => 'stale', 'filter' => 'stale', 'name' => 'Stale']);

	$diagnostics = Mockery::mock(DiagnosticsService::class)->makePartial();
	$diagnostics->shouldReceive('getDefinition')->andReturnUsing(fn(string $key): ?CheckDefinition => $key === 'entries.stale' ? $this->definition : null);
	$this->hub()->set('diagnostics', $diagnostics);

	$this->service = Mockery::mock(WorkService::class)->makePartial();
	$this->hub()->set('work', $this->service);

	// Posts the body params to the action as a signed-in user.
	$this->post = function (string $action, array $body) {
		$this->request();
		$request = Craft::$app->getRequest();
		$request->enableCsrfValidation = false;
		$request->enableCookieValidation = false;
		$request->setBodyParams($body);
		$request->getHeaders()->set('Accept', 'application/json');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => $this->isAdmin]));

		try {
			return Craft::createObject(WorkController::class, ['work', $this->hub()])->runAction($action);
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}
	};

	$this->elements = Mockery::mock(Elements::class)->makePartial();
	$this->elements->shouldReceive('getElementById')->andReturnUsing(fn(int $id): ?Entry => $id === 5 ? new Entry(['id' => 5]) : null);
	$this->elements->shouldReceive('canView')->andReturn(true)->byDefault();
	Craft::$app->set('elements', $this->elements);
});

it('renders what the user is responsible for', function (): void {
	$this->service->shouldReceive('getCounts')->andReturn(['drafts' => 2, 'diagnostics' => 0, 'comingUp' => 0]);
	$this->service->shouldReceive('getDrafts')->andReturn(['items' => [], 'total' => 2]);
	$this->service->shouldReceive('getDiagnostics')->andReturn([]);
	$this->service->shouldReceive('getComingUp')->andReturn(['items' => [], 'total' => 0]);
	$this->service->shouldReceive('getMaintained')->andReturn([]);
	$this->service->shouldReceive('getSnoozed')->andReturn([]);

	$behavior = $this->runAction(WorkController::class, 'index');

	expect($behavior->template)->toBe('hub/pages/work/overview')
		->and($behavior->variables['drafts']['total'])->toBe(2)
		->and(array_keys($behavior->variables['workTabs']))->toBe(['overview', 'diagnostics', 'entries', 'assets']);
});

it('filters the checks of the diagnostics tab, offering all of them', function (array $query, array $keys): void {
	$this->service->shouldReceive('getCounts')->andReturn(['drafts' => 0, 'diagnostics' => 3, 'comingUp' => 0]);
	$this->service->shouldReceive('getMaintained')->andReturn([]);
	$this->service->shouldReceive('getSnoozed')->andReturn([]);
	$check = function (string $key, Severity $severity): Check {
		$check = new Check($key, '', '', $severity, 'list-check', false);
		$check->key = $key;

		return $check;
	};
	$this->service->shouldReceive('getDiagnostics')->with()->andReturn([
		$check('entries.stale', Severity::Error),
		$check('assets.large', Severity::Notice),
	]);
	$this->service->shouldReceive('countSnoozed')->andReturn(0);

	$behavior = $this->runAction(WorkController::class, 'index', ['tab' => 'diagnostics'], $query);

	expect($behavior->template)->toBe('hub/pages/work/diagnostics')
		->and($behavior->variables['allChecks'])->toHaveCount(2)
		->and(array_map(fn(Check $check): ?string => $check->key, $behavior->variables['checks']))->toBe($keys);
})->with([
	'nothing' => [[], ['entries.stale', 'assets.large']],
	'kind' => [['kind' => 'assets'], ['assets.large']],
	'severity' => [['severity' => 'error'], ['entries.stale']],
	'check' => [['check' => 'assets.large'], ['assets.large']],
	'unknown values are ignored' => [['check' => 'nope'], ['entries.stale', 'assets.large']],
]);

it('loads the panel of a check of the diagnostics tab alone, with the first of its items', function (): void {
	$this->service->shouldReceive('getCounts')->andReturn(['drafts' => 0, 'diagnostics' => 1, 'comingUp' => 0]);
	$check = new Check('entries.stale', '', '', Severity::Error, 'list-check', false);
	$check->key = 'entries.stale';
	$this->service->shouldReceive('getDiagnostics')->once()->with(WorkService::CHECK_ITEM_LIMIT, 'entries.stale')->andReturn([$check]);

	$behavior = $this->runAction(WorkController::class, 'index', ['tab' => 'diagnostics'], ['panel' => 'entries.stale']);

	expect($behavior->template)->toBe('hub/_panels/work/check')
		->and($behavior->variables['check'])->toBe($check)
		->and($behavior->variables['snoozeDays'])->toBe(WorkService::SNOOZE_DAYS);
});

it('has no panels of checks that flag nothing', function (): void {
	$this->service->shouldReceive('getCounts')->andReturn(['drafts' => 0, 'diagnostics' => 0, 'comingUp' => 0]);
	$this->service->shouldReceive('getDiagnostics')->andReturn([]);

	$this->runAction(WorkController::class, 'index', ['tab' => 'diagnostics'], ['panel' => 'entries.stale']);
})->throws(NotFoundHttpException::class);

it('leaves out the tabs of what the user has nothing of, or may not view', function (): void {
	$this->service->shouldReceive('getCounts')->andReturn(['drafts' => 0, 'diagnostics' => 0, 'comingUp' => 0]);
	$this->service->shouldReceive('getDiagnostics')->andReturn([]);
	$this->service->shouldReceive('getDrafts')->andReturn(['items' => [], 'total' => 0]);
	$this->service->shouldReceive('getComingUp')->andReturn(['items' => [], 'total' => 0]);
	$this->service->shouldReceive('getMaintained')->andReturn([]);
	$this->service->shouldReceive('getSnoozed')->andReturn([]);
	$access = Mockery::mock(AccessService::class)->makePartial();
	$access->shouldReceive('canView')->andReturn(false);
	$this->hub()->set('access', $access);

	$behavior = $this->runAction(WorkController::class, 'index');

	expect(array_keys($behavior->variables['workTabs']))->toBe(['overview', 'diagnostics']);
});

it("has no tabs of what's on the overview, or of what doesn't exist", function (string $tab): void {
	$this->service->shouldReceive('getCounts')->andReturn(['drafts' => 0, 'diagnostics' => 0, 'comingUp' => 0]);
	$this->service->shouldReceive('getMaintained')->andReturn([]);
	$this->service->shouldReceive('getSnoozed')->andReturn([]);

	$this->runAction(WorkController::class, 'index', ['tab' => $tab]);
})->with(['drafts', 'coming-up', 'maintained', 'following', 'attention', 'changed', 'nope'])->throws(NotFoundHttpException::class);

it("lists drafts, what's coming up and what's maintained on the overview in full", function (): void {
	$this->service->shouldReceive('getCounts')->andReturn(['drafts' => 0, 'diagnostics' => 0, 'comingUp' => 0]);
	$this->service->shouldReceive('getDiagnostics')->andReturn([]);
	$this->service->shouldReceive('getDrafts')->once()->with()->andReturn(['items' => [], 'total' => 0]);
	$this->service->shouldReceive('getComingUp')->once()->with()->andReturn(['items' => [], 'total' => 0]);
	$this->service->shouldReceive('getMaintained')->once()->andReturn([]);

	$behavior = $this->runAction(WorkController::class, 'index');

	expect($behavior->variables)->toHaveKeys(['drafts', 'comingUp', 'maintained']);
});

it('leaves the variables of the lists on its tabs alone', function (): void {
	$this->service->shouldReceive('getCounts')->andReturn(['drafts' => 0, 'diagnostics' => 0, 'comingUp' => 0]);
	$this->service->shouldReceive('getMaintained')->andReturn([]);

	$behavior = $this->runAction(WorkController::class, 'index', ['tab' => 'entries']);

	expect($behavior->variables['list']['sections'])->not->toHaveKey('drafts')
		->and($behavior->variables['workSections'])->toHaveKey('drafts');
});

it('remembers the filters of the list tabs', function (string $tab): void {
	$this->service->shouldReceive('getCounts')->andReturn(['drafts' => 0, 'diagnostics' => 0, 'comingUp' => 0]);
	$this->service->shouldReceive('getMaintained')->andReturn([]);

	$this->runAction(WorkController::class, 'index', ['tab' => $tab], ['q' => 'news']);

	expect($this->session)->toHaveKey('hub:filters:' . WorkService::PATH . "/{$tab}", ['q' => 'news']);

	$this->runAction(WorkController::class, 'index', ['tab' => $tab]);

	expect(Craft::$app->getResponse()->getHeaders()->get('Location'))->toContain(WorkService::PATH . "/{$tab}&q=news");
})->with(['entries', 'assets']);

it('shows what was set aside on the diagnostics tab', function (): void {
	$this->service->shouldReceive('getCounts')->andReturn(['drafts' => 0, 'diagnostics' => 0, 'comingUp' => 0]);
	$this->service->shouldReceive('getMaintained')->andReturn([]);
	$this->service->shouldReceive('getDiagnostics')->andReturn([]);
	$this->service->shouldReceive('getSnoozed')->andReturn([['element' => new Entry(['id' => 1]), 'check' => $this->definition, 'until' => null]]);

	$this->service->shouldReceive('countSnoozed')->andReturn(1);

	$behavior = $this->runAction(WorkController::class, 'index', ['tab' => 'diagnostics']);

	expect($behavior->variables['snoozedCount'])->toBe(1)
		->and($behavior->variables)->not->toHaveKey('snoozed');

	$panel = $this->runAction(WorkController::class, 'index', ['tab' => 'diagnostics'], ['panel' => 'hub-snoozed']);

	expect($panel->template)->toBe('hub/_panels/work/snoozed')
		->and($panel->variables['snoozed'])->toHaveCount(1);
});

it('forbids the list tabs without the permission of their page', function (string $tab): void {
	$access = Mockery::mock(AccessService::class)->makePartial();
	$access->shouldReceive('canView')->andReturn(false);
	$this->hub()->set('access', $access);

	$this->runAction(WorkController::class, 'index', ['tab' => $tab]);
})->with(['entries', 'assets'])->throws(ForbiddenHttpException::class);

it('forbids users without the permission', function (): void {
	$this->actingAs(permissions: ['accessPlugin-hub']);

	$this->runAction(WorkController::class, 'index');
})->throws(ForbiddenHttpException::class);

it('snoozes an element for the days posted', function (): void {
	$this->service->shouldReceive('snooze')->once()->with($this->definition, 5, 30);

	($this->post)('snooze', ['check' => 'entries.stale', 'element' => '5', 'days' => '30']);
});

it('dismisses an element for good without any days', function (): void {
	$this->service->shouldReceive('snooze')->once()->with($this->definition, 5, null);

	($this->post)('snooze', ['check' => 'entries.stale', 'element' => '5', 'days' => '0']);
});

it("rejects days that aren't offered", function (): void {
	($this->post)('snooze', ['check' => 'entries.stale', 'element' => '5', 'days' => '8']);
})->throws(BadRequestHttpException::class);

it("rejects checks and elements that don't exist", function (string $check, string $element): void {
	($this->post)('snooze', ['check' => $check, 'element' => $element, 'days' => '7']);
})->with([
	'check' => ['entries.gone', '5'],
	'element' => ['entries.stale', '6'],
])->throws(NotFoundHttpException::class);

it("rejects checks of what isn't an element", function (): void {
	$this->definition->kind = CheckKind::Sections;

	($this->post)('snooze', ['check' => 'entries.stale', 'element' => '5', 'days' => '7']);
})->throws(NotFoundHttpException::class);

it('rejects elements the user may not view', function (): void {
	$this->elements->shouldReceive('canView')->andReturn(false);

	($this->post)('snooze', ['check' => 'entries.stale', 'element' => '5', 'days' => '7']);
})->throws(ForbiddenHttpException::class);

it('wakes what was snoozed', function (): void {
	$this->service->shouldReceive('wake')->once()->with('entries.stale', 5);

	($this->post)('wake', ['check' => 'entries.stale', 'element' => '5']);
});

it('only snoozes and wakes by posting', function (string $action): void {
	$this->runAction(WorkController::class, $action);
})->with(['snooze', 'wake'])->throws(MethodNotAllowedHttpException::class);

it('offers the widget to users who may view their work, summing it up', function (): void {
	$this->actingAs(permissions: ['accessPlugin-hub', Hub::PERMISSION_VIEW_WORK]);

	expect(MyWork::isSelectable())->toBeTrue()
		->and(MyWork::displayName())->toBe('My Work')
		->and(MyWork::icon())->toBe('list-check');
});

it('states what a list is fixed to among its filters, which nothing can change', function (): void {
	$macros = "{% import 'hub/_components/macros' as hub %}{{ hub.fixedFilters([{ label: 'Author', value: 'Me', icon: 'user' }]) }}";
	$html = Craft::$app->getView()->renderString($macros, [], View::TEMPLATE_MODE_CP);

	expect($html)->toContain('hub-fixed-filter')
		->and($html)->toContain('Author')
		->and($html)->toContain('Me')
		->and($html)->not->toContain('data-hub-filter')
		->and($html)->not->toContain('<input')
		->and($html)->not->toContain('<button');
});
