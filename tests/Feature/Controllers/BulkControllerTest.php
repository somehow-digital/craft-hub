<?php

use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\exporters\Expanded;
use craft\elements\exporters\Raw;
use craft\elements\User;
use craft\behaviors\DraftBehavior;
use craft\enums\CmsEdition;
use craft\events\RegisterElementExportersEvent;
use craft\imagetransforms\FallbackTransformer;
use craft\imagetransforms\ImageTransformer;
use craft\models\ImageTransform;
use craft\models\ImageTransformIndex;
use craft\models\Section;
use craft\models\VolumeFolder;
use craft\queue\jobs\GenerateImageTransform;
use craft\queue\Queue;
use craft\services\Assets;
use craft\services\ImageTransforms;
use SomehowDigital\Craft\Hub\controllers\BulkController;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\TrashedEntryFilter;
use SomehowDigital\Craft\Hub\models\TrashedFileFilter;
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
use yii\base\Event;
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
	Craft::$app->edition = CmsEdition::Pro;
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);
	$bulk = $this->hub()->bulk;

	expect(array_keys($bulk->getActions(ColumnService::LIST_ENTRIES)))->toBe([BulkService::ACTION_LABELS, BulkService::ACTION_EXPORT, BulkService::ACTION_ENABLE, BulkService::ACTION_DISABLE, BulkService::ACTION_CHANGE_AUTHOR, BulkService::ACTION_MOVE_TO_SECTION, BulkService::ACTION_DELETE_DRAFTS, BulkService::ACTION_DELETE])
		->and(array_keys($bulk->getActions(ColumnService::LIST_FILES)))->toBe([BulkService::ACTION_LABELS, BulkService::ACTION_EXPORT, BulkService::ACTION_DOWNLOAD, BulkService::ACTION_MOVE_TO_FOLDER, BulkService::ACTION_GENERATE_TRANSFORMS, BulkService::ACTION_DELETE_TRANSFORMS, BulkService::ACTION_DELETE])
		->and(array_keys($bulk->getActions(ColumnService::LIST_USERS)))->toBe([BulkService::ACTION_LABELS, BulkService::ACTION_EXPORT, BulkService::ACTION_GROUPS, BulkService::ACTION_SUSPEND, BulkService::ACTION_UNSUSPEND, BulkService::ACTION_DELETE])
		->and(array_keys($bulk->getActions(ColumnService::LIST_SECTIONS)))->toBe([BulkService::ACTION_LABELS])
		->and(array_keys($bulk->getActions(ColumnService::LIST_TRASHED_ENTRIES)))->toBe([BulkService::ACTION_EXPORT, BulkService::ACTION_RESTORE, BulkService::ACTION_RESTORE_WITH_RELATIONS, BulkService::ACTION_DELETE_PERMANENTLY])
		->and(array_keys($bulk->getActions(ColumnService::LIST_TRASHED_FILES)))->toBe([BulkService::ACTION_EXPORT, BulkService::ACTION_RESTORE, BulkService::ACTION_RESTORE_WITH_RELATIONS, BulkService::ACTION_DELETE_PERMANENTLY])
		->and($bulk->getActions(ColumnService::LIST_TRASHED_FILES)[BulkService::ACTION_RESTORE]['unless'])->toBe('unrestorable')
		->and($bulk->getActions(ColumnService::LIST_TRASHED_FILES)[BulkService::ACTION_RESTORE_WITH_RELATIONS]['unless'])->toBe('unrestorable')
		->and($bulk->getActions(ColumnService::LIST_ENTRIES)[BulkService::ACTION_MOVE_TO_SECTION])->toMatchArray(['preview' => true, 'fields' => true])
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
	'exporting' => [[Hub::PERMISSION_EXPORT_ENTRIES], [BulkService::ACTION_EXPORT]],
	'changing authors' => [[Hub::PERMISSION_CHANGE_AUTHORS], [BulkService::ACTION_CHANGE_AUTHOR]],
	'moving' => [[Hub::PERMISSION_MOVE_ENTRIES], [BulkService::ACTION_MOVE_TO_SECTION]],
	'deleting drafts' => [[Hub::PERMISSION_DELETE_DRAFTS], [BulkService::ACTION_DELETE_DRAFTS]],
	'exporting trashed entries' => [[Hub::PERMISSION_EXPORT_TRASHED_ENTRIES], []],
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

it('offers the actions of the trashed entries by their own permissions, to users who may view the trash', function (array $permissions, array $actions) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Entries->viewPermission(), ...$permissions]);
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 2, 'username' => 'editor']));

	expect(array_keys($this->hub()->bulk->getActions(ColumnService::LIST_TRASHED_ENTRIES)))->toBe($actions);
})->with([
	'restoring and purging' => [
		[Hub::PERMISSION_VIEW_TRASHED_ENTRIES, Hub::PERMISSION_RESTORE_ENTRIES, Hub::PERMISSION_PURGE_ENTRIES],
		[BulkService::ACTION_RESTORE, BulkService::ACTION_RESTORE_WITH_RELATIONS, BulkService::ACTION_DELETE_PERMANENTLY],
	],
	'restoring' => [[Hub::PERMISSION_VIEW_TRASHED_ENTRIES, Hub::PERMISSION_RESTORE_ENTRIES], [BulkService::ACTION_RESTORE, BulkService::ACTION_RESTORE_WITH_RELATIONS]],
	'purging' => [[Hub::PERMISSION_VIEW_TRASHED_ENTRIES, Hub::PERMISSION_PURGE_ENTRIES], [BulkService::ACTION_DELETE_PERMANENTLY]],
	'without viewing the trash' => [[Hub::PERMISSION_RESTORE_ENTRIES, Hub::PERMISSION_PURGE_ENTRIES], []],
	'deleting' => [[Hub::PERMISSION_VIEW_TRASHED_ENTRIES, Hub::PERMISSION_DELETE_ENTRIES], []],
]);

it('offers no actions of the trashed items while the trash is disabled', function () {
	$this->request();
	$this->hub()->getSettings()->enableTrash = false;

	expect($this->hub()->bulk->getActions(ColumnService::LIST_TRASHED_ENTRIES))->toBe([])
		->and($this->hub()->bulk->getActions(ColumnService::LIST_TRASHED_FILES))->toBe([]);
});

it('restores the selected trashed entries the user may save', function () {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1])]);
	$elements = Craft::$app->getElements();
	$elements->shouldReceive('canSave')->andReturnUsing(fn (Entry $entry) => $entry->id === 1);
	$elements->shouldReceive('restoreElement')->once()->withArgs(fn (Entry $entry) => $entry->id === 1)->andReturn(true);

	$response = ($this->apply)(['list' => 'trashedEntries', 'bulkAction' => 'restore', 'ids' => ['1', '2']]);

	expect($response->data['count'])->toBe(1)
		->and($response->data['skipped'])->toBe(1)
		->and(ElementQueries::executed(Entry::class)[0]->trashed)->toBeTrue();
});

it('deletes the selected trashed files permanently', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'filename' => 'a.jpg'])]);
	$elements = Craft::$app->getElements();
	$elements->shouldReceive('canDelete')->andReturn(true);
	$elements->shouldReceive('deleteElement')->once()->withArgs(fn (Asset $asset, bool $hardDelete) => $hardDelete)->andReturn(true);

	$response = ($this->apply)(['list' => 'trashedFiles', 'bulkAction' => 'deletePermanently', 'ids' => ['1']]);

	expect($response->data['count'])->toBe(1)
		->and(ElementQueries::executed(Asset::class)[0]->trashed)->toBeTrue();
});

it('matches the trashed items against their filters', function () {
	$this->request();
	ElementQueries::respond(Entry::class, [new Entry(['id' => 7, 'siteId' => 1])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 8, 'siteId' => 1])]);
	$bulk = $this->hub()->bulk;

	expect($bulk->getMatchingIds(ColumnService::LIST_TRASHED_ENTRIES, new TrashedEntryFilter()))->toBe([7])
		->and($bulk->getMatchingIds(ColumnService::LIST_TRASHED_FILES, new TrashedFileFilter()))->toBe([8])
		->and(ElementQueries::executed(Entry::class)[0]->trashed)->toBeTrue()
		->and($bulk->getMatchingIds(ColumnService::LIST_TRASHED_ENTRIES, new EntryFilter()))->toBe([]);
});

it('fails when the action applies to none of the selected items', function () {
	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'delete', 'ids' => []]);

	expect($response->getStatusCode())->toBe(400)
		->and($response->data['message'])->toContain('couldn’t be applied');
});

it('rejects actions the list does not offer', function () {
	($this->apply)(['list' => 'users', 'bulkAction' => 'enable', 'ids' => ['1']]);
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
	'exporting assets' => [[Hub::PERMISSION_EXPORT_ASSETS], ColumnService::LIST_FILES, [BulkService::ACTION_EXPORT]],
	'exporting users' => [[Hub::PERMISSION_EXPORT_USERS], ColumnService::LIST_USERS, [BulkService::ACTION_EXPORT]],
	'exporting trashed assets' => [[Hub::PERMISSION_VIEW_TRASHED_ASSETS, Hub::PERMISSION_EXPORT_TRASHED_ASSETS], ColumnService::LIST_TRASHED_FILES, [BulkService::ACTION_EXPORT]],
	'exporting assets, but not trashed ones' => [[Hub::PERMISSION_VIEW_TRASHED_ASSETS, Hub::PERMISSION_EXPORT_ASSETS], ColumnService::LIST_TRASHED_FILES, []],
	'deleting transforms' => [[Hub::PERMISSION_DELETE_TRANSFORMS], ColumnService::LIST_FILES, [BulkService::ACTION_DELETE_TRANSFORMS]],
	'deleting assets' => [[Hub::PERMISSION_DELETE_ASSETS], ColumnService::LIST_FILES, [BulkService::ACTION_DELETE]],
	'suspending' => [[Hub::PERMISSION_SUSPEND_USERS], ColumnService::LIST_USERS, [BulkService::ACTION_SUSPEND, BulkService::ACTION_UNSUSPEND]],
	'moving assets' => [[Hub::PERMISSION_MOVE_ASSETS], ColumnService::LIST_FILES, [BulkService::ACTION_MOVE_TO_FOLDER]],
	'generating transforms' => [[Hub::PERMISSION_GENERATE_TRANSFORMS], ColumnService::LIST_FILES, [BulkService::ACTION_GENERATE_TRANSFORMS]],
	'changing groups, but not in Craft Solo' => [[Hub::PERMISSION_ASSIGN_USER_GROUPS], ColumnService::LIST_USERS, []],
	'deleting users' => [[Hub::PERMISSION_DELETE_USERS], ColumnService::LIST_USERS, [BulkService::ACTION_DELETE]],
	'restoring trashed assets' => [
		[Hub::PERMISSION_VIEW_TRASHED_ASSETS, Hub::PERMISSION_RESTORE_ASSETS, Hub::PERMISSION_PURGE_ASSETS],
		ColumnService::LIST_TRASHED_FILES,
		[BulkService::ACTION_RESTORE, BulkService::ACTION_RESTORE_WITH_RELATIONS, BulkService::ACTION_DELETE_PERMANENTLY],
	],
	'trashed assets without restoring them' => [[Hub::PERMISSION_VIEW_TRASHED_ASSETS, Hub::PERMISSION_DELETE_ASSETS], ColumnService::LIST_TRASHED_FILES, []],
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
	($this->post)('preview', ['list' => 'sections']);
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

it('offers Craft’s exporters of the element type of the list, along with those registered by plugins', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn($this->identity);
	$bulk = $this->hub()->bulk;

	expect(array_keys($bulk->getExporters(ColumnService::LIST_ENTRIES)))->toBe([Raw::class, Expanded::class])
		->and(array_keys($bulk->getExporters(ColumnService::LIST_TRASHED_FILES)))->toBe([Raw::class, Expanded::class])
		->and($bulk->getExporters(ColumnService::LIST_SECTIONS))->toBe([])
		->and(array_keys($bulk->getExportFormats()))->toBe(['csv', 'xlsx', 'json', 'xml', 'yaml']);

	$handler = function (RegisterElementExportersEvent $event) {
		$event->exporters = [Expanded::class];
	};
	Event::on(User::class, User::EVENT_REGISTER_EXPORTERS, $handler);

	try {
		expect(array_keys($bulk->getExporters(ColumnService::LIST_USERS)))->toBe([Expanded::class]);
	} finally {
		Event::off(User::class, User::EVENT_REGISTER_EXPORTERS, $handler);
	}
});

it('previews exporting the items the user may view, offering the exporters and formats', function () {
	ElementQueries::respond(Asset::class, fn (ElementQuery $query) => array_map(
		fn (int $id) => new Asset(['id' => $id, 'siteId' => 1, 'filename' => "file-$id.jpg"]),
		(array)$query->id,
	));
	Craft::$app->getElements()->shouldReceive('canView')->andReturnUsing(fn (Asset $asset) => $asset->id !== 3);
	$this->session['hub:bulk:selection:trashedFiles:hub/test'] = [1, 2, 3];

	$selection = ($this->post)('preview', ['list' => 'trashedFiles', 'bulkAction' => 'export'])->data;

	expect($selection['total'])->toBe(2)
		->and($selection['html'])->toContain('2 items')
		->and($selection['html'])->toContain('1 skipped')
		->and($selection['html'])->toContain('Raw data (fastest)')
		->and($selection['html'])->toContain('value="' . htmlspecialchars(Expanded::class) . '"')
		->and($selection['html'])->toContain('XLSX')
		->and(ElementQueries::executed(Asset::class)[0]->trashed)->toBeTrue();
});

it('exports the selected items the user may view by the exporter and format picked, keeping them selected', function () {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => array_map(
		fn (int $id) => $query->asArray ? ['id' => $id, 'title' => "Entry $id"] : new Entry(['id' => $id, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => "Entry $id"]),
		(array)$query->id,
	));
	Craft::$app->getElements()->shouldReceive('canView')->andReturnUsing(fn (Entry $entry) => $entry->id !== 3);
	$activity = $this->mockComponent('hub.activity', ActivityService::class);
	$activity->shouldReceive('beginBatch')->once()->with(ActivityAction::Bulk, ActivitySubject::Entry, 'Export')->ordered();
	$activity->shouldReceive('record')->twice()->withArgs(fn (ActivityAction $action, ActivitySubject $subject, int $id) => $action === ActivityAction::Exported && $subject === ActivitySubject::Entry && in_array($id, [1, 2], true))->ordered();
	$activity->shouldReceive('endBatch')->once()->ordered();

	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'export', 'exporter' => Raw::class, 'format' => 'json', 'ids' => ['2', '1', '3']]);
	$queries = ElementQueries::executed(Entry::class);

	expect($response->format)->toBe('json')
		->and($response->data)->toBe([['id' => 2, 'title' => 'Entry 2'], ['id' => 1, 'title' => 'Entry 1']])
		->and($response->getHeaders()->get('Content-Disposition'))->toContain('entries.json')
		->and(end($queries)->id)->toBe([2, 1])
		->and(end($queries)->fixedOrder)->toBeTrue()
		->and($this->session['hub:bulk:selection:entries:hub/test'])->toBe([2, 1, 3]);
});

it('exports by the first exporter as CSV by default', function () {
	ElementQueries::respond(User::class, fn (ElementQuery $query) => array_map(
		fn (int $id) => $query->asArray ? ['id' => $id] : new User(['id' => $id, 'username' => "user$id"]),
		(array)$query->id,
	));
	Craft::$app->getElements()->shouldReceive('canView')->andReturn(true);

	$response = ($this->apply)(['list' => 'users', 'bulkAction' => 'export', 'ids' => ['4']]);

	expect($response->format)->toBe('csv')
		->and($response->data)->toBe([['id' => 4]])
		->and($response->getHeaders()->get('Content-Disposition'))->toContain('users.csv')
		->and(ElementQueries::executed(User::class)[0]->siteId)->toBeNull();
});

it('fails exporting when the user may view none of the selected items', function () {
	ElementQueries::respond(Entry::class, []);

	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'export', 'ids' => ['1']]);

	expect($response->data['message'] ?? null)->toContain('couldn’t be applied');
});

it('rejects exporting by exporters or in formats Craft does not offer', function (array $body) {
	($this->apply)(['list' => 'entries', 'bulkAction' => 'export', 'ids' => ['1'], ...$body]);
})->with([
	'exporter' => [['exporter' => 'bogus']],
	'format' => [['exporter' => Raw::class, 'format' => 'html']],
])->throws(BadRequestHttpException::class);

it('replaces the authors of the selected entries with the author picked, or adds it to them', function () {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')])->maxAuthors = 2;
	$first = new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'authorIds' => [2]]);
	$second = new Entry(['id' => 2, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'authorIds' => [2]]);
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => array_values(array_filter([$first, $second], fn (Entry $entry) => in_array($entry->id, (array)$query->id, true))));
	ElementQueries::respond(User::class, fn (ElementQuery $query) => (array)$query->id === [7] ? [new User(['id' => 7, 'username' => 'author', 'admin' => true])] : []);
	Craft::$app->getElements()->shouldReceive('saveElement')->twice()->andReturn(true);

	$replaced = ($this->apply)(['list' => 'entries', 'bulkAction' => 'changeAuthor', 'ids' => ['1'], 'authorId' => '7', 'authors' => 'replace']);
	$added = ($this->apply)(['list' => 'entries', 'bulkAction' => 'changeAuthor', 'ids' => ['2'], 'authorId' => '7', 'authors' => 'add']);

	expect($replaced->data['count'])->toBe(1)
		->and($first->getAuthorIds())->toBe([7])
		->and($added->data['count'])->toBe(1)
		->and($second->getAuthorIds())->toBe([2, 7]);
});

it('changes no authors without an author picked, or of entries the author may not author', function () {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	$this->section(2, 'home', Section::TYPE_SINGLE, entryTypes: [$this->entryType(11, 'home')]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'authorIds' => [7]]), new Entry(['id' => 2, 'siteId' => 1, 'sectionId' => 2, 'typeId' => 11])]);
	ElementQueries::respond(User::class, [new User(['id' => 7, 'username' => 'author', 'admin' => true])]);
	Craft::$app->getElements()->shouldNotReceive('saveElement');

	$none = ($this->apply)(['list' => 'entries', 'bulkAction' => 'changeAuthor', 'ids' => ['1']]);
	// The first one has the author already, the single has no authors.
	$same = ($this->apply)(['list' => 'entries', 'bulkAction' => 'changeAuthor', 'ids' => ['1', '2'], 'authorId' => '7']);

	expect($none->getStatusCode())->toBe(400)
		->and($same->data['count'])->toBe(1)
		->and($same->data['skipped'])->toBe(1);
});

it('previews changing authors, offering those who may author the entries', function () {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'authorIds' => [7]])]);
	ElementQueries::respond(User::class, [new User(['id' => 8, 'username' => 'zoe', 'fullName' => 'Zoe']), new User(['id' => 7, 'username' => 'ann', 'fullName' => 'Ann'])]);
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	$preview = ($this->post)('preview', ['list' => 'entries', 'bulkAction' => 'changeAuthor'])->data;

	expect($preview['total'])->toBe(1)
		->and($preview['html'])->toContain('name="authorId"')
		->and(strpos($preview['html'], 'Ann (ann)'))->toBeLessThan(strpos($preview['html'], 'Zoe (zoe)'))
		->and($preview['html'])->toContain('value="add"')
		->and($preview['html'])->toContain('1 skipped');
});

it('moves the selected entries to the section picked, as far as it has their entry types', function () {
	$article = $this->entryType(10, 'article');
	$page = $this->entryType(11, 'page');
	$this->section(1, 'news', entryTypes: [$article, $page]);
	$this->section(2, 'blog', entryTypes: [$article]);
	ElementQueries::respond(Entry::class, [
		new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10]),
		new Entry(['id' => 2, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 11]),
		new Entry(['id' => 3, 'siteId' => 1, 'sectionId' => 2, 'typeId' => 10]),
	]);
	Craft::$app->getEntries()->shouldReceive('moveEntryToSection')->once()
		->withArgs(fn (Entry $entry, $section) => $entry->id === 1 && $section->id === 2)
		->andReturn(true);

	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'moveToSection', 'ids' => ['1', '2', '3'], 'sectionId' => '2']);

	expect($response->data['count'])->toBe(1)
		->and($response->data['skipped'])->toBe(2);
});

it('skips entries Craft fails to move, and moves none without a section picked', function () {
	$article = $this->entryType(10, 'article');
	$this->section(1, 'news', entryTypes: [$article]);
	$this->section(2, 'blog', entryTypes: [$article]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10])]);
	Craft::$app->getEntries()->shouldReceive('moveEntryToSection')->once()->andThrow(new yii\base\Exception('Invalid'));

	$failed = ($this->apply)(['list' => 'entries', 'bulkAction' => 'moveToSection', 'ids' => ['1'], 'sectionId' => '2']);
	$none = ($this->apply)(['list' => 'entries', 'bulkAction' => 'moveToSection', 'ids' => ['1'], 'sectionId' => '99']);

	expect($failed->getStatusCode())->toBe(400)
		->and($none->getStatusCode())->toBe(400);
});

it('previews moving entries, offering the sections taking them', function () {
	$article = $this->entryType(10, 'article');
	$page = $this->entryType(11, 'page');
	$this->section(1, 'news', entryTypes: [$article, $page], name: 'News');
	$this->section(2, 'blog', entryTypes: [$article], name: 'Blog');
	$this->section(3, 'pages', entryTypes: [$article, $page], name: 'Pages');
	$this->section(4, 'home', Section::TYPE_SINGLE, entryTypes: [$article], name: 'Home');
	ElementQueries::respond(Entry::class, [
		new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10]),
		new Entry(['id' => 2, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 11]),
	]);
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	$preview = ($this->post)('preview', ['list' => 'entries', 'bulkAction' => 'moveToSection'])->data;

	expect($preview['total'])->toBe(2)
		->and($preview['html'])->toContain('Blog (1 of 2)')
		->and($preview['html'])->toContain('>Pages<')
		->and($preview['html'])->not->toContain('Home')
		->and($preview['html'])->not->toContain('>News<');
});

it('deletes the drafts of the selected entries the user may delete', function () {
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => $query->drafts
		? [new Entry(['id' => 11, 'siteId' => 1, 'canonicalId' => 1, 'draftId' => 1]), new Entry(['id' => 12, 'siteId' => 1, 'canonicalId' => 1, 'draftId' => 2]), new Entry(['id' => 13, 'siteId' => 1, 'canonicalId' => 2, 'draftId' => 3])]
		: [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1]), new Entry(['id' => 3, 'siteId' => 1])]);
	$elements = Craft::$app->getElements();
	$elements->shouldReceive('canDelete')->andReturnUsing(fn (Entry $draft) => $draft->id !== 13);
	$elements->shouldReceive('deleteElement')->twice()->withArgs(fn (Entry $draft, bool $hardDelete) => $draft->getCanonicalId() === 1 && $hardDelete)->andReturn(true);

	$response = ($this->apply)(['list' => 'entries', 'bulkAction' => 'deleteDrafts', 'ids' => ['1', '2', '3']]);

	expect($response->data['count'])->toBe(1)
		->and($response->data['skipped'])->toBe(2)
		->and(array_values(array_filter(ElementQueries::executed(Entry::class), fn (ElementQuery $query) => $query->drafts))[0]->draftOf)->toBe([1, 2, 3]);
});

it('previews deleting the drafts of entries, listing them by entry', function () {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => $query->drafts
		? [(new Entry(['id' => 11, 'canonicalId' => 1, 'draftId' => 1, 'title' => 'Draft one', 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10]))->attachBehavior('draft', new DraftBehavior(['draftName' => 'First draft']))->owner]
		: [new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => 'Entry 1']), new Entry(['id' => 2, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => 'Entry 2'])]);
	Craft::$app->getElements()->shouldReceive('canDelete')->andReturn(true);
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$preview = ($this->post)('preview', ['list' => 'entries', 'bulkAction' => 'deleteDrafts'])->data;

	expect($preview['total'])->toBe(1)
		->and($preview['html'])->toContain('Entry 1')
		->and($preview['html'])->not->toContain('Entry 2')
		->and($preview['html'])->toContain('Draft one')
		->and($preview['html'])->toContain('First draft')
		->and($preview['html'])->toContain('deleted permanently')
		->and($preview['html'])->toContain('1 skipped');
});

it('moves the selected files to the folder picked, as far as the user may move them', function () {
	$this->volume(1, 'images');
	$this->volume(2, 'documents');
	ElementQueries::respond(Asset::class, [
		new Asset(['id' => 1, 'siteId' => 1, 'volumeId' => 1, 'folderId' => 10, 'filename' => 'a.jpg']),
		new Asset(['id' => 2, 'siteId' => 1, 'volumeId' => 1, 'folderId' => 10, 'filename' => 'b.jpg']),
		new Asset(['id' => 3, 'siteId' => 1, 'volumeId' => 2, 'folderId' => 20, 'filename' => 'c.pdf']),
	]);
	$target = new VolumeFolder(['id' => 20, 'volumeId' => 2, 'path' => '']);
	$assets = $this->mockComponent('assets', Assets::class);
	$assets->shouldReceive('findFolders')->andReturnUsing(fn (array $criteria) => $criteria['volumeId'] === 2 ? [$target] : [new VolumeFolder(['id' => 10, 'volumeId' => 1, 'path' => ''])]);
	$assets->shouldReceive('moveAsset')->once()->withArgs(fn (Asset $asset, VolumeFolder $folder) => $asset->id === 1 && $folder === $target)->andReturn(true);
	$elements = Craft::$app->getElements();
	$elements->shouldReceive('canDelete')->andReturnUsing(fn (Asset $asset) => $asset->id !== 2);

	// The second may not be deleted from its volume, the third is in the folder already.
	$response = ($this->apply)(['list' => 'files', 'bulkAction' => 'moveToFolder', 'ids' => ['1', '2', '3'], 'folderId' => '20'])->data;
	$unknown = ($this->apply)(['list' => 'files', 'bulkAction' => 'moveToFolder', 'ids' => ['1'], 'folderId' => '99'])->getStatusCode();

	expect($response['count'])->toBe(1)
		->and($response['skipped'])->toBe(2)
		->and($unknown)->toBe(400);
});

it('previews moving files, offering the folders of the volumes the user may save files in', function () {
	$this->volume(1, 'images', name: 'Images');
	$this->volume(2, 'documents', name: 'Documents');
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'volumeId' => 1, 'folderId' => 10, 'filename' => 'a.jpg'])]);
	$assets = $this->mockComponent('assets', Assets::class);
	$assets->shouldReceive('findFolders')->andReturnUsing(fn (array $criteria) => $criteria['volumeId'] === 1
		? [new VolumeFolder(['id' => 10, 'volumeId' => 1, 'path' => '']), new VolumeFolder(['id' => 11, 'volumeId' => 1, 'path' => 'photos/'])]
		: [new VolumeFolder(['id' => 20, 'volumeId' => 2, 'path' => ''])]);
	Craft::$app->getElements()->shouldReceive('canDelete')->andReturn(true);
	$this->session['hub:bulk:selection:files:hub/test'] = [1];

	$preview = ($this->post)('preview', ['list' => 'files', 'bulkAction' => 'moveToFolder'])->data;

	expect($preview['total'])->toBe(1)
		->and($preview['html'])->toContain('<optgroup label="Images">')
		->and($preview['html'])->toContain('Images/photos')
		->and($preview['html'])->toContain('<optgroup label="Documents">');
});

it('queues generating the transforms picked of the selected images not generated yet', function () {
	ElementQueries::respond(Asset::class, [
		new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg']),
		new Asset(['id' => 2, 'siteId' => 1, 'kind' => 'image', 'filename' => 'b.jpg']),
	]);
	Craft::$app->getElements()->shouldReceive('canView')->andReturn(true);
	$thumb = new ImageTransform(['handle' => 'thumb', 'name' => 'Thumb', 'width' => 100]);
	$hero = new ImageTransform(['handle' => 'hero', 'name' => 'Hero', 'width' => 1000]);
	$transformer = Mockery::mock(ImageTransformer::class);
	$transformer->shouldReceive('getTransformIndex')->andReturnUsing(fn (Asset $asset, ImageTransform $transform) => new ImageTransformIndex([
		'id' => $asset->id * 10,
		'assetId' => $asset->id,
		'transformString' => '_' . $transform->handle,
		'fileExists' => $asset->id === 2,
	]));
	$transforms = Mockery::mock(ImageTransforms::class)->makePartial();
	$transforms->shouldReceive('getAllTransforms')->andReturn([$thumb, $hero]);
	$transforms->shouldReceive('getImageTransformer')->with(ImageTransformer::class)->andReturn($transformer);
	Craft::$app->set('imageTransforms', $transforms);
	$queue = $this->mockComponent('queue', Queue::class)->makePartial();
	$queue->shouldReceive('push')->once()->with(Mockery::on(fn (GenerateImageTransform $job) => $job->transformId === 10));

	// The second file has the transform already.
	$response = ($this->apply)(['list' => 'files', 'bulkAction' => 'generateTransforms', 'ids' => ['1', '2'], 'transforms' => ['thumb', 'bogus']])->data;
	$none = ($this->apply)(['list' => 'files', 'bulkAction' => 'generateTransforms', 'ids' => ['1'], 'transforms' => ['bogus']])->getStatusCode();

	expect($response['count'])->toBe(1)
		->and($response['skipped'])->toBe(1)
		->and($none)->toBe(400);
});

it('previews generating transforms, offering the transforms of Craft’s transformer for the images', function () {
	ElementQueries::respond(Asset::class, [
		new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg']),
		new Asset(['id' => 2, 'siteId' => 1, 'kind' => 'pdf', 'filename' => 'b.pdf']),
	]);
	Craft::$app->getElements()->shouldReceive('canView')->andReturn(true);
	$transforms = Mockery::mock(ImageTransforms::class)->makePartial();
	$transforms->shouldReceive('getAllTransforms')->andReturn([
		new ImageTransform(['handle' => 'thumb', 'name' => 'Thumb']),
		new ImageTransform(['handle' => 'service', 'name' => 'Service', 'transformer' => FallbackTransformer::class]),
	]);
	Craft::$app->set('imageTransforms', $transforms);
	$this->session['hub:bulk:selection:files:hub/test'] = [1, 2];

	$preview = ($this->post)('preview', ['list' => 'files', 'bulkAction' => 'generateTransforms'])->data;

	expect($preview['total'])->toBe(1)
		->and($preview['html'])->toContain('value="thumb" checked')
		->and($preview['html'])->not->toContain('Service')
		->and($preview['html'])->toContain('1 skipped');
});

it('adds the selected users to the groups picked and removes them from others, but not the current one', function () {
	Craft::$app->edition = CmsEdition::Pro;
	$this->userGroup(1, 'editors');
	$this->userGroup(2, 'authors');
	$this->userGroup(3, 'guests');
	ElementQueries::respond(User::class, [new User(['id' => 1, 'username' => 'admin']), new User(['id' => 2, 'username' => 'editor']), new User(['id' => 3, 'username' => 'author'])]);
	Craft::$app->getUserGroups()->shouldReceive('getGroupsByUserId')->andReturnUsing(fn (int $id) => $id === 3 ? [$this->userGroups[1]] : [$this->userGroups[2]]);
	$users = Craft::$app->getUsers();
	$users->shouldReceive('assignUserToGroups')->once()->with(2, [1])->andReturn(true);

	// The third one is in the first group already, and not in the second.
	$response = ($this->apply)(['list' => 'users', 'bulkAction' => 'groups', 'ids' => ['1', '2', '3'], 'groups' => ['1' => 'add', '2' => 'remove', '3' => '', '99' => 'add']])->data;
	$none = ($this->apply)(['list' => 'users', 'bulkAction' => 'groups', 'ids' => ['2'], 'groups' => ['3' => '']])->getStatusCode();

	expect($response['count'])->toBe(2)
		->and($response['skipped'])->toBe(1)
		->and($none)->toBe(400);
});

it('previews changing groups, naming how many of the users are in each', function () {
	Craft::$app->edition = CmsEdition::Pro;
	$this->userGroup(1, 'editors')->name = 'Editors';
	$this->userGroup(2, 'authors')->name = 'Authors';
	ElementQueries::respond(User::class, [new User(['id' => 2, 'username' => 'editor']), new User(['id' => 3, 'username' => 'author'])]);
	$this->db()->respond('FROM {{%usergroups_users}}', [['groupId' => 1, 'count' => 2], ['groupId' => 2, 'count' => 1]]);
	$this->session['hub:bulk:selection:users:hub/test'] = [1, 2, 3];

	$preview = ($this->post)('preview', ['list' => 'users', 'bulkAction' => 'groups'])->data;

	expect($preview['total'])->toBe(2)
		->and($preview['html'])->toContain('name="groups[1]"')
		->and($preview['html'])->toContain('All of them are in it.')
		->and($preview['html'])->toContain('1 of them is in it.')
		->and($preview['html'])->toContain('1 skipped');
});

it('deletes the selected users, transferring their content to the user picked', function () {
	ElementQueries::respond(User::class, fn (ElementQuery $query) => array_map(
		fn (int $id) => new User(['id' => $id, 'username' => "user-$id"]),
		array_values(array_filter((array)$query->id, 'is_int')),
	));
	$elements = Craft::$app->getElements();
	$elements->shouldReceive('canDelete')->andReturn(true);
	$deleted = [];
	$elements->shouldReceive('deleteElement')->andReturnUsing(function (User $user) use (&$deleted) {
		$deleted[$user->id] = $user->inheritorOnDelete?->id;

		return true;
	});

	// The current one isn't deleted, nor is the one inheriting the content.
	$response = ($this->apply)(['list' => 'users', 'bulkAction' => 'delete', 'ids' => ['1', '2', '3', '9'], 'inheritorId' => '9']);

	expect($response->data['count'])->toBe(2)
		->and($response->data['skipped'])->toBe(2)
		->and($deleted)->toBe([2 => 9, 3 => 9]);
});

it('deletes no users when the user picked to inherit their content does not exist', function () {
	ElementQueries::respond(User::class, fn (ElementQuery $query) => (array)$query->id === [9] ? [] : [new User(['id' => 2, 'username' => 'editor'])]);
	Craft::$app->getElements()->shouldNotReceive('deleteElement');

	$response = ($this->apply)(['list' => 'users', 'bulkAction' => 'delete', 'ids' => ['2'], 'inheritorId' => '9']);

	expect($response->getStatusCode())->toBe(400);
});

it('previews deleting users, with the entries they are an author of and who may inherit them', function () {
	ElementQueries::respond(User::class, fn (ElementQuery $query) => in_array(User::STATUS_ACTIVE, (array)$query->status, true)
		? [new User(['id' => 5, 'username' => 'heir', 'fullName' => 'Heir'])]
		: [new User(['id' => 1, 'username' => 'admin']), new User(['id' => 2, 'username' => 'editor', 'email' => 'editor@example.com'])]);
	Craft::$app->getElements()->shouldReceive('canDelete')->andReturn(true);
	$this->db()->respond('[[hubAuthors.authorId]]', [['authorId' => 2, 'count' => 3]]);
	$this->session['hub:bulk:selection:users:hub/test'] = [1, 2];

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$preview = ($this->post)('preview', ['list' => 'users', 'bulkAction' => 'delete'])->data;

	expect($preview['total'])->toBe(1)
		->and($preview['html'])->toContain('editor@example.com')
		->and($preview['html'])->toContain('<strong>3</strong>')
		->and($preview['html'])->toContain('Entries authored')
		->and($preview['html'])->toContain('>editor<')
		->and($preview['html'])->toContain('name="inheritorId"')
		->and($preview['html'])->toContain('Heir (heir)')
		->and($preview['html'])->toContain('1 skipped');
});

it('restores the selected trashed items along with the trashed elements related to them', function () {
	$this->db()->respond('FROM {{%elements_owners}}', fn (string $sql) => str_contains($sql, 'WHERE `ownerId`') ? [['elementId' => 4, 'ownerId' => 1]] : []);
	$this->db()->respond('FROM {{%relations}}', [['sourceId' => 4, 'targetId' => 20], ['sourceId' => 30, 'targetId' => 2], ['sourceId' => 1, 'targetId' => 2]]);
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => in_array(30, (array)$query->id, true)
		? [new Entry(['id' => 30, 'siteId' => 1])]
		: [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 20, 'siteId' => 1, 'filename' => 'a.jpg', 'keptFile' => true])]);
	$restored = [];
	Craft::$app->getElements()->shouldReceive('restoreElement')->andReturnUsing(function ($element) use (&$restored) {
		$restored[] = $element->id;

		return true;
	});

	$response = ($this->apply)(['list' => 'trashedEntries', 'bulkAction' => 'restoreWithRelations', 'ids' => ['1', '2']]);

	// The file is related through a nested entry of the first, the entry relates to the second.
	expect($response->data['count'])->toBe(2)
		->and($restored)->toBe([1, 20, 2, 30]);
});

it('previews restoring trashed items with their relations, listing what is restored along with each', function () {
	$this->db()->respond('FROM {{%relations}}', [['sourceId' => 1, 'targetId' => 20]]);
	ElementQueries::respond(Asset::class, fn (ElementQuery $query) => in_array(20, (array)$query->id, true)
		? [new Asset(['id' => 20, 'siteId' => 1, 'filename' => 'related.jpg', 'title' => 'Linked image', 'keptFile' => true])]
		: []);
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => in_array(1, (array)$query->id, true) ? [new Entry(['id' => 1, 'siteId' => 1, 'title' => 'Trashed entry'])] : []);
	Craft::$app->getElements()->shouldReceive('canSave')->andReturn(true);
	$this->session['hub:bulk:selection:trashedEntries:hub/test'] = [1];

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$preview = ($this->post)('preview', ['list' => 'trashedEntries', 'bulkAction' => 'restoreWithRelations'])->data;

	expect($preview['total'])->toBe(1)
		->and($preview['html'])->toContain('Trashed entry')
		->and($preview['html'])->toContain('Linked image')
		->and($preview['html'])->toContain('Related file<');
});

it('previews the actions picking what they change the items to as applying to none without anything to pick', function (string $list, string $action) {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);
	ElementQueries::respond(User::class, [new User(['id' => 2, 'username' => 'editor'])]);
	$assets = $this->mockComponent('assets', Assets::class);
	$assets->shouldReceive('findFolders')->andReturn([]);
	$transforms = Mockery::mock(ImageTransforms::class)->makePartial();
	$transforms->shouldReceive('getAllTransforms')->andReturn([]);
	Craft::$app->set('imageTransforms', $transforms);
	Craft::$app->getElements()->shouldReceive('canDelete')->andReturn(true);
	Craft::$app->getElements()->shouldReceive('canView')->andReturn(true);
	Craft::$app->edition = CmsEdition::Pro;
	$this->session["hub:bulk:selection:$list:hub/test"] = [1, 2];

	$preview = ($this->post)('preview', ['list' => $list, 'bulkAction' => $action])->data;

	expect($preview['total'])->toBe(0)
		->and($preview['html'])->toContain('Nothing to');
})->with([
	'authors' => [ColumnService::LIST_ENTRIES, BulkService::ACTION_CHANGE_AUTHOR],
	'sections' => [ColumnService::LIST_ENTRIES, BulkService::ACTION_MOVE_TO_SECTION],
	'folders' => [ColumnService::LIST_FILES, BulkService::ACTION_MOVE_TO_FOLDER],
	'transforms' => [ColumnService::LIST_FILES, BulkService::ACTION_GENERATE_TRANSFORMS],
	'groups' => [ColumnService::LIST_USERS, BulkService::ACTION_GROUPS],
]);
