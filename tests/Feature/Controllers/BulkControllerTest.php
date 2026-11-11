<?php

declare(strict_types=1);

use craft\base\ElementInterface;
use craft\behaviors\DraftBehavior;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\exporters\Expanded;
use craft\elements\exporters\Raw;
use craft\elements\User;
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
use craft\services\Entries;
use craft\services\ImageTransforms;
use craft\web\Response as WebResponse;
use Mockery\MockInterface;
use SomehowDigital\Craft\Hub\bulk\BulkActions;
use SomehowDigital\Craft\Hub\controllers\BulkController;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\TrashedEntryFilter;
use SomehowDigital\Craft\Hub\models\TrashedFileFilter;
use SomehowDigital\Craft\Hub\models\UserGroupFilter;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\SelectionService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use SomehowDigital\Craft\Hub\Tests\TestCase;
use yii\base\Event;
use yii\base\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;

/**
 * Posts to the action of the bulk controller, from a page of Hub.
 *
 * @param array<string,mixed> $body
 */
function bulkPost(TestCase $test, string $action, array $body): WebResponse
{
	return $test->postTo(BulkController::class, $action, ['path' => 'hub/test', ...$body], $test->identity);
}

/**
 * Applies the action to the items with the `ids`, selected in the list beforehand.
 *
 * @param array<string,mixed> $body
 */
function bulkApply(TestCase $test, array $body): WebResponse
{
	$test->session['hub:bulk:selection:' . stringAt($body, 'list') . ':hub/test'] = array_map(fn(mixed $id): int => (int) $id, array_filter(listOf($body['ids'] ?? []), is_numeric(...)));
	unset($body['ids']);

	return bulkPost($test, 'apply', $body);
}

beforeEach(function (): void {
	$this->identity = new User(['id' => 1, 'username' => 'admin', 'admin' => true]);

});

it('refuses paths that are no pages of Hub', function (): void {
	bulkPost($this, 'apply', ['path' => 'https://example.test/hub', 'list' => ColumnService::LIST_ENTRIES, 'bulkAction' => BulkActions::ACTION_ENABLE]);
})->throws(BadRequestHttpException::class);

it('takes an invalid item for the selection, which takes the permission to bulk-edit', function (): void {
	$this->actingAs(permissions: [Permission::AccessData->value, Page::Entries->viewPermission(), Permission::EnableEntries->value]);
	$this->identity = new User(['id' => 1, 'username' => 'editor']);

	bulkPost($this, 'apply', ['list' => ColumnService::LIST_ENTRIES, 'bulkAction' => BulkActions::ACTION_ENABLE, 'item' => -1]);
})->throws(ForbiddenHttpException::class);

it('offers the actions of each list', function (): void {
	Craft::$app->edition = CmsEdition::Pro;
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn($this->identity);
	$bulk = $this->hub()->bulkActions;

	expect(array_keys($bulk->getActions(ColumnService::LIST_ENTRIES)))->toBe([BulkActions::ACTION_LABELS, BulkActions::ACTION_EXPORT, BulkActions::ACTION_ENABLE, BulkActions::ACTION_DISABLE, BulkActions::ACTION_CHANGE_AUTHOR, BulkActions::ACTION_MOVE_TO_SECTION, BulkActions::ACTION_DELETE_DRAFTS, BulkActions::ACTION_DELETE])
		->and(array_keys($bulk->getActions(ColumnService::LIST_FILES)))->toBe([BulkActions::ACTION_LABELS, BulkActions::ACTION_EXPORT, BulkActions::ACTION_DOWNLOAD, BulkActions::ACTION_MOVE_TO_FOLDER, BulkActions::ACTION_GENERATE_TRANSFORMS, BulkActions::ACTION_DELETE_TRANSFORMS, BulkActions::ACTION_DELETE])
		->and(array_keys($bulk->getActions(ColumnService::LIST_USERS)))->toBe([BulkActions::ACTION_LABELS, BulkActions::ACTION_EXPORT, BulkActions::ACTION_GROUPS, BulkActions::ACTION_SUSPEND, BulkActions::ACTION_UNSUSPEND, BulkActions::ACTION_DELETE])
		->and(array_keys($bulk->getActions(ColumnService::LIST_SECTIONS)))->toBe([BulkActions::ACTION_LABELS])
		->and(array_keys($bulk->getActions(ColumnService::LIST_TRASHED_ENTRIES)))->toBe([BulkActions::ACTION_EXPORT, BulkActions::ACTION_RESTORE, BulkActions::ACTION_RESTORE_WITH_RELATIONS, BulkActions::ACTION_DELETE_PERMANENTLY])
		->and(array_keys($bulk->getActions(ColumnService::LIST_TRASHED_FILES)))->toBe([BulkActions::ACTION_EXPORT, BulkActions::ACTION_RESTORE, BulkActions::ACTION_RESTORE_WITH_RELATIONS, BulkActions::ACTION_DELETE_PERMANENTLY])
		->and(at($bulk->getActions(ColumnService::LIST_TRASHED_FILES), BulkActions::ACTION_RESTORE, 'unless'))->toBe('unrestorable')
		->and(at($bulk->getActions(ColumnService::LIST_TRASHED_FILES), BulkActions::ACTION_RESTORE_WITH_RELATIONS, 'unless'))->toBe('unrestorable')
		->and($bulk->getActions(ColumnService::LIST_ENTRIES)[BulkActions::ACTION_MOVE_TO_SECTION])->toMatchArray(['preview' => true, 'fields' => true])
		->and($bulk->getActions('bogus'))->toBe([]);
});

it('offers labeling while there are labels or the user may create some', function (): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessStructure->value, Page::Sections->viewPermission(), Page::Sections->labelPermission()]);
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 2, 'username' => 'editor']));

	expect($this->hub()->bulkActions->getActions(ColumnService::LIST_SECTIONS))->toBe([]);

	$this->db()->respond('FROM {{%hub_labels}}', [['id' => 1, 'name' => 'Keep', 'description' => null, 'icon' => null, 'color' => null, 'sortOrder' => 1, 'uid' => 'label-keep']]);
	$this->resetServices();

	expect($this->hub()->bulkActions->getActions(ColumnService::LIST_SECTIONS))->toHaveKey(BulkActions::ACTION_LABELS)
		->and($this->hub()->bulkActions->getActions(ColumnService::LIST_SECTIONS)[BulkActions::ACTION_LABELS])->toMatchArray(['labels' => true, 'keep' => true]);
});

it('offers the actions of a list only to users with the permissions', function (array $permissions, array $actions): void {
	/** @var list<string> $permissions */
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Page::Entries->viewPermission(), ...strings($permissions)]);
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 2, 'username' => 'editor']));
	$this->db()->respond('FROM {{%hub_labels}}', [['id' => 1, 'name' => 'Keep', 'description' => null, 'icon' => null, 'color' => null, 'sortOrder' => 1, 'uid' => 'label-keep']]);

	expect(array_keys($this->hub()->bulkActions->getActions(ColumnService::LIST_ENTRIES)))->toBe($actions);
})->with([
	'none' => [[], []],
	'labels' => [[Page::Entries->labelPermission()], [BulkActions::ACTION_LABELS]],
	'enabling' => [[Permission::EnableEntries->value], [BulkActions::ACTION_ENABLE, BulkActions::ACTION_DISABLE]],
	'deleting' => [[Permission::DeleteEntries->value], [BulkActions::ACTION_DELETE]],
	'exporting' => [[Permission::ExportEntries->value], [BulkActions::ACTION_EXPORT]],
	'changing authors' => [[Permission::ChangeAuthors->value], [BulkActions::ACTION_CHANGE_AUTHOR]],
	'moving' => [[Permission::MoveEntries->value], [BulkActions::ACTION_MOVE_TO_SECTION]],
	'deleting drafts' => [[Permission::DeleteDrafts->value], [BulkActions::ACTION_DELETE_DRAFTS]],
	'exporting trashed entries' => [[Permission::ExportTrashedEntries->value], []],
	'an action of another page' => [[Permission::DeleteAssets->value], []],
]);

it('offers no actions for lists the user may not view', function (string $list): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Page::Entries->viewPermission(), Page::Users->labelPermission(), Page::Sections->labelPermission(), Permission::SuspendUsers->value]);
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn($this->identity);

	expect($this->hub()->bulkActions->getActions($list))->toBe([]);
})->with([ColumnService::LIST_USERS, ColumnService::LIST_USER_GROUPS, ColumnService::LIST_SECTIONS, ColumnService::LIST_VOLUMES]);

it('offers no labeling while labels are disabled', function (): void {
	$this->hub()->settings->enableLabels = false;

	try {
		expect($this->hub()->bulkActions->getActions(ColumnService::LIST_SECTIONS))->not->toHaveKey(BulkActions::ACTION_LABELS);
	} finally {
		$this->hub()->settings->enableLabels = true;
	}
});

it('offers no deleting of structure, not even to admins', function (): void {
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn($this->identity);
	$bulk = $this->hub()->bulkActions;

	foreach ([ColumnService::LIST_SECTIONS, ColumnService::LIST_ENTRY_TYPES, ColumnService::LIST_FIELDS, ColumnService::LIST_VOLUMES, ColumnService::LIST_USER_GROUPS] as $list) {
		expect($bulk->getActions($list))->not->toHaveKey(BulkActions::ACTION_DELETE);
	}
});

it('deletes the selected entries the user may delete', function (): void {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1])]);
	$elements = mocked(Craft::$app->getElements());
	$elements->shouldReceive('canDelete')->andReturnUsing(fn(Entry $entry): bool => $entry->id === 1);
	$elements->shouldReceive('deleteElement')->once()->andReturn(true);

	$response = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'delete', 'ids' => ['1', '2', 'bogus']]);

	expect(at($response->data, 'count'))->toBe(1)
		->and(at($response->data, 'skipped'))->toBe(1)
		->and(at($response->data, 'message'))->toContain('applied to one item')
		->and(at($response->data, 'message'))->toContain('One item was skipped');
});

it('disables and enables the selected entries', function (): void {
	$entry = new Entry(['id' => 1, 'siteId' => 1, 'enabled' => true]);
	ElementQueries::respond(Entry::class, [$entry]);
	mocked(Craft::$app->getElements())->shouldReceive('saveElement')->twice()->andReturn(true);

	bulkApply($this, ['list' => 'entries', 'bulkAction' => 'disable', 'ids' => ['1']]);
	expect($entry->enabled)->toBeFalse();

	bulkApply($this, ['list' => 'entries', 'bulkAction' => 'enable', 'ids' => ['1']]);
	expect($entry->enabled)->toBeTrue()
		->and($entry->getEnabledForSite())->toBeTrue()
		->and($entry->getScenario())->toBe(Entry::SCENARIO_LIVE);
});

it('deletes the transforms of the selected files', function (): void {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);
	$row = ['id' => '11', 'assetId' => '1', 'transformer' => 'other\\Transformer', 'filename' => 'a.jpg', 'format' => null, 'transformString' => '_100x100', 'fileExists' => '1', 'inProgress' => '0', 'error' => '0', 'dateIndexed' => null];
	$this->db()->respond('SELECT `id`, `assetId`', [$row]);

	$response = bulkApply($this, ['list' => 'files', 'bulkAction' => 'deleteTransforms', 'ids' => ['1']]);

	expect(at($response->data, 'count'))->toBe(1)
		->and($this->db()->queriesMatching('DELETE FROM {{%imagetransformindex}}')[0])->toContain('`id`=11');
});

it('suspends the selected users, but not the current one', function (): void {
	ElementQueries::respond(User::class, [new User(['id' => 1, 'username' => 'admin']), new User(['id' => 2, 'username' => 'editor'])]);
	$users = mocked(Craft::$app->getUsers());
	$users->shouldReceive('canSuspend')->andReturn(true);
	$users->shouldReceive('suspendUser')->once()->withArgs(fn(User $user): bool => $user->id === 2);

	$response = bulkApply($this, ['list' => 'users', 'bulkAction' => 'suspend', 'ids' => ['1', '2']]);

	expect(at($response->data, 'count'))->toBe(1)
		->and(at($response->data, 'skipped'))->toBe(1);
});

it('offers the actions of the trashed entries by their own permissions, to users who may view the trash', function (array $permissions, array $actions): void {
	/** @var list<string> $permissions */
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Page::Entries->viewPermission(), ...strings($permissions)]);
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 2, 'username' => 'editor']));

	expect(array_keys($this->hub()->bulkActions->getActions(ColumnService::LIST_TRASHED_ENTRIES)))->toBe($actions);
})->with([
	'restoring and purging' => [
		[Permission::ViewTrashedEntries->value, Permission::RestoreEntries->value, Permission::PurgeEntries->value],
		[BulkActions::ACTION_RESTORE, BulkActions::ACTION_RESTORE_WITH_RELATIONS, BulkActions::ACTION_DELETE_PERMANENTLY],
	],
	'restoring' => [[Permission::ViewTrashedEntries->value, Permission::RestoreEntries->value], [BulkActions::ACTION_RESTORE, BulkActions::ACTION_RESTORE_WITH_RELATIONS]],
	'purging' => [[Permission::ViewTrashedEntries->value, Permission::PurgeEntries->value], [BulkActions::ACTION_DELETE_PERMANENTLY]],
	'without viewing the trash' => [[Permission::RestoreEntries->value, Permission::PurgeEntries->value], []],
	'deleting' => [[Permission::ViewTrashedEntries->value, Permission::DeleteEntries->value], []],
]);

it('offers no actions of the trashed items while the trash is disabled', function (): void {
	$this->request();
	$this->hub()->settings->enableTrash = false;

	expect($this->hub()->bulkActions->getActions(ColumnService::LIST_TRASHED_ENTRIES))->toBe([])
		->and($this->hub()->bulkActions->getActions(ColumnService::LIST_TRASHED_FILES))->toBe([]);
});

it('restores the selected trashed entries the user may save', function (): void {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1])]);
	$elements = mocked(Craft::$app->getElements());
	$elements->shouldReceive('canSave')->andReturnUsing(fn(Entry $entry): bool => $entry->id === 1);
	$elements->shouldReceive('restoreElement')->once()->withArgs(fn(Entry $entry): bool => $entry->id === 1)->andReturn(true);

	$response = bulkApply($this, ['list' => 'trashedEntries', 'bulkAction' => 'restore', 'ids' => ['1', '2']]);

	expect(at($response->data, 'count'))->toBe(1)
		->and(at($response->data, 'skipped'))->toBe(1)
		->and(ElementQueries::executed(Entry::class)[0]->trashed)->toBeTrue();
});

it('deletes the selected trashed files permanently', function (): void {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'filename' => 'a.jpg'])]);
	$elements = mocked(Craft::$app->getElements());
	$elements->shouldReceive('canDelete')->andReturn(true);
	$elements->shouldReceive('deleteElement')->once()->withArgs(fn(Asset $asset, bool $hardDelete): bool => $hardDelete)->andReturn(true);

	$response = bulkApply($this, ['list' => 'trashedFiles', 'bulkAction' => 'deletePermanently', 'ids' => ['1']]);

	expect(at($response->data, 'count'))->toBe(1)
		->and(ElementQueries::executed(Asset::class)[0]->trashed)->toBeTrue();
});

it('matches the trashed items against their filters', function (): void {
	$this->request();
	ElementQueries::respond(Entry::class, [new Entry(['id' => 7, 'siteId' => 1])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 8, 'siteId' => 1])]);
	$bulk = $this->hub()->bulk;

	expect($bulk->getMatchingIds(ColumnService::LIST_TRASHED_ENTRIES, new TrashedEntryFilter()))->toBe([7])
		->and($bulk->getMatchingIds(ColumnService::LIST_TRASHED_FILES, new TrashedFileFilter()))->toBe([8])
		->and(ElementQueries::executed(Entry::class)[0]->trashed)->toBeTrue()
		->and($bulk->getMatchingIds(ColumnService::LIST_TRASHED_ENTRIES, new EntryFilter()))->toBe([]);
});

it('fails when the action applies to none of the selected items', function (): void {
	$response = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'delete', 'ids' => []]);

	expect($response->getStatusCode())->toBe(400)
		->and(at($response->data, 'message'))->toContain('couldn’t be applied');
});

it('rejects actions the list does not offer', function (): void {
	bulkApply($this, ['list' => 'users', 'bulkAction' => 'enable', 'ids' => ['1']]);
})->throws(BadRequestHttpException::class);

it('rejects deleting structure, even for admins', function (string $list): void {
	mocked(Craft::$app->getEntries())->shouldNotReceive('deleteSectionById', 'deleteEntryTypeById');
	mocked(Craft::$app->getFields())->shouldNotReceive('deleteFieldById');

	bulkApply($this, ['list' => $list, 'bulkAction' => 'delete', 'ids' => ['1']]);
})->with([ColumnService::LIST_SECTIONS, ColumnService::LIST_ENTRY_TYPES, ColumnService::LIST_FIELDS, ColumnService::LIST_VOLUMES, ColumnService::LIST_USER_GROUPS])
	->throws(BadRequestHttpException::class);

it('requires a post request to apply bulk actions', function (): void {
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn($this->identity);

	Craft::createObject(BulkController::class, ['bulk', $this->hub()])->runAction('apply');
})->throws(MethodNotAllowedHttpException::class);

it('unselects the items an action was applied to, keeping the skipped ones selected', function (): void {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1])]);
	$elements = mocked(Craft::$app->getElements());
	$elements->shouldReceive('canDelete')->andReturnUsing(fn(Entry $entry): bool => $entry->id === 1);
	$elements->shouldReceive('deleteElement')->andReturn(true);

	$response = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'delete', 'ids' => ['1', '2']]);

	expect($this->session['hub:bulk:selection:entries:hub/test'])->toBe([2])
		->and(at($response->data, 'message'))->toContain('so it stays selected')
		->and($response->data)->not->toHaveKey('redirect');
});

it('goes back to the list once the action was applied to all selected items', function (): void {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 3, 'siteId' => 1]), new Entry(['id' => 4, 'siteId' => 1])]);
	mocked(Craft::$app->getElements())->shouldReceive('saveElement')->andReturn(true);

	$response = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'disable', 'ids' => ['3', '4']]);

	expect($this->session)->not->toHaveKey('hub:bulk:selection:entries:hub/test')
		->and(at($response->data, 'redirect'))->toContain('hub/test');
});

it('applies an action to a single item in place of the selection, unselecting it', function (): void {
	$entry = new Entry(['id' => 2, 'siteId' => 1, 'enabled' => true]);
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => listOf($query->id) === [2] ? [$entry] : []);
	mocked(Craft::$app->getElements())->shouldReceive('saveElement')->once()->andReturn(true);

	$response = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'disable', 'item' => '2', 'ids' => ['1', '2']]);

	expect($entry->enabled)->toBeFalse()
		->and(at($response->data, 'count'))->toBe(1)
		->and($this->session['hub:bulk:selection:entries:hub/test'])->toBe([1])
		->and($response->data)->not->toHaveKey('redirect');
});

it('applies an action to a single item for users who may not bulk-edit the list', function (): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Page::Entries->viewPermission(), Permission::EnableEntries->value]);
	$entry = new Entry(['id' => 2, 'siteId' => 1, 'enabled' => true]);
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => listOf($query->id) === [2] ? [$entry] : []);
	mocked(Craft::$app->getElements())->shouldReceive('saveElement')->once()->andReturn(true);

	$response = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'disable', 'item' => '2']);

	expect($entry->enabled)->toBeFalse()
		->and(at($response->data, 'count'))->toBe(1);
});

it('selects nothing for users who may not bulk-edit the list', function (string $action, array $body): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Page::Entries->viewPermission(), Permission::EnableEntries->value, Permission::DeleteEntries->value]);
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	/** @var array<string, mixed> $body */
	bulkPost($this, $action, ['list' => 'entries', ...$body]);
})->with([
	'selecting' => ['select', ['add' => ['3']]],
	'applying to the selection' => ['apply', ['bulkAction' => 'disable']],
	'previewing the selection' => ['preview', ['bulkAction' => 'delete']],
])->throws(ForbiddenHttpException::class);

it('lets users who may bulk-edit the list select its items', function (): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Page::Entries->viewPermission(), Page::Entries->bulkEditPermission(), Permission::EnableEntries->value]);

	expect(at(bulkPost($this, 'select', ['list' => 'entries', 'add' => ['3']])->data, 'ids'))->toBe([3])
		->and($this->hub()->bulkActions->canSelect(ColumnService::LIST_ENTRIES))->toBeTrue();

	// Bulk-editing alone offers nothing to apply to the selection.
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Page::Entries->viewPermission(), Page::Entries->bulkEditPermission()]);

	expect($this->hub()->bulkActions->canSelect(ColumnService::LIST_ENTRIES))->toBeFalse();
});

it('offers the actions of the assets and users by their own permissions', function (array $permissions, string $list, array $actions): void {
	/** @var list<string> $permissions */
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Page::Assets->viewPermission(), Page::Users->viewPermission(), ...strings($permissions)]);
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn($this->identity);

	expect(array_keys($this->hub()->bulkActions->getActions($list)))->toBe($actions);
})->with([
	'downloading' => [[Permission::DownloadAssets->value], ColumnService::LIST_FILES, [BulkActions::ACTION_DOWNLOAD]],
	'exporting assets' => [[Permission::ExportAssets->value], ColumnService::LIST_FILES, [BulkActions::ACTION_EXPORT]],
	'exporting users' => [[Permission::ExportUsers->value], ColumnService::LIST_USERS, [BulkActions::ACTION_EXPORT]],
	'exporting trashed assets' => [[Permission::ViewTrashedAssets->value, Permission::ExportTrashedAssets->value], ColumnService::LIST_TRASHED_FILES, [BulkActions::ACTION_EXPORT]],
	'exporting assets, but not trashed ones' => [[Permission::ViewTrashedAssets->value, Permission::ExportAssets->value], ColumnService::LIST_TRASHED_FILES, []],
	'deleting transforms' => [[Permission::DeleteTransforms->value], ColumnService::LIST_FILES, [BulkActions::ACTION_DELETE_TRANSFORMS]],
	'deleting assets' => [[Permission::DeleteAssets->value], ColumnService::LIST_FILES, [BulkActions::ACTION_DELETE]],
	'suspending' => [[Permission::SuspendUsers->value], ColumnService::LIST_USERS, [BulkActions::ACTION_SUSPEND, BulkActions::ACTION_UNSUSPEND]],
	'moving assets' => [[Permission::MoveAssets->value], ColumnService::LIST_FILES, [BulkActions::ACTION_MOVE_TO_FOLDER]],
	'generating transforms' => [[Permission::GenerateTransforms->value], ColumnService::LIST_FILES, [BulkActions::ACTION_GENERATE_TRANSFORMS]],
	'changing groups, but not in Craft Solo' => [[Permission::AssignUserGroups->value], ColumnService::LIST_USERS, []],
	'deleting users' => [[Permission::DeleteUsers->value], ColumnService::LIST_USERS, [BulkActions::ACTION_DELETE]],
	'restoring trashed assets' => [
		[Permission::ViewTrashedAssets->value, Permission::RestoreAssets->value, Permission::PurgeAssets->value],
		ColumnService::LIST_TRASHED_FILES,
		[BulkActions::ACTION_RESTORE, BulkActions::ACTION_RESTORE_WITH_RELATIONS, BulkActions::ACTION_DELETE_PERMANENTLY],
	],
	'trashed assets without restoring them' => [[Permission::ViewTrashedAssets->value, Permission::DeleteAssets->value], ColumnService::LIST_TRASHED_FILES, []],
	'none' => [[], ColumnService::LIST_FILES, []],
]);

it('adds and removes labels of the selected items, keeping them selected', function (): void {
	$this->db()->respond('FROM {{%hub_labels}}', [
		['id' => 1, 'name' => 'Keep', 'description' => null, 'icon' => null, 'color' => null, 'sortOrder' => 1, 'uid' => 'label-keep'],
		['id' => 2, 'name' => 'Review', 'description' => null, 'icon' => null, 'color' => null, 'sortOrder' => 2, 'uid' => 'label-review'],
	]);
	$this->db()->respond('FROM {{%hub_labeled}}', [['itemId' => 5, 'labelId' => 1]]);
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 5, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => 'Hello']), new Entry(['id' => 6, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => 'World'])]);
	$this->db()->tables = [Tables::ACTIVITY];

	$response = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'labels', 'labelsAdd' => ['1'], 'labelsRemove' => ['2'], 'ids' => ['5', '6', '7']]);
	$inserts = $this->db()->queriesMatching('INSERT INTO {{%hub_labeled}}');

	expect(at($response->data, 'count'))->toBe(2)
		->and(at($response->data, 'skipped'))->toBe(1)
		->and($this->session['hub:bulk:selection:entries:hub/test'])->toBe([5, 6, 7])
		->and($response->data)->not->toHaveKey('redirect')
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_labeled}}')[0])->toContain('`labelId`=2')
		->and($inserts)->toHaveCount(1)
		->and($inserts[0])->toContain("(1, 'entries', 6")
		->and($inserts[0])->not->toContain("(1, 'entries', 5")
		->and(array_map(fn(array $row): array => array_slice($row, 0, 5), activities()))->toBe([[ActivityAction::Labeled, ActivitySubject::Entry, 6, 'World', ['added' => ['Keep']]]])
		->and(array_values(array_filter($this->db()->queriesMatching(sqlInsertInto(Tables::ACTIVITY)), fn(string $sql): bool => str_contains($sql, "'bulk'"))))->toHaveCount(1)
		->and(isBatching())->toBeFalse();
});

it('keeps the selection when downloading files', function (): void {
	ElementQueries::respond(Asset::class, []);

	bulkApply($this, ['list' => 'files', 'bulkAction' => 'download', 'ids' => ['1']]);

	expect($this->session['hub:bulk:selection:files:hub/test'])->toBe([1]);
});

it('selects and unselects items by their IDs', function (): void {
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	$response = bulkPost($this, 'select', ['list' => 'entries', 'add' => ['3', '4', '2', 'x', '-1'], 'remove' => ['1']]);

	expect(at($response->data, 'ids'))->toBe([2, 3, 4])
		->and($this->session['hub:bulk:selection:entries:hub/test'])->toBe([2, 3, 4]);
});

it('clears the selection before selecting items', function (): void {
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	expect(at(bulkPost($this, 'select', ['list' => 'entries', 'clear' => 1, 'add' => ['5']])->data, 'ids'))->toBe([5]);

	bulkPost($this, 'select', ['list' => 'entries', 'clear' => 1]);

	expect($this->session)->not->toHaveKey('hub:bulk:selection:entries:hub/test');
});

it('selects and unselects all items matching the filters the list was shown with', function (): void {
	$this->createContentModel();
	$filter = new SectionFilter();
	$filter->types = ['channel'];
	$this->session['hub:bulk:filter:sections:hub/test'] = $filter;
	$this->session['hub:bulk:selection:sections:hub/test'] = [2];

	// Like the list, sorted by name: archive, then news.
	expect(at(bulkPost($this, 'select', ['list' => 'sections', 'matching' => 'add'])->data, 'ids'))->toBe([2, 4, 1])
		->and(at(bulkPost($this, 'select', ['list' => 'sections', 'matching' => 'remove'])->data, 'ids'))->toBe([2]);
});

it('selects no matching items without filters kept for the list', function (): void {
	$this->createContentModel();

	expect(at(bulkPost($this, 'select', ['list' => 'sections', 'matching' => 'add'])->data, 'ids'))->toBe([])
		->and(at(bulkPost($this, 'select', ['list' => 'entries', 'matching' => 'add'])->data, 'ids'))->toBe([]);
});

it('refuses selecting more items than can be selected at once', function (): void {
	$this->session['hub:bulk:selection:entries:hub/test'] = range(1, SelectionService::LIMIT);

	$response = bulkPost($this, 'select', ['list' => 'entries', 'add' => [SelectionService::LIMIT + 1]]);

	expect(at($response->data, 'ids'))->toHaveCount(SelectionService::LIMIT)
		->and(at($response->data, 'error'))->toContain('Up to 1,000 items')
		->and($this->session['hub:bulk:selection:entries:hub/test'])->toHaveCount(SelectionService::LIMIT);
});

it('rejects selecting in lists without actions for the user', function (): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessStructure->value, Page::Sections->viewPermission()]);
	$this->identity = new User(['id' => 2, 'username' => 'editor']);

	bulkPost($this, 'select', ['list' => 'sections', 'add' => ['1']]);
})->throws(BadRequestHttpException::class);

it('matches the items of each list against its filters', function (): void {
	$this->createContentModel();
	$this->request();
	$bulk = $this->hub()->bulk;
	$queried = [];
	ElementQueries::respond(Entry::class, function (ElementQuery $query) use (&$queried): array {
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

it('requires a post request asking for JSON to change the selection', function (): void {
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn($this->identity);

	Craft::createObject(BulkController::class, ['bulk', $this->hub()])->runAction('select');
})->throws(MethodNotAllowedHttpException::class);

it('previews deleting the selection, or a single item', function (): void {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => array_map(
		fn(int $id): Entry => new Entry(['id' => $id, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => "Entry {$id}"]),
		idsOf($query->id),
	));
	mocked(Craft::$app->getElements())->shouldReceive('canDelete')->andReturn(true);
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	// The preview links the items to their Hub pages, which keep the selected sites of the plugin.
	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	// The responses are the same object, so their data is read as they are sent.
	$selection = bulkPost($this, 'preview', ['list' => 'entries'])->data;
	$single = bulkPost($this, 'preview', ['list' => 'entries', 'item' => '2'])->data;

	expect(at($selection, 'total'))->toBe(2)
		->and(at($selection, 'html'))->toContain('Entry 1')
		->and(at($selection, 'html'))->toContain('moved to the trash')
		->and(at($single, 'total'))->toBe(1)
		->and(at($single, 'html'))->not->toContain('Entry 1');
});

it('previews deleting only for lists whose items are deleted', function (): void {
	bulkPost($this, 'preview', ['list' => 'sections']);
})->throws(BadRequestHttpException::class);

it('previews downloading the files the user may view, and how they are sent', function (): void {
	$this->volume(1, 'images');
	ElementQueries::respond(Asset::class, fn(ElementQuery $query): array => array_map(
		fn(int $id): Asset => new Asset(['id' => $id, 'siteId' => 1, 'volumeId' => 1, 'kind' => 'image', 'filename' => "file-{$id}.jpg", 'title' => "File {$id}", 'size' => 1000 * 1000]),
		idsOf($query->id),
	));
	mocked(Craft::$app->getElements())->shouldReceive('canView')->andReturnUsing(fn(Asset $asset): bool => $asset->id !== 3);
	$this->session['hub:bulk:selection:files:hub/test'] = [1, 2, 3];

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$selection = bulkPost($this, 'preview', ['list' => 'files', 'bulkAction' => 'download'])->data;
	$single = bulkPost($this, 'preview', ['list' => 'files', 'bulkAction' => 'download', 'item' => '2'])->data;

	expect(at($selection, 'total'))->toBe(2)
		->and(at($selection, 'html'))->toContain('File 1')
		->and(at($selection, 'html'))->toContain('Images/file-2.jpg')
		->and(at($selection, 'html'))->toContain('zip archive')
		->and(at($selection, 'html'))->toContain('1 skipped')
		->and(at($single, 'total'))->toBe(1)
		->and(at($single, 'html'))->toContain('downloaded as it is')
		->and(at($single, 'html'))->not->toContain('File 1');
});

it('previews the items by the page asked for, the totals covering all of them', function (): void {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => array_map(
		fn(int $id): Entry => new Entry(['id' => $id, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => "Entry {$id}"]),
		idsOf($query->id),
	));
	$this->volume(1, 'images');
	ElementQueries::respond(Asset::class, fn(ElementQuery $query): array => array_map(
		fn(int $id): Asset => new Asset(['id' => $id, 'siteId' => 1, 'volumeId' => 1, 'kind' => 'image', 'filename' => "file-{$id}.jpg", 'title' => "File {$id}", 'size' => 1000]),
		idsOf($query->id),
	));
	mocked(Craft::$app->getElements())->shouldReceive('canDelete')->andReturn(true);
	mocked(Craft::$app->getElements())->shouldReceive('canView')->andReturn(true);
	$this->session['hub:bulk:selection:entries:hub/test'] = range(1, 52);
	$this->session['hub:bulk:selection:files:hub/test'] = range(1, 52);

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$first = bulkPost($this, 'preview', ['list' => 'entries'])->data;
	$second = bulkPost($this, 'preview', ['list' => 'entries', 'page' => '2'])->data;
	$files = bulkPost($this, 'preview', ['list' => 'files', 'bulkAction' => 'download', 'page' => '2'])->data;

	expect(at($first, 'total'))->toBe(52)
		->and(at($first, 'html'))->toContain('Entry 50')
		->and(at($first, 'html'))->not->toContain('Entry 51')
		->and(at($first, 'html'))->toContain('data-hub-preview-pages="1"')
		->and(at($second, 'total'))->toBe(52)
		->and(at($second, 'html'))->toContain('Entry 52')
		->and(at($second, 'html'))->not->toContain('Entry 50<')
		->and(at($second, 'html'))->toContain('data-hub-preview-pages="2"')
		->and(at($files, 'total'))->toBe(52)
		->and(at($files, 'html'))->toContain('File 51')
		->and(at($files, 'html'))->not->toContain('File 1<')
		->and(at($files, 'html'))->toContain('data-hub-preview-pages="2"');
});

it('previews deleting the transforms of the selected files the user may save', function (): void {
	$this->volume(1, 'images');
	ElementQueries::respond(Asset::class, fn(ElementQuery $query): array => array_map(
		fn(int $id): Asset => new Asset(['id' => $id, 'siteId' => 1, 'volumeId' => 1, 'kind' => 'image', 'filename' => "file-{$id}.jpg", 'title' => "File {$id}"]),
		idsOf($query->id),
	));
	mocked(Craft::$app->getElements())->shouldReceive('canSave')->andReturnUsing(fn(Asset $asset): bool => $asset->id !== 3);
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

	$selection = bulkPost($this, 'preview', ['list' => 'files', 'bulkAction' => 'deleteTransforms'])->data;

	// The second file has no transforms, and the third may not be saved.
	expect(at($selection, 'total'))->toBe(1)
		->and(at($selection, 'html'))->toContain('2 transforms')
		->and(at($selection, 'html'))->toContain('_200x200')
		->and(at($selection, 'html'))->not->toContain('_300x300')
		->and(at($selection, 'html'))->toContain('generated again')
		->and(at($selection, 'html'))->toContain('2 skipped');
});

it('previews only the actions previewed', function (): void {
	bulkPost($this, 'preview', ['list' => 'users', 'bulkAction' => 'suspend']);
})->throws(BadRequestHttpException::class);

it('offers Craft’s exporters of the element type of the list, along with those registered by plugins', function (): void {
	$this->request();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn($this->identity);
	$bulk = $this->hub()->bulk;

	expect(array_keys($bulk->getExporters(ColumnService::LIST_ENTRIES)))->toBe([Raw::class, Expanded::class])
		->and(array_keys($bulk->getExporters(ColumnService::LIST_TRASHED_FILES)))->toBe([Raw::class, Expanded::class])
		->and($bulk->getExporters(ColumnService::LIST_SECTIONS))->toBe([])
		->and(array_keys($bulk->getExportFormats()))->toBe(['csv', 'xlsx', 'json', 'xml', 'yaml']);

	$handler = function (RegisterElementExportersEvent $event): void {
		$event->exporters = [Expanded::class];
	};
	Event::on(User::class, User::EVENT_REGISTER_EXPORTERS, $handler);

	try {
		expect(array_keys($bulk->getExporters(ColumnService::LIST_USERS)))->toBe([Expanded::class]);
	} finally {
		Event::off(User::class, User::EVENT_REGISTER_EXPORTERS, $handler);
	}
});

it('previews exporting the items the user may view, offering the exporters and formats', function (): void {
	ElementQueries::respond(Asset::class, fn(ElementQuery $query): array => array_map(
		fn(int $id): Asset => new Asset(['id' => $id, 'siteId' => 1, 'filename' => "file-{$id}.jpg"]),
		idsOf($query->id),
	));
	mocked(Craft::$app->getElements())->shouldReceive('canView')->andReturnUsing(fn(Asset $asset): bool => $asset->id !== 3);
	$this->session['hub:bulk:selection:trashedFiles:hub/test'] = [1, 2, 3];

	$selection = bulkPost($this, 'preview', ['list' => 'trashedFiles', 'bulkAction' => 'export'])->data;

	expect(at($selection, 'total'))->toBe(2)
		->and(at($selection, 'html'))->toContain('2 items')
		->and(at($selection, 'html'))->toContain('1 skipped')
		->and(at($selection, 'html'))->toContain('Raw data (fastest)')
		->and(at($selection, 'html'))->toContain('value="' . htmlspecialchars(Expanded::class) . '"')
		->and(at($selection, 'html'))->toContain('XLSX')
		->and(ElementQueries::executed(Asset::class)[0]->trashed)->toBeTrue();
});

it('exports the selected items the user may view by the exporter and format picked, keeping them selected', function (): void {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => array_map(
		fn(int $id): Entry|array => $query->asArray ? ['id' => $id, 'title' => "Entry {$id}"] : new Entry(['id' => $id, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => "Entry {$id}"]),
		idsOf($query->id),
	));
	mocked(Craft::$app->getElements())->shouldReceive('canView')->andReturnUsing(fn(Entry $entry): bool => $entry->id !== 3);
	$this->db()->tables = [Tables::ACTIVITY];

	$response = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'export', 'exporter' => Raw::class, 'format' => 'json', 'ids' => ['2', '1', '3']]);
	$queries = ElementQueries::executed(Entry::class);
	$last = $queries[array_key_last($queries) ?? throw new LogicException('No query ran.')];

	expect($response->format)->toBe('json')
		->and($response->data)->toBe([['id' => 2, 'title' => 'Entry 2'], ['id' => 1, 'title' => 'Entry 1']])
		->and($response->getHeaders()->get('Content-Disposition'))->toContain('entries.json')
		->and($last->id)->toBe([2, 1])
		->and($last->fixedOrder)->toBeTrue()
		->and($this->session['hub:bulk:selection:entries:hub/test'])->toBe([2, 1, 3])
		->and(array_map(fn(array $row): array => [$row[0], $row[1], $row[2]], activities()))->toBe([[ActivityAction::Exported, ActivitySubject::Entry, 2], [ActivityAction::Exported, ActivitySubject::Entry, 1]])
		->and(isBatching())->toBeFalse();
});

it('exports by the first exporter as CSV by default', function (): void {
	ElementQueries::respond(User::class, fn(ElementQuery $query): array => array_map(
		fn(int $id): User|array => $query->asArray ? ['id' => $id] : new User(['id' => $id, 'username' => "user{$id}"]),
		idsOf($query->id),
	));
	mocked(Craft::$app->getElements())->shouldReceive('canView')->andReturn(true);

	$response = bulkApply($this, ['list' => 'users', 'bulkAction' => 'export', 'ids' => ['4']]);

	expect($response->format)->toBe('csv')
		->and($response->data)->toBe([['id' => 4]])
		->and($response->getHeaders()->get('Content-Disposition'))->toContain('users.csv')
		->and(ElementQueries::executed(User::class)[0]->siteId)->toBeNull();
});

it('fails exporting when the user may view none of the selected items', function (): void {
	ElementQueries::respond(Entry::class, []);

	$response = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'export', 'ids' => ['1']]);

	expect(at($response->data, 'message'))->toContain('couldn’t be applied');
});

it('rejects exporting by exporters or in formats Craft does not offer', function (array $body): void {
	/** @var array<string, mixed> $body */
	bulkApply($this, ['list' => 'entries', 'bulkAction' => 'export', 'ids' => ['1'], ...$body]);
})->with([
	'exporter' => [['exporter' => 'bogus']],
	'format' => [['exporter' => Raw::class, 'format' => 'html']],
])->throws(BadRequestHttpException::class);

it('replaces the authors of the selected entries with the author picked, or adds it to them', function (): void {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')])->maxAuthors = 2;
	$first = new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'authorIds' => [2]]);
	$second = new Entry(['id' => 2, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'authorIds' => [2]]);
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => array_values(array_filter([$first, $second], fn(Entry $entry): bool => in_array($entry->id, listOf($query->id), true))));
	ElementQueries::respond(User::class, fn(ElementQuery $query): array => listOf($query->id) === [7] ? [new User(['id' => 7, 'username' => 'author', 'admin' => true])] : []);
	mocked(Craft::$app->getElements())->shouldReceive('saveElement')->twice()->andReturn(true);

	$replaced = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'changeAuthor', 'ids' => ['1'], 'authorId' => '7', 'authors' => 'replace']);
	$added = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'changeAuthor', 'ids' => ['2'], 'authorId' => '7', 'authors' => 'add']);

	expect(at($replaced->data, 'count'))->toBe(1)
		->and($first->getAuthorIds())->toBe([7])
		->and(at($added->data, 'count'))->toBe(1)
		->and($second->getAuthorIds())->toBe([2, 7]);
});

it('changes no authors without an author picked, or of entries the author may not author', function (): void {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	$this->section(2, 'home', Section::TYPE_SINGLE, entryTypes: [$this->entryType(11, 'home')]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'authorIds' => [7]]), new Entry(['id' => 2, 'siteId' => 1, 'sectionId' => 2, 'typeId' => 11])]);
	ElementQueries::respond(User::class, [new User(['id' => 7, 'username' => 'author', 'admin' => true])]);
	mocked(Craft::$app->getElements())->shouldNotReceive('saveElement');

	$none = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'changeAuthor', 'ids' => ['1']]);
	// The first one has the author already, the single has no authors.
	$same = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'changeAuthor', 'ids' => ['1', '2'], 'authorId' => '7']);

	expect($none->getStatusCode())->toBe(400)
		->and(at($same->data, 'count'))->toBe(1)
		->and(at($same->data, 'skipped'))->toBe(1);
});

it('previews changing authors, offering those who may author the entries', function (): void {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'authorIds' => [7]])]);
	ElementQueries::respond(User::class, [new User(['id' => 8, 'username' => 'zoe', 'fullName' => 'Zoe']), new User(['id' => 7, 'username' => 'ann', 'fullName' => 'Ann'])]);
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	$preview = bulkPost($this, 'preview', ['list' => 'entries', 'bulkAction' => 'changeAuthor'])->data;

	expect(at($preview, 'total'))->toBe(1)
		->and(at($preview, 'html'))->toContain('name="authorId"')
		->and(at($preview, 'html'))->toMatch('/Ann \(ann\).*Zoe \(zoe\)/s')
		->and(at($preview, 'html'))->toContain('value="add"')
		->and(at($preview, 'html'))->toContain('1 skipped');
});

it('moves the selected entries to the section picked, as far as it has their entry types', function (): void {
	$article = $this->entryType(10, 'article');
	$page = $this->entryType(11, 'page');
	$this->section(1, 'news', entryTypes: [$article, $page]);
	$this->section(2, 'blog', entryTypes: [$article]);
	ElementQueries::respond(Entry::class, [
		new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10]),
		new Entry(['id' => 2, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 11]),
		new Entry(['id' => 3, 'siteId' => 1, 'sectionId' => 2, 'typeId' => 10]),
	]);
	mocked(Craft::$app->getEntries())->shouldReceive('moveEntryToSection')->once()
		->withArgs(fn(Entry $entry, Section $section): bool => $entry->id === 1 && $section->id === 2)
		->andReturn(true);

	$response = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'moveToSection', 'ids' => ['1', '2', '3'], 'sectionId' => '2']);

	expect(at($response->data, 'count'))->toBe(1)
		->and(at($response->data, 'skipped'))->toBe(2);
});

it('skips entries Craft fails to move, and moves none without a section picked', function (): void {
	$article = $this->entryType(10, 'article');
	$this->section(1, 'news', entryTypes: [$article]);
	$this->section(2, 'blog', entryTypes: [$article]);
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10])]);
	mocked(Craft::$app->getEntries())->shouldReceive('moveEntryToSection')->once()->andThrow(new Exception('Invalid'));

	$failed = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'moveToSection', 'ids' => ['1'], 'sectionId' => '2']);
	$none = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'moveToSection', 'ids' => ['1'], 'sectionId' => '99']);

	expect($failed->getStatusCode())->toBe(400)
		->and($none->getStatusCode())->toBe(400);
});

it('previews moving entries, offering the sections taking them', function (): void {
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

	$preview = bulkPost($this, 'preview', ['list' => 'entries', 'bulkAction' => 'moveToSection'])->data;

	expect(at($preview, 'total'))->toBe(2)
		->and(at($preview, 'html'))->toContain('Blog (1 of 2)')
		->and(at($preview, 'html'))->toContain('>Pages<')
		->and(at($preview, 'html'))->not->toContain('Home')
		->and(at($preview, 'html'))->not->toContain('>News<');
});

it('deletes the drafts of the selected entries the user may delete', function (): void {
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => $query->drafts === true
		? [new Entry(['id' => 11, 'siteId' => 1, 'canonicalId' => 1, 'draftId' => 1]), new Entry(['id' => 12, 'siteId' => 1, 'canonicalId' => 1, 'draftId' => 2]), new Entry(['id' => 13, 'siteId' => 1, 'canonicalId' => 2, 'draftId' => 3])]
		: [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1]), new Entry(['id' => 3, 'siteId' => 1])]);
	$elements = mocked(Craft::$app->getElements());
	$elements->shouldReceive('canDelete')->andReturnUsing(fn(Entry $draft): bool => $draft->id !== 13);
	$elements->shouldReceive('deleteElement')->twice()->withArgs(fn(Entry $draft, bool $hardDelete): bool => $draft->getCanonicalId() === 1 && $hardDelete)->andReturn(true);

	$response = bulkApply($this, ['list' => 'entries', 'bulkAction' => 'deleteDrafts', 'ids' => ['1', '2', '3']]);

	expect(at($response->data, 'count'))->toBe(1)
		->and(at($response->data, 'skipped'))->toBe(2)
		->and(array_values(array_filter(ElementQueries::executed(Entry::class), fn(ElementQuery $query): bool => $query->drafts === true))[0]->draftOf)->toBe([1, 2, 3]);
});

it('previews deleting the drafts of entries, listing them by entry', function (): void {
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article')]);
	$draft = new Entry(['id' => 11, 'canonicalId' => 1, 'draftId' => 1, 'title' => 'Draft one', 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10]);
	$draft->attachBehavior('draft', ['class' => DraftBehavior::class, 'draftName' => 'First draft']);
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => $query->drafts === true
		? [$draft]
		: [new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => 'Entry 1']), new Entry(['id' => 2, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => 'Entry 2'])]);
	mocked(Craft::$app->getElements())->shouldReceive('canDelete')->andReturn(true);
	$this->session['hub:bulk:selection:entries:hub/test'] = [1, 2];

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$preview = bulkPost($this, 'preview', ['list' => 'entries', 'bulkAction' => 'deleteDrafts'])->data;

	expect(at($preview, 'total'))->toBe(1)
		->and(at($preview, 'html'))->toContain('Entry 1')
		->and(at($preview, 'html'))->not->toContain('Entry 2')
		->and(at($preview, 'html'))->toContain('Draft one')
		->and(at($preview, 'html'))->toContain('First draft')
		->and(at($preview, 'html'))->toContain('deleted permanently')
		->and(at($preview, 'html'))->toContain('1 skipped');
});

it('moves the selected files to the folder picked, as far as the user may move them', function (): void {
	$this->volume(1, 'images');
	$this->volume(2, 'documents');
	ElementQueries::respond(Asset::class, [
		new Asset(['id' => 1, 'siteId' => 1, 'volumeId' => 1, 'folderId' => 10, 'filename' => 'a.jpg']),
		new Asset(['id' => 2, 'siteId' => 1, 'volumeId' => 1, 'folderId' => 10, 'filename' => 'b.jpg']),
		new Asset(['id' => 3, 'siteId' => 1, 'volumeId' => 2, 'folderId' => 20, 'filename' => 'c.pdf']),
	]);
	$target = new VolumeFolder(['id' => 20, 'volumeId' => 2, 'path' => '']);
	$assets = $this->mockComponent('assets', Assets::class);
	$assets->shouldReceive('findFolders')->andReturnUsing(fn(array $criteria): array => $criteria['volumeId'] === 2 ? [$target] : [new VolumeFolder(['id' => 10, 'volumeId' => 1, 'path' => ''])]);
	$assets->shouldReceive('moveAsset')->once()->withArgs(fn(Asset $asset, VolumeFolder $folder): bool => $asset->id === 1 && $folder === $target)->andReturn(true);
	$elements = mocked(Craft::$app->getElements());
	$elements->shouldReceive('canDelete')->andReturnUsing(fn(Asset $asset): bool => $asset->id !== 2);

	// The second may not be deleted from its volume, the third is in the folder already.
	$response = bulkApply($this, ['list' => 'files', 'bulkAction' => 'moveToFolder', 'ids' => ['1', '2', '3'], 'folderId' => '20'])->data;
	$unknown = bulkApply($this, ['list' => 'files', 'bulkAction' => 'moveToFolder', 'ids' => ['1'], 'folderId' => '99'])->getStatusCode();

	expect(at($response, 'count'))->toBe(1)
		->and(at($response, 'skipped'))->toBe(2)
		->and($unknown)->toBe(400);
});

it('previews moving files, offering the folders of the volumes the user may save files in', function (): void {
	$this->volume(1, 'images', name: 'Images');
	$this->volume(2, 'documents', name: 'Documents');
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'volumeId' => 1, 'folderId' => 10, 'filename' => 'a.jpg'])]);
	$assets = $this->mockComponent('assets', Assets::class);
	$assets->shouldReceive('findFolders')->andReturnUsing(fn(array $criteria): array => $criteria['volumeId'] === 1
		? [new VolumeFolder(['id' => 10, 'volumeId' => 1, 'path' => '']), new VolumeFolder(['id' => 11, 'volumeId' => 1, 'path' => 'photos/'])]
		: [new VolumeFolder(['id' => 20, 'volumeId' => 2, 'path' => ''])]);
	mocked(Craft::$app->getElements())->shouldReceive('canDelete')->andReturn(true);
	$this->session['hub:bulk:selection:files:hub/test'] = [1];

	$preview = bulkPost($this, 'preview', ['list' => 'files', 'bulkAction' => 'moveToFolder'])->data;

	expect(at($preview, 'total'))->toBe(1)
		->and(at($preview, 'html'))->toContain('<optgroup label="Images">')
		->and(at($preview, 'html'))->toContain('Images/photos')
		->and(at($preview, 'html'))->toContain('<optgroup label="Documents">');
});

it('queues generating the transforms picked of the selected images not generated yet', function (): void {
	ElementQueries::respond(Asset::class, [
		new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg']),
		new Asset(['id' => 2, 'siteId' => 1, 'kind' => 'image', 'filename' => 'b.jpg']),
	]);
	mocked(Craft::$app->getElements())->shouldReceive('canView')->andReturn(true);
	$thumb = new ImageTransform(['handle' => 'thumb', 'name' => 'Thumb', 'width' => 100]);
	$hero = new ImageTransform(['handle' => 'hero', 'name' => 'Hero', 'width' => 1000]);
	$transformer = Mockery::mock(ImageTransformer::class);
	$transformer->shouldReceive('getTransformIndex')->andReturnUsing(fn(Asset $asset, ImageTransform $transform): ImageTransformIndex => new ImageTransformIndex([
		'id' => ($asset->id ?? 0) * 10,
		'assetId' => $asset->id,
		'transformString' => '_' . ($transform->handle ?? ''),
		'fileExists' => $asset->id === 2,
	]));
	/** @var ImageTransforms&MockInterface $transforms */
	$transforms = Mockery::mock(ImageTransforms::class)->makePartial();
	$transforms->shouldReceive('getAllTransforms')->andReturn([$thumb, $hero]);
	$transforms->shouldReceive('getImageTransformer')->with(ImageTransformer::class)->andReturn($transformer);
	Craft::$app->set('imageTransforms', $transforms);
	$queue = $this->mockComponent('queue', Queue::class)->makePartial();
	$queue->shouldReceive('push')->once()->with(Mockery::on(fn(GenerateImageTransform $job): bool => $job->transformId === 10));

	// The second file has the transform already.
	$response = bulkApply($this, ['list' => 'files', 'bulkAction' => 'generateTransforms', 'ids' => ['1', '2'], 'transforms' => ['thumb', 'bogus']])->data;
	$none = bulkApply($this, ['list' => 'files', 'bulkAction' => 'generateTransforms', 'ids' => ['1'], 'transforms' => ['bogus']])->getStatusCode();

	expect(at($response, 'count'))->toBe(1)
		->and(at($response, 'skipped'))->toBe(1)
		->and($none)->toBe(400);
});

it('previews generating transforms, offering the transforms of Craft’s transformer for the images', function (): void {
	ElementQueries::respond(Asset::class, [
		new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg']),
		new Asset(['id' => 2, 'siteId' => 1, 'kind' => 'pdf', 'filename' => 'b.pdf']),
	]);
	mocked(Craft::$app->getElements())->shouldReceive('canView')->andReturn(true);
	/** @var ImageTransforms&MockInterface $transforms */
	$transforms = Mockery::mock(ImageTransforms::class)->makePartial();
	$transforms->shouldReceive('getAllTransforms')->andReturn([
		new ImageTransform(['handle' => 'thumb', 'name' => 'Thumb']),
		new ImageTransform(['handle' => 'service', 'name' => 'Service', 'transformer' => FallbackTransformer::class]),
	]);
	Craft::$app->set('imageTransforms', $transforms);
	$this->session['hub:bulk:selection:files:hub/test'] = [1, 2];

	$preview = bulkPost($this, 'preview', ['list' => 'files', 'bulkAction' => 'generateTransforms'])->data;

	expect(at($preview, 'total'))->toBe(1)
		->and(at($preview, 'html'))->toContain('value="thumb" checked')
		->and(at($preview, 'html'))->not->toContain('Service')
		->and(at($preview, 'html'))->toContain('1 skipped');
});

it('adds the selected users to the groups picked and removes them from others, but not the current one', function (): void {
	Craft::$app->edition = CmsEdition::Pro;
	$this->userGroup(1, 'editors');
	$this->userGroup(2, 'authors');
	$this->userGroup(3, 'guests');
	ElementQueries::respond(User::class, [new User(['id' => 1, 'username' => 'admin']), new User(['id' => 2, 'username' => 'editor']), new User(['id' => 3, 'username' => 'author'])]);
	mocked(Craft::$app->getUserGroups())->shouldReceive('getGroupsByUserId')->andReturnUsing(fn(int $id): array => $id === 3 ? [$this->userGroups[1]] : [$this->userGroups[2]]);
	$users = mocked(Craft::$app->getUsers());
	$users->shouldReceive('assignUserToGroups')->once()->with(2, [1])->andReturn(true);

	// The third one is in the first group already, and not in the second.
	$response = bulkApply($this, ['list' => 'users', 'bulkAction' => 'groups', 'ids' => ['1', '2', '3'], 'groups' => ['1' => 'add', '2' => 'remove', '3' => '', '99' => 'add']])->data;
	$none = bulkApply($this, ['list' => 'users', 'bulkAction' => 'groups', 'ids' => ['2'], 'groups' => ['3' => '']])->getStatusCode();

	expect(at($response, 'count'))->toBe(2)
		->and(at($response, 'skipped'))->toBe(1)
		->and($none)->toBe(400);
});

it('previews changing groups, naming how many of the users are in each', function (): void {
	Craft::$app->edition = CmsEdition::Pro;
	$this->userGroup(1, 'editors')->name = 'Editors';
	$this->userGroup(2, 'authors')->name = 'Authors';
	ElementQueries::respond(User::class, [new User(['id' => 2, 'username' => 'editor']), new User(['id' => 3, 'username' => 'author'])]);
	$this->db()->respond('FROM {{%usergroups_users}}', [['groupId' => 1, 'count' => 2], ['groupId' => 2, 'count' => 1]]);
	$this->session['hub:bulk:selection:users:hub/test'] = [1, 2, 3];

	$preview = bulkPost($this, 'preview', ['list' => 'users', 'bulkAction' => 'groups'])->data;

	expect(at($preview, 'total'))->toBe(2)
		->and(at($preview, 'html'))->toContain('name="groups[1]"')
		->and(at($preview, 'html'))->toContain('All of them are in it.')
		->and(at($preview, 'html'))->toContain('1 of them is in it.')
		->and(at($preview, 'html'))->toContain('1 skipped');
});

it('deletes the selected users, transferring their content to the user picked', function (): void {
	ElementQueries::respond(User::class, fn(ElementQuery $query): array => array_map(
		fn(int $id): User => new User(['id' => $id, 'username' => "user-{$id}"]),
		array_values(array_filter(listOf($query->id), is_int(...))),
	));
	$elements = mocked(Craft::$app->getElements());
	$elements->shouldReceive('canDelete')->andReturn(true);
	$deleted = [];
	/** @var array<int,int|null> $transferred */
	$transferred = [];
	$elements->shouldReceive('deleteElement')->andReturnUsing(function (User $user) use (&$deleted, &$transferred): true {
		$deleted[(int) $user->id] = $transferred[(int) $user->id] ?? null;

		return true;
	});
	$this->mockComponent('entries', Entries::class)->shouldReceive('reassignEntries')->andReturnUsing(function (int $from, int $to) use (&$transferred): int {
		$transferred[$from] = $to;

		return 0;
	});

	// The current one isn't deleted, nor is the one inheriting the content.
	$response = bulkApply($this, ['list' => 'users', 'bulkAction' => 'delete', 'ids' => ['1', '2', '3', '9'], 'inheritorId' => '9']);

	expect(at($response->data, 'count'))->toBe(2)
		->and(at($response->data, 'skipped'))->toBe(2)
		->and($deleted)->toBe([2 => 9, 3 => 9]);
});

it('deletes no users when the user picked to inherit their content does not exist', function (): void {
	ElementQueries::respond(User::class, fn(ElementQuery $query): array => listOf($query->id) === [9] ? [] : [new User(['id' => 2, 'username' => 'editor'])]);
	mocked(Craft::$app->getElements())->shouldNotReceive('deleteElement');

	$response = bulkApply($this, ['list' => 'users', 'bulkAction' => 'delete', 'ids' => ['2'], 'inheritorId' => '9']);

	expect($response->getStatusCode())->toBe(400);
});

it('previews deleting users, with the entries they are an author of and who may inherit them', function (): void {
	ElementQueries::respond(User::class, fn(ElementQuery $query): array => in_array(User::STATUS_ACTIVE, listOf($query->status), true)
		? [new User(['id' => 5, 'username' => 'heir', 'fullName' => 'Heir'])]
		: [new User(['id' => 1, 'username' => 'admin']), new User(['id' => 2, 'username' => 'editor', 'email' => 'editor@example.com'])]);
	mocked(Craft::$app->getElements())->shouldReceive('canDelete')->andReturn(true);
	$this->db()->respond('[[hubAuthors.authorId]]', [['authorId' => 2, 'count' => 3]]);
	$this->session['hub:bulk:selection:users:hub/test'] = [1, 2];

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$preview = bulkPost($this, 'preview', ['list' => 'users', 'bulkAction' => 'delete'])->data;

	expect(at($preview, 'total'))->toBe(1)
		->and(at($preview, 'html'))->toContain('editor@example.com')
		->and(at($preview, 'html'))->toContain('<strong>3</strong>')
		->and(at($preview, 'html'))->toContain('Entries authored')
		->and(at($preview, 'html'))->toContain('>editor<')
		->and(at($preview, 'html'))->toContain('name="inheritorId"')
		->and(at($preview, 'html'))->toContain('Heir (heir)')
		->and(at($preview, 'html'))->toContain('1 skipped');
});

it('restores the selected trashed items along with the trashed elements related to them', function (): void {
	$this->db()->respond('FROM {{%elements_owners}}', fn(string $sql): array => str_contains($sql, 'WHERE `ownerId`') ? [['elementId' => 4, 'ownerId' => 1]] : []);
	$this->db()->respond('FROM {{%relations}}', [['sourceId' => 4, 'targetId' => 20], ['sourceId' => 30, 'targetId' => 2], ['sourceId' => 1, 'targetId' => 2]]);
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => in_array(30, listOf($query->id), true)
		? [new Entry(['id' => 30, 'siteId' => 1])]
		: [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 20, 'siteId' => 1, 'filename' => 'a.jpg', 'keptFile' => true])]);
	$restored = [];
	mocked(Craft::$app->getElements())->shouldReceive('restoreElement')->andReturnUsing(function (ElementInterface $element) use (&$restored): true {
		$restored[] = $element->id;

		return true;
	});

	$response = bulkApply($this, ['list' => 'trashedEntries', 'bulkAction' => 'restoreWithRelations', 'ids' => ['1', '2']]);

	// The file is related through a nested entry of the first, the entry relates to the second.
	expect(at($response->data, 'count'))->toBe(2)
		->and($restored)->toBe([1, 20, 2, 30]);
});

it('previews restoring trashed items with their relations, listing what is restored along with each', function (): void {
	$this->db()->respond('FROM {{%relations}}', [['sourceId' => 1, 'targetId' => 20]]);
	ElementQueries::respond(Asset::class, fn(ElementQuery $query): array => in_array(20, listOf($query->id), true)
		? [new Asset(['id' => 20, 'siteId' => 1, 'filename' => 'related.jpg', 'title' => 'Linked image', 'keptFile' => true])]
		: []);
	ElementQueries::respond(Entry::class, fn(ElementQuery $query): array => in_array(1, listOf($query->id), true) ? [new Entry(['id' => 1, 'siteId' => 1, 'title' => 'Trashed entry'])] : []);
	mocked(Craft::$app->getElements())->shouldReceive('canSave')->andReturn(true);
	$this->session['hub:bulk:selection:trashedEntries:hub/test'] = [1];

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$preview = bulkPost($this, 'preview', ['list' => 'trashedEntries', 'bulkAction' => 'restoreWithRelations'])->data;

	expect(at($preview, 'total'))->toBe(1)
		->and(at($preview, 'html'))->toContain('Trashed entry')
		->and(at($preview, 'html'))->toContain('Linked image')
		->and(at($preview, 'html'))->toContain('Related file<');
});

it('previews the actions picking what they change the items to as applying to none without anything to pick', function (string $list, string $action): void {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1])]);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);
	ElementQueries::respond(User::class, [new User(['id' => 2, 'username' => 'editor'])]);
	$assets = $this->mockComponent('assets', Assets::class);
	$assets->shouldReceive('findFolders')->andReturn([]);
	/** @var ImageTransforms&MockInterface $transforms */
	$transforms = Mockery::mock(ImageTransforms::class)->makePartial();
	$transforms->shouldReceive('getAllTransforms')->andReturn([]);
	Craft::$app->set('imageTransforms', $transforms);
	mocked(Craft::$app->getElements())->shouldReceive('canDelete')->andReturn(true);
	mocked(Craft::$app->getElements())->shouldReceive('canView')->andReturn(true);
	Craft::$app->edition = CmsEdition::Pro;
	$this->session["hub:bulk:selection:{$list}:hub/test"] = [1, 2];

	$preview = bulkPost($this, 'preview', ['list' => $list, 'bulkAction' => $action])->data;

	expect(at($preview, 'total'))->toBe(0)
		->and(at($preview, 'html'))->toContain('Nothing to');
})->with([
	'authors' => [ColumnService::LIST_ENTRIES, BulkActions::ACTION_CHANGE_AUTHOR],
	'sections' => [ColumnService::LIST_ENTRIES, BulkActions::ACTION_MOVE_TO_SECTION],
	'folders' => [ColumnService::LIST_FILES, BulkActions::ACTION_MOVE_TO_FOLDER],
	'transforms' => [ColumnService::LIST_FILES, BulkActions::ACTION_GENERATE_TRANSFORMS],
	'groups' => [ColumnService::LIST_USERS, BulkActions::ACTION_GROUPS],
]);
