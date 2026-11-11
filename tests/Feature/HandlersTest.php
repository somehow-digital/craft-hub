<?php

declare(strict_types=1);

use craft\base\ElementInterface;
use craft\controllers\UserSettingsController;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\models\UserGroup;
use craft\services\UserGroups;
use craft\web\CpScreenResponseBehavior;
use craft\web\Response;
use craft\web\View;
use Mockery\MockInterface;
use SomehowDigital\Craft\Hub\assets\SidebarAsset;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\handlers\DefineSidebarHtmlHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpNavItemsHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpRoutesHandler;
use SomehowDigital\Craft\Hub\handlers\UserGroupSidebarHandler;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\MaintainerSource;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use SomehowDigital\Craft\Hub\Tests\TestCase;
use yii\base\Action;
use yii\base\ActionEvent;

it('routes the Hub pages to their controllers', function (): void {
	$event = new RegisterUrlRulesEvent();
	(new RegisterCpRoutesHandler())($event);

	expect($event->rules)->toMatchArray([
		'hub/data' => 'hub/nav/data',
		'hub/data/entries' => 'hub/entries/index',
		'hub/data/entries/diagnostics' => 'hub/entries/diagnostics',
		'hub/data/entries/statistics' => 'hub/entries/statistics',
		'hub/data/entries/<id:\d+>' => 'hub/entries/view',
		'hub/data/entries/<id:\d+>/<tab:history|diagnostics>' => 'hub/entries/view',
		'hub/data/assets' => 'hub/files/index',
		'hub/data/assets/diagnostics' => 'hub/files/diagnostics',
		'hub/data/assets/trash' => 'hub/files/trash',
		'hub/data/assets/<id:\d+>' => 'hub/files/view',
		'hub/data/assets/<id:\d+>/<tab:transforms|diagnostics>' => 'hub/files/view',
		'hub/data/users' => 'hub/users/index',
		'hub/data/users/diagnostics' => 'hub/users/diagnostics',
		'hub/data/users/groups' => 'hub/users/groups',
		'hub/data/users/groups/<id:\d+>' => 'hub/users/group',
		'hub/data/users/groups/<id:\d+>/<tab:users>' => 'hub/users/group',
		'hub/data/entries/trash' => 'hub/entries/trash',
		'hub/data/users/statistics' => 'hub/users/statistics',
		'hub/data/users/<id:\d+>' => 'hub/users/view',
		'hub/data/users/<id:\d+>/<tab:entries|assets|diagnostics>' => 'hub/users/view',
		'hub/structure' => 'hub/nav/structure',
		'hub/structure/entry-types/<id:\d+>' => 'hub/types/view',
		'hub/structure/sections/diagnostics' => 'hub/sections/diagnostics',
		'hub/structure/sections/<id:\d+>' => 'hub/sections/view',
		'hub/structure/sections/<id:\d+>/<tab:entries|statistics|diagnostics>' => 'hub/sections/view',
		'hub/structure/fields/<id:\d+>' => 'hub/fields/view',
		'hub/structure/volumes/<id:\d+>' => 'hub/volumes/view',
		'hub/structure/volumes/<id:\d+>/<tab:assets|statistics|diagnostics>' => 'hub/volumes/view',
		'hub/manage' => 'hub/nav/manage',
		'hub/manage/activity' => 'hub/activity/index',
		'hub/manage/settings' => 'hub/settings/index',
		'hub/manage/settings/labels' => 'hub/labels/index',
		'hub/manage/settings/labels/new' => 'hub/labels/edit',
		'hub/manage/settings/labels/<labelId:\d+>' => 'hub/labels/edit',
		'hub/manage/settings/diagnostics' => 'hub/checks/index',
		'hub/manage/settings/diagnostics/new/<kind:[a-zA-Z]+>' => 'hub/checks/edit',
		'hub/manage/settings/diagnostics/<checkId:\d+>' => 'hub/checks/edit',
		'hub/manage/settings/filters' => 'hub/filters/index',
		'hub/manage/settings/filters/new/<kind:[a-zA-Z]+>' => 'hub/filters/edit',
		'hub/manage/settings/filters/<filterId:\d+>' => 'hub/filters/edit',
		'hub/manage/settings/maintainers' => 'hub/settings/maintainers',
		'hub/manage/settings/statistics' => 'hub/settings/statistics',
		'hub/manage/settings/activity' => 'hub/settings/activity',
		'hub/manage/settings/trash' => 'hub/settings/trash',
	])->and($event->rules)->toHaveCount(60);

	foreach (array_unique(array_filter($event->rules, is_string(...))) as $route) {
		[, $controller, $action] = explode('/', $route);
		$class = 'SomehowDigital\\Craft\\Hub\\controllers\\' . ucfirst($controller) . 'Controller';

		expect(method_exists($class, 'action' . ucfirst($action)))->toBeTrue("{$route} has no action");
	}
});

it('adds the hub navigation item right before the data one and the structure one right after it', function (): void {
	$event = new RegisterCpNavItemsEvent(['navItems' => [
		['label' => 'Entries', 'url' => 'entries'],
		['label' => 'Data', 'url' => 'hub/data'],
		['label' => 'Settings', 'url' => 'settings'],
	]]);
	$this->handler('navHandler', RegisterCpNavItemsHandler::class)($event);

	expect(array_column($event->navItems, 'url'))->toBe(['entries', 'hub/manage', 'hub/data', 'hub/structure', 'settings']);
});

it("puts the navigation items in place of the structure one, as the plugin's own, for users who may not open the data", function (): void {
	$this->request();
	$this->actingAs(permissions: [Permission::AccessStructure->value, Page::Fields->viewPermission(), Permission::ManageLabels->value]);
	$event = new RegisterCpNavItemsEvent(['navItems' => [
		['label' => 'Entries', 'url' => 'entries'],
		['label' => 'Structure', 'url' => 'hub/structure'],
		['label' => 'Settings', 'url' => 'settings'],
	]]);
	$this->handler('navHandler', RegisterCpNavItemsHandler::class)($event);

	expect(array_column($event->navItems, 'url'))->toBe(['entries', 'hub/manage', 'hub/structure', 'settings']);
});

it('adds no structure navigation item without the data one', function (): void {
	$event = new RegisterCpNavItemsEvent(['navItems' => [['label' => 'Entries', 'url' => 'entries']]]);
	$this->handler('navHandler', RegisterCpNavItemsHandler::class)($event);

	expect($event->navItems)->toHaveCount(1);
});

function sidebarEvent(mixed $sender): DefineHtmlEvent
{
	if (!is_object($sender)) {
		throw new LogicException('No sender.');
	}

	$event = new DefineHtmlEvent(['html' => '<existing>']);
	$event->sender = $sender;

	return $event;
}

/**
 * Mocks the view the sidebar renders in, to keep the variables it renders the panel with.
 *
 * @return ArrayObject<string,mixed>
 */
function sidebarRendered(TestCase $test): ArrayObject
{
	/** @var ArrayObject<string,mixed> $rendered */
	$rendered = new ArrayObject();
	$view = $test->mockComponent('view', View::class);
	$view->shouldReceive('registerAssetBundle')->once()->with(SidebarAsset::class);
	$view->shouldReceive('renderTemplate')->once()->with('hub/_sidebar/hub', Mockery::on(function (array $variables) use ($rendered): bool {
		$rendered->exchangeArray($variables);

		return true;
	}))->andReturn('<hub>');

	return $rendered;
}

/**
 * Sets the labels and what they label, as `[list, itemId, labelId]`.
 *
 * @param list<array{0:string,1:int,2:int}> $labeled
 */
function sidebarLabels(TestCase $test, array $labeled): void
{
	$test->db()->respond(sqlFrom(Tables::LABELS), [
		['id' => 1, 'name' => 'Review', 'description' => null, 'icon' => null, 'color' => null, 'sortOrder' => 1, 'uid' => 'review'],
		['id' => 2, 'name' => 'Keep', 'description' => null, 'icon' => null, 'color' => null, 'sortOrder' => 2, 'uid' => 'keep'],
	]);
	$test->db()->respond(sqlFrom(Tables::LABELED), array_map(fn(array $row): array => ['list' => $row[0], 'itemId' => $row[1], 'labelId' => $row[2]], $labeled));
}

/**
 * @return list<mixed>
 */
function namesOf(mixed $items): array
{
	return array_values(array_map(fn(mixed $item): mixed => at($item, 'name'), listOf($items)));
}

/**
 * @return list<string|null>
 */
function keysOf(mixed $checks): array
{
	return array_map(fn(Check $check): ?string => $check->key, objectsAt(Check::class, ['checks' => $checks], 'checks'));
}

it('adds a panel with the labels, checks, last edit and references of an entry to its sidebar', function (): void {
	$entry = new Entry(['id' => 5, 'siteId' => 1]);
	onlyChecks(['entries.stale' => Severity::Warning]);
	ElementQueries::respond(Entry::class, [$entry]);
	sidebarLabels($this, [[ColumnService::LIST_ENTRIES, 5, 1]]);
	$this->db()->respond(sqlFrom(Tables::EDITS), [['elementId' => 5, 'userId' => null, 'dateEdited' => '2026-01-01 00:00:00']]);
	$rendered = sidebarRendered($this);

	$event = sidebarEvent($entry);
	$this->handler('sidebarHandler', DefineSidebarHtmlHandler::class)($event);

	expect($event->html)->toBe('<existing><hub>')
		->and($rendered['element'])->toBe($entry)
		->and($rendered['list'])->toBe(ColumnService::LIST_ENTRIES)
		->and($rendered['activitySubject'])->toBe('entry')
		->and($rendered['maintainers'])->toBe([])
		->and(namesOf($rendered['labels']))->toBe(['Review'])
		->and(namesOf($rendered['allLabels']))->toBe(['Review', 'Keep'])
		->and($rendered['canManageLabels'])->toBeTrue()
		->and(keysOf($rendered['checks']))->toBe(['entries.stale'])
		->and(asDateTime(at($rendered['lastEdit'], 'date'))->format('Y'))->toBe('2026')
		->and($rendered['references'])->toBe([])
		->and($rendered['referenced'])->toBe([])
		->and($rendered['maintained'])->toBe([]);
});

it('adds the panel to the sidebar of assets and users, with their own list and checks', function (Closure $sender, string $list, string $check): void {
	/** @var ElementInterface $element */
	$element = $sender();
	onlyChecks([$check => Severity::Notice]);
	ElementQueries::respond($element::class, [$element]);
	$rendered = sidebarRendered($this);

	$event = sidebarEvent($element);
	$this->handler('sidebarHandler', DefineSidebarHtmlHandler::class)($event);

	expect($event->html)->toBe('<existing><hub>')
		->and($rendered['element'])->toBe($element)
		->and($rendered['list'])->toBe($list)
		->and($rendered['activitySubject'])->toBe(ActivitySubject::fromElement($element)->value)
		->and(keysOf($rendered['checks']))->toBe([$check])
		->and($rendered['lastEdit'])->toBeNull();
})->with([
	'asset' => [fn(): Asset => new Asset(['id' => 5]), ColumnService::LIST_FILES, 'assets.large'],
	'user' => [fn(): User => new User(['id' => 5]), ColumnService::LIST_USERS, 'users.locked'],
]);

it('shows the labels and checks of the canonical entry on its drafts, with its references', function (): void {
	$canonical = new Entry(['id' => 5]);
	/** @var Entry&MockInterface $draft */
	$draft = Mockery::mock(Entry::class)->makePartial();
	$draft->id = 7;
	$draft->shouldReceive('getIsUnpublishedDraft')->andReturnFalse();
	$draft->shouldReceive('getCanonical')->with(true)->andReturn($canonical);
	onlyChecks([]);
	sidebarLabels($this, [[ColumnService::LIST_ENTRIES, 5, 2], [ColumnService::LIST_ENTRIES, 7, 1]]);
	$this->db()->respond(sqlFrom(Tables::EDITS), [['elementId' => 5, 'userId' => null, 'dateEdited' => '2026-01-01 00:00:00']]);
	$rendered = sidebarRendered($this);

	$event = sidebarEvent($draft);
	$this->handler('sidebarHandler', DefineSidebarHtmlHandler::class)($event);

	expect($event->html)->toBe('<existing><hub>')
		->and($rendered['element'])->toBe($canonical)
		->and(namesOf($rendered['labels']))->toBe(['Keep'])
		->and(asDateTime(at($rendered['lastEdit'], 'date'))->format('Y'))->toBe('2026')
		->and($rendered['references'])->toBe([]);
});

it('offers no labels to pick on revisions, nor to users who may not label the element', function (Closure $sender, bool $canApply): void {
	if (!$canApply) {
		$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Page::Entries->viewPermission()]);
	}

	onlyChecks([]);
	sidebarLabels($this, []);
	$rendered = sidebarRendered($this);

	$this->handler('sidebarHandler', DefineSidebarHtmlHandler::class)(sidebarEvent($sender()));

	expect($rendered['labels'])->toBe([])
		->and($rendered['allLabels'])->toBeNull();
})->with([
	'revision' => [function (): Entry {
		/** @var Entry&MockInterface $revision */
		$revision = Mockery::mock(Entry::class)->makePartial();
		$revision->id = 7;
		$revision->shouldReceive('getIsUnpublishedDraft')->andReturnFalse();
		$revision->shouldReceive('getIsRevision')->andReturnTrue();
		$revision->shouldReceive('getCanonical')->andReturn(new Entry(['id' => 5]));

		return $revision;
	}, true],
	'without the permission' => [fn(): Entry => new Entry(['id' => 5]), false],
]);

it('shows the maintainers of an element along with those of its fields, on top of what Hub knows of it', function (): void {
	$this->createContentModel();
	$this->hub()->settings->enableMaintainers = true;
	$this->db()->respond(sqlFrom(Tables::MAINTAINERS), [['list' => ColumnService::LIST_SECTIONS, 'itemId' => 1, 'userId' => 3, 'groupId' => null]]);
	ElementQueries::respond(User::class, [new User(['id' => 3, 'username' => 'jane'])]);
	onlyChecks([]);
	$entry = new Entry(['id' => 5, 'sectionId' => 1, 'typeId' => 10]);
	$rendered = sidebarRendered($this);

	try {
		$event = sidebarEvent($entry);
		$this->handler('sidebarHandler', DefineSidebarHtmlHandler::class)($event);
	} finally {
		$this->hub()->settings->enableMaintainers = false;
	}

	$sources = objectsAt(MaintainerSource::class, $rendered->getArrayCopy(), 'maintainers');

	expect($event->html)->toBe('<existing><hub>')
		->and($sources)->toHaveCount(1)
		->and($sources[0]->list)->toBe(ColumnService::LIST_SECTIONS)
		->and(array_map(fn(User $user): ?string => $user->username, $sources[0]->users))->toBe(['jane']);
});

it('shows only the maintainers to users who may not view the Hub page of the element', function (): void {
	$this->actingAs(permissions: ['accessCp']);
	$this->createContentModel();
	$this->hub()->settings->enableMaintainers = true;
	$this->db()->respond(sqlFrom(Tables::MAINTAINERS), [['list' => ColumnService::LIST_SECTIONS, 'itemId' => 1, 'userId' => 3, 'groupId' => null]]);
	ElementQueries::respond(User::class, [new User(['id' => 3, 'username' => 'jane'])]);
	$entry = new Entry(['id' => 5, 'sectionId' => 1, 'typeId' => 10]);
	$rendered = sidebarRendered($this);

	try {
		$event = sidebarEvent($entry);
		$this->handler('sidebarHandler', DefineSidebarHtmlHandler::class)($event);
	} finally {
		$this->hub()->settings->enableMaintainers = false;
	}

	expect($event->html)->toBe('<existing><hub>')
		->and($rendered['maintainers'])->toHaveCount(1)
		->and($rendered->getArrayCopy())->toMatchArray([
			'element' => $entry,
			'list' => ColumnService::LIST_ENTRIES,
			'activitySubject' => 'entry',
			'labels' => null,
			'allLabels' => null,
			'canManageLabels' => false,
			'checks' => null,
			'lastEdit' => null,
			'references' => null,
			'referenced' => null,
			'maintained' => null,
			'activities' => null,
		]);
});

it('adds nothing for users who may not view the Hub page of an element without maintainers', function (): void {
	$this->actingAs(permissions: ['accessCp']);

	$event = sidebarEvent(new Entry(['id' => 5]));
	$this->handler('sidebarHandler', DefineSidebarHtmlHandler::class)($event);

	expect($event->html)->toBe('<existing>');
});

it('leaves out the labels and checks while they are disabled', function (): void {
	$this->hub()->settings->enableDiagnostics = false;
	$this->hub()->settings->enableLabels = false;
	$rendered = sidebarRendered($this);

	try {
		$this->handler('sidebarHandler', DefineSidebarHtmlHandler::class)(sidebarEvent(new Asset(['id' => 5])));
	} finally {
		$this->hub()->settings->enableDiagnostics = true;
		$this->hub()->settings->enableLabels = true;
	}

	expect($rendered['labels'])->toBeNull()
		->and($rendered['allLabels'])->toBeNull()
		->and($rendered['checks'])->toBeNull();
});

it('leaves the sidebar of other elements, new entries and unpublished drafts alone', function (Closure $sender): void {
	$this->mockComponent('view', View::class)->shouldNotReceive('registerAssetBundle');

	$event = sidebarEvent($sender());
	$this->handler('sidebarHandler', DefineSidebarHtmlHandler::class)($event);

	expect($event->html)->toBe('<existing>');
})->with([
	'category' => fn(): Category => new Category(['id' => 5]),
	'new entry' => fn(): Entry => new Entry(),
	'unpublished draft' => function (): Entry {
		/** @var Entry&MockInterface $draft */
		$draft = Mockery::mock(Entry::class)->makePartial();
		$draft->id = 5;
		$draft->shouldReceive('getIsUnpublishedDraft')->andReturnTrue();

		return $draft;
	},
]);

it('leaves the sidebar alone for users without access', function (Closure $sender, Page $page): void {
	$this->actingAs(permissions: ['accessCp']);
	$this->mockComponent('view', View::class)->shouldNotReceive('registerAssetBundle');

	$event = sidebarEvent($sender());
	$this->handler('sidebarHandler', DefineSidebarHtmlHandler::class)($event);

	expect($event->html)->toBe('<existing>')
		->and(Craft::$app->getUser()->checkPermission($page->viewPermission()))->toBeFalse();
})->with([
	'entry' => [fn(): Entry => new Entry(['id' => 5]), Page::Entries],
	'asset' => [fn(): Asset => new Asset(['id' => 5]), Page::Assets],
	'user' => [fn(): User => new User(['id' => 5]), Page::Users],
]);

it('adds the latest activity and what a user maintains to the sidebar', function (): void {
	$this->createContentModel();
	$this->hub()->settings->enableMaintainers = true;
	$this->db()->tables = [Tables::ACTIVITY];
	$this->db()->respond(sqlFrom(Tables::MAINTAINERS), [['list' => ColumnService::LIST_SECTIONS, 'itemId' => 1, 'userId' => 5, 'groupId' => null]]);
	$this->db()->respond(sqlFrom(Tables::ACTIVITY), [activityRow(['subject' => 'user', 'subjectId' => 5])]);
	$user = new User(['id' => 5, 'username' => 'jane']);
	ElementQueries::respond(User::class, [$user]);
	onlyChecks([]);
	$rendered = sidebarRendered($this);

	try {
		$this->handler('sidebarHandler', DefineSidebarHtmlHandler::class)(sidebarEvent($user));
	} finally {
		$this->hub()->settings->enableMaintainers = false;
	}

	$maintained = objectsAt(MaintainerSource::class, $rendered->getArrayCopy(), 'maintained');

	expect($maintained)->toHaveCount(1)
		->and($maintained[0]->list)->toBe(ColumnService::LIST_SECTIONS)
		->and($rendered['activities'])->toHaveCount(1)
		->and($rendered['activitySubject'])->toBe('user');
});

/**
 * Event of Craft's user group page, with the given params of its action and, unless given, a screen as its result.
 */
/**
 * @param array<string,mixed> $params
 */
function userGroupEvent(array $params, string $action = 'edit-group', ?Response $result = null): ActionEvent
{
	$controller = new UserSettingsController('user-settings', Craft::$app);
	$controller->actionParams = $params;

	if (!$result instanceof Response) {
		$result = new Response();
		$result->attachBehavior(CpScreenResponseBehavior::NAME, CpScreenResponseBehavior::class);
	}

	$event = new ActionEvent(new Action($action, $controller));
	$event->result = $result;

	return $event;
}

function userGroupScreen(ActionEvent $event): CpScreenResponseBehavior
{
	$result = $event->result;
	if (!$result instanceof Response) {
		throw new LogicException('No response.');
	}

	$behavior = $result->getBehavior(CpScreenResponseBehavior::NAME);
	if (!$behavior instanceof CpScreenResponseBehavior) {
		throw new LogicException('No screen.');
	}

	return $behavior;
}

function userGroupSidebar(ActionEvent $event): mixed
{
	$result = $event->result;
	if (!$result instanceof Response) {
		throw new LogicException('No response.');
	}

	$behavior = $result->getBehavior(CpScreenResponseBehavior::NAME);

	return $behavior instanceof CpScreenResponseBehavior ? $behavior->metaSidebarHtml : null;
}

it('adds a panel with the labels, what it maintains and the latest activity to the sidebar of a user group', function (array $params): void {
	$group = new UserGroup(['id' => 3, 'name' => 'Editors']);
	$this->createContentModel();
	$this->hub()->settings->enableMaintainers = true;
	$this->db()->tables = [Tables::ACTIVITY];
	$this->db()->respond(sqlFrom(Tables::MAINTAINERS), [['list' => ColumnService::LIST_SECTIONS, 'itemId' => 1, 'userId' => null, 'groupId' => 3]]);
	$this->db()->respond(sqlFrom(Tables::ACTIVITY), [activityRow(['subject' => 'userGroup', 'subjectId' => 3])]);
	sidebarLabels($this, [[ColumnService::LIST_USER_GROUPS, 3, 1]]);
	$this->mockComponent('userGroups', UserGroups::class)->shouldReceive('getGroupById')->with(3)->andReturn($group);
	$rendered = sidebarRendered($this);

	/** @var array<string,mixed> $params */
	$event = userGroupEvent($params);
	userGroupScreen($event)->metaSidebarHtml(fn(): string => '<existing>');

	try {
		$this->handler('groupSidebarHandler', UserGroupSidebarHandler::class)($event);
	} finally {
		$this->hub()->settings->enableMaintainers = false;
	}

	expect(userGroupSidebar($event))->toBe('<existing><hub>')
		->and($rendered['element'])->toBe($group)
		->and($rendered['list'])->toBe(ColumnService::LIST_USER_GROUPS)
		->and($rendered['activitySubject'])->toBe('userGroup')
		->and(namesOf($rendered['labels']))->toBe(['Review'])
		->and($rendered['maintainers'])->toBe([])
		->and($rendered['maintained'])->toHaveCount(1)
		->and($rendered['activities'])->toHaveCount(1);
})->with([
	'of the URL' => [['groupId' => 3]],
	'failed to save' => [['groupId' => null, 'group' => new UserGroup(['id' => 3])]],
]);

it('leaves user group pages alone without a group to show or a screen to add to', function (ActionEvent $event): void {
	$this->mockComponent('view', View::class)->shouldNotReceive('registerAssetBundle');

	$this->handler('groupSidebarHandler', UserGroupSidebarHandler::class)($event);

	expect(userGroupSidebar($event))->toBeNull();
})->with([
	'new group' => fn(): ActionEvent => userGroupEvent(['groupId' => null]),
	'other action' => fn(): ActionEvent => userGroupEvent(['groupId' => 3], 'save-group'),
	'template of Craft Team' => fn(): ActionEvent => userGroupEvent(['groupId' => 3], result: new Response()),
]);

it('leaves user group pages alone for users without access', function (): void {
	$this->actingAs(permissions: ['accessCp']);
	$this->mockComponent('userGroups', UserGroups::class)->shouldReceive('getGroupById')->andReturn(new UserGroup(['id' => 3]));
	$this->mockComponent('view', View::class)->shouldNotReceive('registerAssetBundle');

	$event = userGroupEvent(['groupId' => 3]);
	$this->handler('groupSidebarHandler', UserGroupSidebarHandler::class)($event);

	expect(userGroupSidebar($event))->toBeNull();
});

it('renders the panel of a user group, linking to its Hub page', function (): void {
	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);
	Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

	$html = Craft::$app->getView()->renderTemplate('hub/_sidebar/hub', [
		'element' => new UserGroup(['id' => 3, 'name' => 'Editors']),
		'list' => ColumnService::LIST_USER_GROUPS,
		'maintainers' => [],
		'activitySubject' => 'userGroup',
		'labels' => [],
		'allLabels' => [],
		'canManageLabels' => false,
		'checks' => null,
		'lastEdit' => null,
		'references' => null,
		'referenced' => null,
		'maintained' => [],
		'activities' => null,
	]);

	expect($html)->toContain('data-hub-sidebar-labels="' . ColumnService::LIST_USER_GROUPS . '"')
		->and($html)->toContain('data-hub-sidebar-id="3"')
		->and($html)->toContain('hub/data/users/groups/3');
});
