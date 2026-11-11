<?php

declare(strict_types=1);

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\services\Elements;
use craft\web\View;
use Mockery\MockInterface;
use SomehowDigital\Craft\Hub\controllers\WorkController;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\services\WorkService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use SomehowDigital\Craft\Hub\widgets\MyWorkWidget;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function (): void {
	Craft::$app->getCache()?->flush();
	mocked(Craft::$app->getUser())->shouldReceive('getId')->andReturn(7)->byDefault();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 7, 'username' => 'jane']))->byDefault();
	onlyChecks([]);

	/** @var Elements&MockInterface $elementsMock */
	$elementsMock = Mockery::mock(Elements::class)->makePartial();
	$this->elementsMock = $elementsMock;
	$this->elementsMock->shouldReceive('getElementById')->andReturnUsing(fn(int $id): ?Entry => $id === 5 ? new Entry(['id' => 5]) : null);
	$this->elementsMock->shouldReceive('canView')->andReturn(true)->byDefault();
	Craft::$app->set('elements', $this->elementsMock);
});

it('renders what the user is responsible for', function (): void {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1]), new Entry(['id' => 2])]);

	$behavior = $this->runAction(WorkController::class, 'index');

	expect($behavior->template)->toBe('hub/pages/work/overview')
		->and(at($behavior->variables, 'drafts', 'total'))->toBe(2)
		->and(array_keys(arrayAt($behavior->variables, 'workTabs')))->toBe(['overview', 'diagnostics', 'entries', 'assets']);
});

it('filters the checks of the diagnostics tab, offering all of them', function (array $query, array $keys): void {
	onlyChecks(['entries.stale' => Severity::Error, 'assets.large' => Severity::Notice]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 2])]);

	$behavior = $this->runAction(WorkController::class, 'index', ['tab' => 'diagnostics'], $query);

	expect($behavior->template)->toBe('hub/pages/work/diagnostics')
		->and(at($behavior->variables, 'allChecks'))->toHaveCount(2)
		->and(array_map(fn(Check $check): ?string => $check->key, objectsAt(Check::class, $behavior->variables, 'checks')))->toBe($keys);
})->with([
	'nothing' => [[], ['entries.stale', 'assets.large']],
	'kind' => [['kind' => 'assets'], ['assets.large']],
	'severity' => [['severity' => 'error'], ['entries.stale']],
	'check' => [['check' => 'assets.large'], ['assets.large']],
	'unknown values are ignored' => [['check' => 'nope'], ['entries.stale', 'assets.large']],
]);

it('loads the panel of a check of the diagnostics tab alone, with the first of its items', function (): void {
	onlyChecks(['entries.stale' => Severity::Error]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1])]);

	$behavior = $this->runAction(WorkController::class, 'index', ['tab' => 'diagnostics'], ['panel' => 'entries.stale']);

	expect($behavior->template)->toBe('hub/_panels/work/check')
		->and(at($behavior->variables, 'check', 'key'))->toBe('entries.stale')
		->and(at($behavior->variables, 'snoozeDays'))->toBe(WorkService::SNOOZE_DAYS);
});

it('has no panels of checks that flag nothing', function (): void {
	onlyChecks(['entries.stale' => null]);

	$this->runAction(WorkController::class, 'index', ['tab' => 'diagnostics'], ['panel' => 'entries.stale']);
})->throws(NotFoundHttpException::class);

it('leaves out the tabs of what the user has nothing of, or may not view', function (): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', 'accessPlugin-hub', Permission::ViewWork->value]);

	$behavior = $this->runAction(WorkController::class, 'index');

	expect(array_keys(arrayAt($behavior->variables, 'workTabs')))->toBe(['overview', 'diagnostics']);
});

it("has no tabs of what's on the overview, or of what doesn't exist", function (string $tab): void {

	$this->runAction(WorkController::class, 'index', ['tab' => $tab]);
})->with(['drafts', 'coming-up', 'maintained', 'following', 'attention', 'changed', 'nope'])->throws(NotFoundHttpException::class);

it("lists drafts, what's coming up and what's maintained on the overview in full", function (): void {

	$behavior = $this->runAction(WorkController::class, 'index');

	expect($behavior->variables)->toHaveKeys(['drafts', 'comingUp', 'maintained']);
});

it('leaves the variables of the lists on its tabs alone', function (): void {

	$behavior = $this->runAction(WorkController::class, 'index', ['tab' => 'entries']);

	expect(at($behavior->variables, 'list', 'sections'))->not->toHaveKey('drafts')
		->and(at($behavior->variables, 'workSections'))->toHaveKey('drafts');
});

it('remembers the filters of the list tabs', function (string $tab): void {

	$this->runAction(WorkController::class, 'index', ['tab' => $tab], ['q' => 'news']);

	expect($this->session)->toHaveKey('hub:filters:' . WorkService::PATH . "/{$tab}", ['q' => 'news']);

	$this->runAction(WorkController::class, 'index', ['tab' => $tab]);

	expect(webResponse()->getHeaders()->get('Location'))->toContain(WorkService::PATH . "/{$tab}&q=news");
})->with(['entries', 'assets']);

it('shows what was set aside on the diagnostics tab', function (): void {
	$this->db()->respond('FROM {{%hub_snoozes}}', [['elementId' => 1, 'checkKey' => 'entries.stale', 'dateUntil' => null]]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1])]);

	$behavior = $this->runAction(WorkController::class, 'index', ['tab' => 'diagnostics']);

	expect(at($behavior->variables, 'snoozedCount'))->toBe(1)
		->and($behavior->variables)->not->toHaveKey('snoozed');

	$panel = $this->runAction(WorkController::class, 'index', ['tab' => 'diagnostics'], ['panel' => 'hub-snoozed']);

	expect($panel->template)->toBe('hub/_panels/work/snoozed')
		->and($panel->variables['snoozed'])->toHaveCount(1);
});

it('forbids the list tabs without the permission of their page', function (string $tab): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', 'accessPlugin-hub', Permission::ViewWork->value]);

	$this->runAction(WorkController::class, 'index', ['tab' => $tab]);
})->with(['entries', 'assets'])->throws(ForbiddenHttpException::class);

it('forbids users without the permission', function (): void {
	$this->actingAs(permissions: ['accessPlugin-hub']);

	$this->runAction(WorkController::class, 'index');
})->throws(ForbiddenHttpException::class);

it('snoozes an element for the days posted', function (): void {
	$this->postTo(WorkController::class, 'snooze', ['check' => 'entries.stale', 'element' => '5', 'days' => '30']);

	expect($this->db()->queriesMatching('INTO {{%hub_snoozes}}')[0])->toContain("'entries.stale'")->toContain((new DateTime('+30 days', new DateTimeZone('UTC')))->format('Y-m-d'));
});

it('dismisses an element for good without any days', function (): void {
	$this->postTo(WorkController::class, 'snooze', ['check' => 'entries.stale', 'element' => '5', 'days' => '0']);

	expect($this->db()->queriesMatching('INTO {{%hub_snoozes}}')[0])->toContain('NULL');
});

it("rejects days that aren't offered", function (): void {
	$this->postTo(WorkController::class, 'snooze', ['check' => 'entries.stale', 'element' => '5', 'days' => '8']);
})->throws(BadRequestHttpException::class);

it("rejects checks and elements that don't exist", function (string $check, string $element): void {
	$this->postTo(WorkController::class, 'snooze', ['check' => $check, 'element' => $element, 'days' => '7']);
})->with([
	'check' => ['entries.gone', '5'],
	'element' => ['entries.stale', '6'],
])->throws(NotFoundHttpException::class);

it("rejects checks of what isn't an element", function (): void {
	$this->postTo(WorkController::class, 'snooze', ['check' => 'sections.empty', 'element' => '5', 'days' => '7']);
})->throws(NotFoundHttpException::class);

it('rejects elements the user may not view', function (): void {
	$this->elementsMock->shouldReceive('canView')->andReturn(false);

	$this->postTo(WorkController::class, 'snooze', ['check' => 'entries.stale', 'element' => '5', 'days' => '7']);
})->throws(ForbiddenHttpException::class);

it('wakes what was snoozed', function (): void {
	$this->postTo(WorkController::class, 'wake', ['check' => 'entries.stale', 'element' => '5']);

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_snoozes}}')[0])->toContain('`elementId`=5')->toContain("`checkKey`='entries.stale'");
});

it('only snoozes and wakes by posting', function (string $action): void {
	$this->runAction(WorkController::class, $action);
})->with(['snooze', 'wake'])->throws(MethodNotAllowedHttpException::class);

it('offers the widget to users who may view their work, summing it up', function (): void {
	$this->actingAs(permissions: ['accessPlugin-hub', Permission::ViewWork->value]);

	expect(MyWorkWidget::isSelectable())->toBeTrue()
		->and(MyWorkWidget::displayName())->toBe('My Work')
		->and(MyWorkWidget::icon())->toBe('list-check');
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
