<?php

use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\BulkController;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\UserGroupFilter;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

beforeEach(function () {
	$this->identity = new User(['id' => 1, 'username' => 'admin', 'admin' => true]);

	// Posts the body params to an action of the controller as the signed-in user, asking for JSON, for the list on `hub/test`.
	$this->post = function (string $action, array $body): Response {
		$this->request();
		$request = Craft::$app->getRequest();
		$request->enableCsrfValidation = false;
		$request->enableCookieValidation = false;
		$request->setBodyParams(['path' => 'hub/test', ...$body]);
		$request->getHeaders()->set('Accept', 'application/json');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);

		try {
			return Craft::createObject(BulkController::class, ['bulk', $this->hub()])->runAction($action);
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}
	};

	// Applies the action to the items with the `ids`, selected in the list beforehand.
	$this->apply = function (array $body): Response {
		$this->session['hub:bulk:selection:' . $body['list'] . ':hub/test'] = array_map('intval', array_filter($body['ids'] ?? [], 'is_numeric'));
		unset($body['ids']);

		return ($this->post)('apply', $body);
	};
});

it('offers the actions of each list', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);
	$bulk = $this->hub()->bulk;

	expect(array_keys($bulk->getActions(ColumnService::LIST_ENTRIES)))->toBe([BulkService::ACTION_LABELS, BulkService::ACTION_ENABLE, BulkService::ACTION_DISABLE, BulkService::ACTION_DELETE])
		->and(array_keys($bulk->getActions(ColumnService::LIST_FILES)))->toBe([BulkService::ACTION_LABELS, BulkService::ACTION_DOWNLOAD, BulkService::ACTION_DELETE_TRANSFORMS, BulkService::ACTION_DELETE])
		->and(array_keys($bulk->getActions(ColumnService::LIST_USERS)))->toBe([BulkService::ACTION_LABELS, BulkService::ACTION_SUSPEND, BulkService::ACTION_UNSUSPEND])
		->and(array_keys($bulk->getActions(ColumnService::LIST_SECTIONS)))->toBe([BulkService::ACTION_LABELS])
		->and($bulk->getActions('bogus'))->toBe([]);
});

it('offers labeling while there are labels or the user may create some', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_STRUCTURE, Page::Sections->viewPermission(), Page::Sections->labelPermission()]);
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 2, 'username' => 'editor']));

	expect($this->hub()->bulk->getActions(ColumnService::LIST_SECTIONS))->toBe([]);

	$this->db()->respond('FROM {{%hub_labels}}', [['id' => 1, 'name' => 'Keep', 'icon' => null, 'color' => null, 'sortOrder' => 1, 'uid' => 'label-keep']]);
	$this->hub()->set('labels', new LabelService());

	expect($this->hub()->bulk->getActions(ColumnService::LIST_SECTIONS))->toHaveKey(BulkService::ACTION_LABELS)
		->and($this->hub()->bulk->getActions(ColumnService::LIST_SECTIONS)[BulkService::ACTION_LABELS])->toMatchArray(['labels' => true, 'keep' => true]);
});

it('offers the actions of a list only to users with the permissions', function (array $permissions, array $actions) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Entries->viewPermission(), ...$permissions]);
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 2, 'username' => 'editor']));
	$this->db()->respond('FROM {{%hub_labels}}', [['id' => 1, 'name' => 'Keep', 'icon' => null, 'color' => null, 'sortOrder' => 1, 'uid' => 'label-keep']]);
	$this->hub()->set('labels', new LabelService());

	expect(array_keys($this->hub()->bulk->getActions(ColumnService::LIST_ENTRIES)))->toBe($actions);
})->with([
	'none' => [[], []],
	'labels' => [[Page::Entries->labelPermission()], [BulkService::ACTION_LABELS]],
	'enabling' => [[Hub::PERMISSION_ENABLE_ENTRIES], [BulkService::ACTION_ENABLE, BulkService::ACTION_DISABLE]],
	'deleting' => [[Hub::PERMISSION_DELETE_ENTRIES], [BulkService::ACTION_DELETE]],
	'an action of another page' => [[Hub::PERMISSION_DELETE_ASSETS], []],
]);

it('offers no actions for lists the user may not view', function (string $list) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Entries->viewPermission(), Page::Users->labelPermission(), Page::Sections->labelPermission(), Hub::PERMISSION_SUSPEND_USERS]);
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);

	expect($this->hub()->bulk->getActions($list))->toBe([]);
})->with([ColumnService::LIST_USERS, ColumnService::LIST_USER_GROUPS, ColumnService::LIST_SECTIONS, ColumnService::LIST_VOLUMES]);

it('offers no labeling while labels are disabled', function () {
	$this->hub()->getSettings()->enableLabels = false;

	try {
		expect($this->hub()->bulk->getActions(ColumnService::LIST_SECTIONS))->not->toHaveKey(BulkService::ACTION_LABELS);
	} finally {
		$this->hub()->getSettings()->enableLabels = true;
	}
});

it('offers no deleting of structure, not even to admins', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);
	$bulk = $this->hub()->bulk;

	foreach ([ColumnService::LIST_SECTIONS, ColumnService::LIST_ENTRY_TYPES, ColumnService::LIST_FIELDS, ColumnService::LIST_VOLUMES, ColumnService::LIST_USER_GROUPS] as $list) {
		expect($bulk->getActions($list))->not->toHaveKey(BulkService::ACTION_DELETE);
	}
});

it('deletes the selected entries the user may delete', function () {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1])]);
	$elements = Craft::$app->getElements();
	$elements->shouldReceive('canDelete')->andReturnUsing(fn (Entry $entry) => $entry->id === 1);
	$elements->shouldReceive('deleteElement')->once()->andReturn(true);

	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'delete', 'ids' => ['1', '2', 'bogus']]);

	expect($response->data['count'])->toBe(1)
		->and($response->data['skipped'])->toBe(1)
		->and($response->data['message'])->toContain('applied to one item')
		->and($response->data['message'])->toContain('One item was skipped');
});

it('disables and enables the selected entries', function () {
	$entry = new Entry(['id' => 1, 'siteId' => 1, 'enabled' => true]);
	ElementQueries::respond(Entry::class, [$entry]);
	Craft::$app->getElements()->shouldReceive('saveElement')->twice()->andReturn(true);

	($this->apply)(['list' => 'entries', 'bulkAction' => 'disable', 'ids' => ['1']]);
	expect($entry->enabled)->toBeFalse();

	($this->apply)(['list' => 'entries', 'bulkAction' => 'enable', 'ids' => ['1']]);
	expect($entry->enabled)->toBeTrue()
		->and($entry->getEnabledForSite())->toBeTrue()
		->and($entry->getScenario())->toBe(Entry::SCENARIO_LIVE);
});

it('deletes the transforms of the selected files', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);
	$row = ['id' => '11', 'assetId' => '1', 'transformer' => 'other\\Transformer', 'filename' => 'a.jpg', 'format' => null, 'transformString' => '_100x100', 'fileExists' => '1', 'inProgress' => '0', 'error' => '0', 'dateIndexed' => null];
	$this->db()->respond('SELECT `id`, `assetId`', [$row]);

	$response = ($this->apply)(['list' => 'files', 'bulkAction' => 'deleteTransforms', 'ids' => ['1']]);

	expect($response->data['count'])->toBe(1)
		->and($this->db()->queriesMatching('DELETE FROM {{%imagetransformindex}}')[0])->toContain('`id`=11');
});

it('suspends the selected users, but not the current one', function () {
	ElementQueries::respond(User::class, [new User(['id' => 1, 'username' => 'admin']), new User(['id' => 2, 'username' => 'editor'])]);
	$users = Craft::$app->getUsers();
	$users->shouldReceive('canSuspend')->andReturn(true);
	$users->shouldReceive('suspendUser')->once()->withArgs(fn (User $user) => $user->id === 2);

	$response = ($this->apply)(['list' => 'users', 'bulkAction' => 'suspend', 'ids' => ['1', '2']]);

	expect($response->data['count'])->toBe(1)
		->and($response->data['skipped'])->toBe(1);
});

it('fails when the action applies to none of the selected items', function () {
	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'delete', 'ids' => []]);

	expect($response->getStatusCode())->toBe(400)
		->and($response->data['message'])->toContain('couldn’t be applied');
});

it('rejects actions the list does not offer', function () {
	($this->apply)(['list' => 'users', 'bulkAction' => 'delete', 'ids' => ['1']]);
})->throws(BadRequestHttpException::class);

it('rejects deleting structure, even for admins', function (string $list) {
	Craft::$app->getEntries()->shouldNotReceive('deleteSectionById', 'deleteEntryTypeById');
	Craft::$app->getFields()->shouldNotReceive('deleteFieldById');

	($this->apply)(['list' => $list, 'bulkAction' => 'delete', 'ids' => ['1']]);
})->with([ColumnService::LIST_SECTIONS, ColumnService::LIST_ENTRY_TYPES, ColumnService::LIST_FIELDS, ColumnService::LIST_VOLUMES, ColumnService::LIST_USER_GROUPS])
	->throws(BadRequestHttpException::class);

it('requires a post request to apply bulk actions', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);

	Craft::createObject(BulkController::class, ['bulk', $this->hub()])->runAction('apply');
})->throws(MethodNotAllowedHttpException::class);

it('unselects the items an action was applied to, keeping the skipped ones selected', function () {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1])]);
	$elements = Craft::$app->getElements();
	$elements->shouldReceive('canDelete')->andReturnUsing(fn (Entry $entry) => $entry->id === 1);
	$elements->shouldReceive('deleteElement')->andReturn(true);

	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'delete', 'ids' => ['1', '2']]);

	expect($this->session['hub:bulk:selection:entries:hub/test'])->toBe([2])
		->and($response->data['message'])->toContain('so it stays selected')
		->and($response->data)->not->toHaveKey('redirect');
});

it('goes back to the list once the action was applied to all selected items', function () {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 3, 'siteId' => 1]), new Entry(['id' => 4, 'siteId' => 1])]);
	Craft::$app->getElements()->shouldReceive('saveElement')->andReturn(true);

	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'disable', 'ids' => ['3', '4']]);

	expect($this->session)->not->toHaveKey('hub:bulk:selection:entries:hub/test')
		->and($response->data['redirect'])->toContain('hub/test');
});

it('applies an action to a single item in place of the selection, unselecting it', function () {
	$entry = new Entry(['id' => 2, 'siteId' => 1, 'enabled' => true]);
	ElementQueries::respond(Entry::class, fn ($query) => (array)$query->id === [2] ? [$entry] : []);
	Craft::$app->getElements()->shouldReceive('saveElement')->once()->andReturn(true);

	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'disable', 'item' => '2', 'ids' => ['1', '2']]);

	expect($entry->enabled)->toBeFalse()
		->and($response->data['count'])->toBe(1)
		->and($this->session['hub:bulk:selection:entries:hub/test'])->toBe([1])
		->and($response->data)->not->toHaveKey('redirect');
});

it('applies an action to a single item for users who may not bulk-edit the list', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Entries->viewPermission(), Hub::PERMISSION_ENABLE_ENTRIES]);
	$entry = new Entry(['id' => 2, 'siteId' => 1, 'enabled' => true]);
	ElementQueries::respond(Entry::class, fn ($query) => (array)$query->id === [2] ? [$entry] : []);
	Craft::$app->getElements()->shouldReceive('saveElement')->once()->andReturn(true);

	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'disable', 'item' => '2']);

	expect($entry->enabled)->toBeFalse()
		->and($response->data['count'])->toBe(1);
});

it('selects nothing for users who may not bulk-edit the list', function (string $action, array $body) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Entries->viewPermission(), Hub::PERMISSION_ENABLE_ENTRIES, Hub::PERMISSION_DELETE_ENTRIES]);
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	($this->post)($action, ['list' => 'entries', ...$body]);
})->with([
	'selecting' => ['select', ['add' => ['3']]],
	'applying to the selection' => ['apply', ['bulkAction' => 'disable']],
	'previewing the selection' => ['preview', ['bulkAction' => 'delete']],
])->throws(ForbiddenHttpException::class);

it('lets users who may bulk-edit the list select its items', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Entries->viewPermission(), Page::Entries->bulkEditPermission(), Hub::PERMISSION_ENABLE_ENTRIES]);

	expect(($this->post)('select', ['list' => 'entries', 'add' => ['3']])->data['ids'])->toBe([3])
		->and($this->hub()->bulk->canSelect(ColumnService::LIST_ENTRIES))->toBeTrue();

	// Bulk-editing alone offers nothing to apply to the selection.
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Entries->viewPermission(), Page::Entries->bulkEditPermission()]);

	expect($this->hub()->bulk->canSelect(ColumnService::LIST_ENTRIES))->toBeFalse();
});

it('offers the actions of the assets and users by their own permissions', function (array $permissions, string $list, array $actions) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Assets->viewPermission(), Page::Users->viewPermission(), ...$permissions]);
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);

	expect(array_keys($this->hub()->bulk->getActions($list)))->toBe($actions);
})->with([
	'downloading' => [[Hub::PERMISSION_DOWNLOAD_ASSETS], ColumnService::LIST_FILES, [BulkService::ACTION_DOWNLOAD]],
	'deleting transforms' => [[Hub::PERMISSION_DELETE_TRANSFORMS], ColumnService::LIST_FILES, [BulkService::ACTION_DELETE_TRANSFORMS]],
	'deleting assets' => [[Hub::PERMISSION_DELETE_ASSETS], ColumnService::LIST_FILES, [BulkService::ACTION_DELETE]],
	'suspending' => [[Hub::PERMISSION_SUSPEND_USERS], ColumnService::LIST_USERS, [BulkService::ACTION_SUSPEND, BulkService::ACTION_UNSUSPEND]],
	'none' => [[], ColumnService::LIST_FILES, []],
]);

it('adds and removes labels of the selected items, keeping them selected', function () {
	$this->db()->respond('FROM {{%hub_labels}}', [
		['id' => 1, 'name' => 'Keep', 'icon' => null, 'color' => null, 'sortOrder' => 1, 'uid' => 'label-keep'],
		['id' => 2, 'name' => 'Review', 'icon' => null, 'color' => null, 'sortOrder' => 2, 'uid' => 'label-review'],
	]);
	$this->db()->respond('FROM {{%hub_labeled}}', [['itemId' => 5, 'labelId' => 1]]);
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 5, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => 'Hello']), new Entry(['id' => 6, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => 'World'])]);
	$activity = $this->mockComponent('hub.activity', ActivityService::class);
	$activity->shouldReceive('beginBatch')->once()->with(ActivityAction::Bulk, ActivitySubject::Entry, 'Labels')->ordered();
	$activity->shouldReceive('record')->once()->with(ActivityAction::Labeled, ActivitySubject::Entry, 6, 'World', ['added' => ['Keep']])->ordered();
	$activity->shouldReceive('endBatch')->once()->ordered();

	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'labels', 'labelsAdd' => ['1'], 'labelsRemove' => ['2'], 'ids' => ['5', '6', '7']]);
	$inserts = $this->db()->queriesMatching('INSERT INTO {{%hub_labeled}}');

	expect($response->data['count'])->toBe(2)
		->and($response->data['skipped'])->toBe(1)
		->and($this->session['hub:bulk:selection:entries:hub/test'])->toBe([5, 6, 7])
		->and($response->data)->not->toHaveKey('redirect')
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_labeled}}')[0])->toContain('`labelId`=2')
		->and($inserts)->toHaveCount(1)
		->and($inserts[0])->toContain("(1, 'entries', 6")
		->and($inserts[0])->not->toContain("(1, 'entries', 5");
});

it('keeps the selection when downloading files', function () {
	ElementQueries::respond(Asset::class, []);

	($this->apply)(['list' => 'files', 'bulkAction' => 'download', 'ids' => ['1']]);

	expect($this->session['hub:bulk:selection:files:hub/test'])->toBe([1]);
});

it('selects and unselects items by their IDs', function () {
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	$response = ($this->post)('select', ['list' => 'entries', 'add' => ['3', '4', '2', 'x', '-1'], 'remove' => ['1']]);

	expect($response->data['ids'])->toBe([2, 3, 4])
		->and($this->session['hub:bulk:selection:entries:hub/test'])->toBe([2, 3, 4]);
});

it('clears the selection before selecting items', function () {
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	expect(($this->post)('select', ['list' => 'entries', 'clear' => 1, 'add' => ['5']])->data['ids'])->toBe([5]);

	($this->post)('select', ['list' => 'entries', 'clear' => 1]);

	expect($this->session)->not->toHaveKey('hub:bulk:selection:entries:hub/test');
});

it('selects and unselects all items matching the filters the list was shown with', function () {
	$this->createContentModel();
	$filter = new SectionFilter();
	$filter->types = ['channel'];
	$this->session['hub:bulk:filter:sections:hub/test'] = $filter;
	$this->session['hub:bulk:selection:sections:hub/test'] = [2];

	// Like the list, sorted by name: archive, then news.
	expect(($this->post)('select', ['list' => 'sections', 'matching' => 'add'])->data['ids'])->toBe([2, 4, 1])
		->and(($this->post)('select', ['list' => 'sections', 'matching' => 'remove'])->data['ids'])->toBe([2]);
});

it('selects no matching items without filters kept for the list', function () {
	$this->createContentModel();

	expect(($this->post)('select', ['list' => 'sections', 'matching' => 'add'])->data['ids'])->toBe([])
		->and(($this->post)('select', ['list' => 'entries', 'matching' => 'add'])->data['ids'])->toBe([]);
});

it('refuses selecting more items than can be selected at once', function () {
	$this->session['hub:bulk:selection:entries:hub/test'] = range(1, BulkService::SELECTION_LIMIT);

	$response = ($this->post)('select', ['list' => 'entries', 'add' => [BulkService::SELECTION_LIMIT + 1]]);

	expect($response->data['ids'])->toHaveCount(BulkService::SELECTION_LIMIT)
		->and($response->data['error'])->toContain('Up to 1,000 items')
		->and($this->session['hub:bulk:selection:entries:hub/test'])->toHaveCount(BulkService::SELECTION_LIMIT);
});

it('rejects selecting in lists without actions for the user', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_STRUCTURE, Page::Sections->viewPermission()]);
	$this->identity = new User(['id' => 2, 'username' => 'editor']);

	($this->post)('select', ['list' => 'sections', 'add' => ['1']]);
})->throws(BadRequestHttpException::class);

it('matches the items of each list against its filters', function () {
	$this->createContentModel();
	$this->request();
	$bulk = $this->hub()->bulk;
	$queried = [];
	ElementQueries::respond(Entry::class, function (ElementQuery $query) use (&$queried) {
		$queried[] = $query;

		return [new Entry(['id' => 7, 'siteId' => 1])];
	});

	expect($bulk->getMatchingIds(ColumnService::LIST_ENTRIES, new EntryFilter(), [7, 8], 5))->toBe([7])
		->and($queried[0]->id)->toBe([7, 8])
		->and($queried[0]->limit)->toBe(5)
		->and($bulk->getMatchingIds(ColumnService::LIST_ENTRIES, new EntryFilter(), []))->toBe([])
		->and($bulk->getMatchingIds(ColumnService::LIST_SECTIONS, new SectionFilter(), [1, 3, 9]))->toBe([1, 3])
		->and($bulk->getMatchingIds(ColumnService::LIST_SECTIONS, new SectionFilter(), limit: 2))->toBe([4, 1])
		->and($bulk->getMatchingIds(ColumnService::LIST_ENTRY_TYPES, new EntryTypeFilter()))->not->toBe([])
		->and($bulk->getMatchingIds(ColumnService::LIST_FIELDS, new FieldFilter()))->not->toBe([])
		->and($bulk->getMatchingIds(ColumnService::LIST_VOLUMES, new VolumeFilter()))->not->toBe([])
		->and($bulk->getMatchingIds(ColumnService::LIST_USER_GROUPS, new UserGroupFilter()))->toBeArray()
		// Filters of another list match nothing.
		->and($bulk->getMatchingIds(ColumnService::LIST_SECTIONS, new EntryFilter()))->toBe([]);
});

it('requires a post request asking for JSON to change the selection', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);

	Craft::createObject(BulkController::class, ['bulk', $this->hub()])->runAction('select');
})->throws(MethodNotAllowedHttpException::class);

it('previews deleting the selection, or a single item', function () {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => array_map(
		fn (int $id) => new Entry(['id' => $id, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => "Entry $id"]),
		(array)$query->id,
	));
	Craft::$app->getElements()->shouldReceive('canDelete')->andReturn(true);
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	// The preview links the items to their Hub pages, which keep the selected sites of the plugin.
	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	// The responses are the same object, so their data is read as they are sent.
	$selection = ($this->post)('preview', ['list' => 'entries'])->data;
	$single = ($this->post)('preview', ['list' => 'entries', 'item' => '2'])->data;

	expect($selection['total'])->toBe(2)
		->and($selection['html'])->toContain('Entry 1')
		->and($selection['html'])->toContain('moved to the trash')
		->and($single['total'])->toBe(1)
		->and($single['html'])->not->toContain('Entry 1');
});

it('previews deleting only for lists whose items are deleted', function () {
	($this->post)('preview', ['list' => 'users']);
})->throws(BadRequestHttpException::class);

it('previews downloading the files the user may view, and how they are sent', function () {
	$this->volume(1, 'images');
	ElementQueries::respond(Asset::class, fn (ElementQuery $query) => array_map(
		fn (int $id) => new Asset(['id' => $id, 'siteId' => 1, 'volumeId' => 1, 'kind' => 'image', 'filename' => "file-$id.jpg", 'title' => "File $id", 'size' => 1000 * 1000]),
		(array)$query->id,
	));
	Craft::$app->getElements()->shouldReceive('canView')->andReturnUsing(fn (Asset $asset) => $asset->id !== 3);
	$this->session['hub:bulk:selection:files:hub/test'] = [1, 2, 3];

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$selection = ($this->post)('preview', ['list' => 'files', 'bulkAction' => 'download'])->data;
	$single = ($this->post)('preview', ['list' => 'files', 'bulkAction' => 'download', 'item' => '2'])->data;

	expect($selection['total'])->toBe(2)
		->and($selection['html'])->toContain('File 1')
		->and($selection['html'])->toContain('Images/file-2.jpg')
		->and($selection['html'])->toContain('zip archive')
		->and($selection['html'])->toContain('1 skipped')
		->and($single['total'])->toBe(1)
		->and($single['html'])->toContain('downloaded as it is')
		->and($single['html'])->not->toContain('File 1');
});

it('previews the items by the page asked for, the totals covering all of them', function () {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => array_map(
		fn (int $id) => new Entry(['id' => $id, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => "Entry $id"]),
		(array)$query->id,
	));
	$this->volume(1, 'images');
	ElementQueries::respond(Asset::class, fn (ElementQuery $query) => array_map(
		fn (int $id) => new Asset(['id' => $id, 'siteId' => 1, 'volumeId' => 1, 'kind' => 'image', 'filename' => "file-$id.jpg", 'title' => "File $id", 'size' => 1000]),
		(array)$query->id,
	));
	Craft::$app->getElements()->shouldReceive('canDelete')->andReturn(true);
	Craft::$app->getElements()->shouldReceive('canView')->andReturn(true);
	$this->session['hub:bulk:selection:entries:hub/test'] = range(1, 52);
	$this->session['hub:bulk:selection:files:hub/test'] = range(1, 52);

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$first = ($this->post)('preview', ['list' => 'entries'])->data;
	$second = ($this->post)('preview', ['list' => 'entries', 'page' => '2'])->data;
	$files = ($this->post)('preview', ['list' => 'files', 'bulkAction' => 'download', 'page' => '2'])->data;

	expect($first['total'])->toBe(52)
		->and($first['html'])->toContain('Entry 50')
		->and($first['html'])->not->toContain('Entry 51')
		->and($first['html'])->toContain('data-hub-preview-pages="1"')
		->and($second['total'])->toBe(52)
		->and($second['html'])->toContain('Entry 52')
		->and($second['html'])->not->toContain('Entry 50<')
		->and($second['html'])->toContain('data-hub-preview-pages="2"')
		->and($files['total'])->toBe(52)
		->and($files['html'])->toContain('File 51')
		->and($files['html'])->not->toContain('File 1<')
		->and($files['html'])->toContain('data-hub-preview-pages="2"');
});

it('previews deleting the transforms of the selected files the user may save', function () {
	$this->volume(1, 'images');
	ElementQueries::respond(Asset::class, fn (ElementQuery $query) => array_map(
		fn (int $id) => new Asset(['id' => $id, 'siteId' => 1, 'volumeId' => 1, 'kind' => 'image', 'filename' => "file-$id.jpg", 'title' => "File $id"]),
		(array)$query->id,
	));
	Craft::$app->getElements()->shouldReceive('canSave')->andReturnUsing(fn (Asset $asset) => $asset->id !== 3);
	$transform = ['transformer' => 'other\\Transformer', 'filename' => 'a.jpg', 'format' => null, 'fileExists' => '1', 'inProgress' => '0', 'error' => '0', 'dateIndexed' => null];
	$this->db()->respond('SELECT `id`, `assetId`', [
		['id' => '11', 'assetId' => '1', 'transformString' => '_100x100', ...$transform],
		['id' => '12', 'assetId' => '1', 'transformString' => '_200x200', ...$transform],
		['id' => '13', 'assetId' => '3', 'transformString' => '_300x300', ...$transform],
	]);
	$this->session['hub:bulk:selection:files:hub/test'] = [1, 2, 3];

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$selection = ($this->post)('preview', ['list' => 'files', 'bulkAction' => 'deleteTransforms'])->data;

	// The second file has no transforms, and the third may not be saved.
	expect($selection['total'])->toBe(1)
		->and($selection['html'])->toContain('2 transforms')
		->and($selection['html'])->toContain('_200x200')
		->and($selection['html'])->not->toContain('_300x300')
		->and($selection['html'])->toContain('generated again')
		->and($selection['html'])->toContain('2 skipped');
});

it('previews only the actions previewed', function () {
	($this->post)('preview', ['list' => 'users', 'bulkAction' => 'suspend']);
})->throws(BadRequestHttpException::class);
