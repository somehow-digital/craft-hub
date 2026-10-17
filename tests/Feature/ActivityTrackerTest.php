<?php

use craft\auth\methods\RecoveryCodes;
use craft\auth\methods\TOTP;
use craft\behaviors\DraftBehavior;
use craft\controllers\AssetIndexesController;
use craft\controllers\AuthController;
use craft\controllers\ElementIndexesController;
use craft\controllers\UtilitiesController;
use craft\behaviors\RevisionBehavior;
use craft\controllers\UsersController;
use craft\elements\actions\DownloadAssetFile;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\exporters\Raw;
use craft\elements\User;
use craft\events\ConfigEvent;
use craft\events\ElementActionEvent;
use craft\events\ElementEvent;
use craft\events\LoginFailureEvent;
use craft\events\MergeElementsEvent;
use craft\events\MoveElementEvent;
use craft\events\PluginEvent;
use craft\events\SectionEvent;
use craft\events\UserEvent;
use craft\events\UserGroupsAssignEvent;
use craft\events\UserPermissionsEvent;
use craft\mail\Mailer;
use craft\mail\Message;
use craft\models\Section;
use craft\models\UserGroup;
use craft\services\Elements;
use craft\services\Entries;
use craft\services\Plugins;
use craft\services\ProjectConfig;
use craft\services\Structures;
use craft\services\UserPermissions;
use craft\services\Users;
use craft\utilities\AssetIndexes;
use craft\utilities\ClearCaches;
use craft\utilities\FindAndReplace;
use craft\utilities\Migrations;
use craft\web\User as WebUser;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\base\Action;
use yii\base\ActionEvent;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;
use yii\web\UserEvent as WebUserEvent;

beforeEach(function () {
	$this->createContentModel();
	$this->activity = $this->mockComponent('hub.activity', ActivityService::class);

	// Records the activities, as the arguments they're recorded with.
	$this->recorded = [];
	$this->activity->shouldReceive('record')->andReturnUsing(function (...$args) {
		$this->recorded[] = $args;
	})->byDefault();

	$this->saved = fn (object $element, bool $isNew = false) => Event::trigger(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, new ElementEvent(['element' => $element, 'isNew' => $isNew]));
});

it('records elements created, as new ones or unpublished drafts saved for the first time', function () {
	($this->saved)(new Entry(['id' => 5, 'sectionId' => 1, 'typeId' => 10, 'siteId' => 1, 'title' => 'Hello']), true);
	($this->saved)(new Entry(['id' => 6, 'sectionId' => 1, 'typeId' => 10, 'siteId' => 1, 'title' => 'Draft', 'firstSave' => true]));

	expect($this->recorded)->toBe([
		[ActivityAction::Created, ActivitySubject::Entry, 5, 'Hello', [], 1],
		[ActivityAction::Created, ActivitySubject::Entry, 6, 'Draft', [], 1],
	]);
});

it('records elements updated with what changed, leaving out what Craft sets', function () {
	$entry = new Entry(['id' => 5, 'sectionId' => 1, 'typeId' => 10, 'siteId' => 1, 'title' => 'Hello']);
	$entry->setDirtyAttributes(['title', 'dateUpdated', 'uri']);
	$entry->setDirtyFields(['body']);

	($this->saved)($entry);

	expect($this->recorded)->toBe([
		[ActivityAction::Updated, ActivitySubject::Entry, 5, 'Hello', ['attributes' => ['title'], 'fields' => ['body']], 1],
	]);
});

it('records drafts applied by their name, and revisions reverted to by their number', function () {
	$draft = new Entry(['id' => 8]);
	$draft->attachBehavior('draft', new DraftBehavior(['draftName' => 'Spring update']));
	$revision = new Entry(['id' => 9]);
	$revision->attachBehavior('revision', new RevisionBehavior(['revisionNum' => 3]));

	($this->saved)(new Entry(['id' => 5, 'sectionId' => 1, 'typeId' => 10, 'siteId' => 1, 'title' => 'Hello', 'updatingFromDerivative' => true, 'duplicateOf' => $draft]));
	($this->saved)(new Entry(['id' => 5, 'sectionId' => 1, 'typeId' => 10, 'siteId' => 1, 'title' => 'Hello', 'updatingFromDerivative' => true, 'duplicateOf' => $revision]));

	expect($this->recorded)->toBe([
		[ActivityAction::Updated, ActivitySubject::Entry, 5, 'Hello', ['draft' => 'Spring update'], 1],
		[ActivityAction::Reverted, ActivitySubject::Entry, 5, 'Hello', ['revision' => 3], 1],
	]);
});

it('records no drafts, revisions, nested elements, or elements resaved or propagated', function () {
	($this->saved)(new Entry(['id' => 5, 'draftId' => 2]));
	($this->saved)(new Entry(['id' => 6, 'canonicalId' => 5, 'draftId' => 2]));
	($this->saved)(new Entry(['id' => 5, 'revisionId' => 2]));
	($this->saved)(new Entry(['id' => 6, 'fieldId' => 3, 'primaryOwnerId' => 5]));
	($this->saved)(new Entry(['id' => 5, 'resaving' => true]));
	($this->saved)(new Entry(['id' => 5, 'propagating' => true]));

	expect($this->recorded)->toBe([]);
});

it('records elements deleted, for good or not, and restored', function () {
	Event::trigger(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, new ElementEvent(['element' => new Asset(['id' => 7, 'volumeId' => 1, 'siteId' => 1, 'title' => 'Photo'])]));
	Event::trigger(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, new ElementEvent(['element' => new Asset(['id' => 8, 'volumeId' => 1, 'siteId' => 1, 'title' => 'Logo', 'hardDelete' => true])]));
	Event::trigger(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, new ElementEvent(['element' => new Asset(['id' => 7, 'volumeId' => 1, 'siteId' => 1, 'title' => 'Photo'])]));
	Event::trigger(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, new ElementEvent(['element' => new Entry(['id' => 9, 'draftId' => 1])]));

	expect($this->recorded)->toBe([
		[ActivityAction::Deleted, ActivitySubject::Asset, 7, 'Photo', [], 1],
		[ActivityAction::Deleted, ActivitySubject::Asset, 8, 'Logo', ['hardDelete' => true], 1],
		[ActivityAction::Restored, ActivitySubject::Asset, 7, 'Photo', [], 1],
	]);
});

it('records sign-ins, but not those continuing a session by its cookie, and sign-outs', function () {
	$user = new User(['id' => 4, 'username' => 'jane', 'fullName' => 'Jane Doe']);

	Event::trigger(WebUser::class, WebUser::EVENT_AFTER_LOGIN, new WebUserEvent(['identity' => $user]));
	Event::trigger(WebUser::class, WebUser::EVENT_AFTER_LOGIN, new WebUserEvent(['identity' => $user, 'cookieBased' => true]));
	Event::trigger(WebUser::class, WebUser::EVENT_BEFORE_LOGOUT, new WebUserEvent(['identity' => $user]));

	expect($this->recorded)->toBe([
		[ActivityAction::SignedIn, ActivitySubject::User, 4, 'Jane Doe', [], null, $user],
		[ActivityAction::SignedOut, ActivitySubject::User, 4, 'Jane Doe', [], null, $user],
	]);
});

it('records impersonations as done by the impersonating user, rather than as sign-ins', function () {
	$session = Mockery::mock(WebUser::class);
	$session->shouldReceive('getImpersonatorId')->andReturn(1);
	$admin = new User(['id' => 1, 'username' => 'admin']);
	Craft::$app->getUsers()->shouldReceive('getUserById')->with(1)->andReturn($admin);

	Event::trigger(WebUser::class, WebUser::EVENT_AFTER_LOGIN, new WebUserEvent(['sender' => $session, 'identity' => new User(['id' => 4, 'username' => 'jane'])]));

	expect($this->recorded)->toBe([
		[ActivityAction::Impersonated, ActivitySubject::User, 4, 'jane', [], null, $admin],
	]);
});

it('records failed sign-ins without a user, by the name tried for unknown users', function () {
	$this->request();
	Craft::$app->getRequest()->setBodyParams(['loginName' => 'mallory']);

	Event::trigger(UsersController::class, UsersController::EVENT_LOGIN_FAILURE, new LoginFailureEvent(['authError' => User::AUTH_INVALID_CREDENTIALS, 'message' => 'Invalid']));
	Event::trigger(UsersController::class, UsersController::EVENT_LOGIN_FAILURE, new LoginFailureEvent(['message' => 'Invalid', 'user' => new User(['id' => 4, 'username' => 'jane'])]));

	expect($this->recorded)->toBe([
		[ActivityAction::SignInFailed, ActivitySubject::User, null, 'mallory', ['reason' => User::AUTH_INVALID_CREDENTIALS], null, null, true],
		[ActivityAction::SignInFailed, ActivitySubject::User, 4, 'jane', [], null, null, true],
	]);
});

it('records what happens to user accounts', function () {
	$user = new User(['id' => 4, 'username' => 'jane']);

	Event::trigger(Users::class, Users::EVENT_AFTER_SUSPEND_USER, new UserEvent(['user' => $user]));
	Event::trigger(Users::class, Users::EVENT_AFTER_UNLOCK_USER, new UserEvent(['user' => $user]));

	expect($this->recorded)->toBe([
		[ActivityAction::Suspended, ActivitySubject::User, 4, 'jane', [], null, null],
		[ActivityAction::Unlocked, ActivitySubject::User, 4, 'jane', [], null, null],
	]);
});

it('records the groups added to and removed from users by their names', function () {
	$groups = [1 => new UserGroup(['id' => 1, 'name' => 'Editors']), 2 => new UserGroup(['id' => 2, 'name' => 'Authors'])];
	Craft::$app->getUserGroups()->shouldReceive('getGroupById')->andReturnUsing(fn (int $id) => $groups[$id] ?? null);
	Craft::$app->getUsers()->shouldReceive('getUserById')->with(4)->andReturn(new User(['id' => 4, 'username' => 'jane']));

	Event::trigger(Users::class, Users::EVENT_AFTER_ASSIGN_USER_TO_GROUPS, new UserGroupsAssignEvent([
		'userId' => 4,
		'groupIds' => [1],
		'newGroupIds' => [1],
		'removedGroupIds' => [2],
	]));

	expect($this->recorded)->toBe([
		[ActivityAction::GroupsChanged, ActivitySubject::User, 4, 'jane', ['added' => ['Editors'], 'removed' => ['Authors']]],
	]);
});

it('records the permissions of users only when they changed', function () {
	$this->activity->shouldReceive('getUserPermissions')->with(4)->andReturn(['accesscp', 'editentries']);
	Craft::$app->getUsers()->shouldReceive('getUserById')->with(4)->andReturn(new User(['id' => 4, 'username' => 'jane']));
	$save = function (array $permissions) {
		Event::trigger(UsersController::class, UsersController::EVENT_BEFORE_ASSIGN_GROUPS_AND_PERMISSIONS, new UserEvent(['user' => new User(['id' => 4])]));
		Event::trigger(UserPermissions::class, UserPermissions::EVENT_AFTER_SAVE_USER_PERMISSIONS, new UserPermissionsEvent(['userId' => 4, 'permissions' => $permissions]));
	};

	$save(['accesscp', 'editentries']);
	$save(['accesscp', 'deleteentries']);

	expect($this->recorded)->toBe([
		[ActivityAction::PermissionsChanged, ActivitySubject::User, 4, 'jane', ['granted' => ['deleteentries'], 'revoked' => ['editentries']]],
	]);
});

it('records the things holding the content created, updated and deleted', function () {
	$section = new Section(['id' => 2, 'name' => 'News', 'handle' => 'news']);

	Event::trigger(Entries::class, Entries::EVENT_AFTER_SAVE_SECTION, new SectionEvent(['section' => $section, 'isNew' => true]));
	Event::trigger(Entries::class, Entries::EVENT_AFTER_SAVE_SECTION, new SectionEvent(['section' => $section]));
	Event::trigger(Entries::class, Entries::EVENT_AFTER_DELETE_SECTION, new SectionEvent(['section' => $section]));

	expect($this->recorded)->toBe([
		[ActivityAction::Created, ActivitySubject::Section, 2, 'News'],
		[ActivityAction::Updated, ActivitySubject::Section, 2, 'News'],
		[ActivityAction::Deleted, ActivitySubject::Section, 2, 'News'],
	]);
});

it('records plugins installed, by their name and handle', function () {
	$this->hub()->name = 'Hub';

	Event::trigger(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, new PluginEvent(['plugin' => $this->hub()]));

	expect($this->recorded)->toBe([
		[ActivityAction::Installed, ActivitySubject::Plugin, null, 'Hub', ['handle' => 'hub']],
	]);
});

it('records changes applied from the project config files as a batch', function () {
	$this->mockComponent('projectConfig', ProjectConfig::class)->shouldReceive('getIsApplyingExternalChanges')->andReturn(true);
	$this->activity->shouldReceive('beginBatch')->once()->with(ActivityAction::Applied, ActivitySubject::ProjectConfig, 'Project Config');
	$this->activity->shouldReceive('endBatch')->once();

	Event::trigger(Entries::class, Entries::EVENT_AFTER_SAVE_SECTION, new SectionEvent(['section' => new Section(['id' => 2, 'name' => 'News'])]));
	Event::trigger(Plugins::class, Plugins::EVENT_AFTER_ENABLE_PLUGIN, new PluginEvent(['plugin' => $this->hub()]));
	Event::trigger(ProjectConfig::class, ProjectConfig::EVENT_AFTER_APPLY_CHANGES);

	expect(array_column($this->recorded, 0))->toBe([ActivityAction::Updated, ActivityAction::Enabled]);
});

it('records users made admins and their passwords changed on their own', function () {
	$user = new User(['id' => 4, 'username' => 'jane', 'admin' => true]);
	$user->setDirtyAttributes(['admin', 'password', 'lastPasswordChangeDate']);

	($this->saved)($user);

	expect(array_column($this->recorded, 0))->toBe([ActivityAction::AdminGranted, ActivityAction::PasswordChanged]);
});

it('records elements moved in their structure, but not along with being saved', function () {
	$entry = new Entry(['id' => 5, 'sectionId' => 1, 'typeId' => 10, 'siteId' => 1, 'title' => 'Hello']);
	$move = fn () => Event::trigger(Structures::class, Structures::EVENT_AFTER_MOVE_ELEMENT, new MoveElementEvent(['element' => $entry, 'structureId' => 1, 'action' => Structures::ACTION_APPEND]));

	$move();
	Event::trigger(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, new ElementEvent(['element' => $entry]));
	$move();

	expect($this->recorded)->toBe([
		[ActivityAction::Moved, ActivitySubject::Entry, 5, 'Hello', ['position' => 'append'], 1],
	]);
});

it('records the permissions of user groups and other project config changes without events of their own', function () {
	Craft::$app->getUserGroups()->shouldReceive('getGroupByUid')->with('g1')->andReturn(new UserGroup(['id' => 3, 'name' => 'Editors']));
	$change = fn (string $name, string $path, mixed $old, mixed $new) => Event::trigger(ProjectConfig::class, $name, new ConfigEvent(['path' => $path, 'oldValue' => $old, 'newValue' => $new]));

	$change(ProjectConfig::EVENT_UPDATE_ITEM, 'users.groups.g1.permissions', ['accesscp'], ['accesscp', 'editentries']);
	$change(ProjectConfig::EVENT_ADD_ITEM, 'fs.images', null, ['name' => 'Images']);
	$change(ProjectConfig::EVENT_UPDATE_ITEM, 'system', ['name' => 'Old', 'live' => true, 'schemaVersion' => '1'], ['name' => 'Old', 'live' => false, 'schemaVersion' => '2']);
	$change(ProjectConfig::EVENT_UPDATE_ITEM, 'sections.abc', [], ['name' => 'News']);

	expect($this->recorded)->toBe([
		[ActivityAction::PermissionsChanged, ActivitySubject::UserGroup, 3, 'Editors', ['granted' => ['editentries']]],
		[ActivityAction::Created, ActivitySubject::Filesystem, null, 'Images'],
		[ActivityAction::Updated, ActivitySubject::Settings, null, 'General Settings', ['attributes' => ['live']]],
	]);
});

it('records element actions as a batch', function () {
	$this->activity->shouldReceive('beginBatch')->once()->with(ActivityAction::Bulk, ActivitySubject::Entry, 'Disable');
	$this->activity->shouldReceive('endBatch')->once();
	$action = Mockery::mock(craft\base\ElementActionInterface::class);
	$action->shouldReceive('getTriggerLabel')->andReturn('Disable');
	$event = new ElementActionEvent(['action' => $action, 'criteria' => Entry::find()]);

	Event::trigger(Elements::class, Elements::EVENT_BEFORE_PERFORM_ACTION, $event);
	Event::trigger(Elements::class, Elements::EVENT_AFTER_PERFORM_ACTION, $event);
});

it('records password resets requested, by the address mailed to for unknown users', function () {
	Craft::$app->getUsers()->shouldReceive('getUserByUsernameOrEmail')->with('jane@example.test')->andReturn(new User(['id' => 4, 'username' => 'jane']));
	Craft::$app->getUsers()->shouldReceive('getUserByUsernameOrEmail')->with('nobody@example.test')->andReturn(null);
	$sent = fn (string $key, string $to, bool $isSuccessful = true) => Event::trigger(Mailer::class, BaseMailer::EVENT_AFTER_SEND, new MailEvent([
		'message' => (new Message(['key' => $key]))->setTo($to),
		'isSuccessful' => $isSuccessful,
	]));

	$sent('forgot_password', 'jane@example.test');
	$sent('forgot_password', 'nobody@example.test');
	$sent('forgot_password', 'jane@example.test', false);
	$sent('account_activation', 'jane@example.test');

	expect($this->recorded)->toBe([
		[ActivityAction::PasswordResetRequested, ActivitySubject::User, 4, 'jane'],
		[ActivityAction::PasswordResetRequested, ActivitySubject::User, null, 'nobody@example.test'],
	]);
});

it('records sign-in methods removed and recovery codes generated by the signed-in user', function () {
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 4, 'username' => 'jane']));
	Craft::$app->getUsers()->shouldReceive('getUserById')->with(4)->andReturn(new User(['id' => 4, 'username' => 'jane']));
	$controller = new AuthController('auth', Craft::$app);
	$performed = function (string $id, array $body = []) use ($controller) {
		Craft::$app->getRequest()->setBodyParams($body);
		Event::trigger(AuthController::class, AuthController::EVENT_AFTER_ACTION, new ActionEvent(new Action($id, $controller)));
	};

	$performed('remove-method', ['method' => TOTP::class]);
	$performed('remove-method', ['method' => User::class]);
	$performed('generate-recovery-codes');
	$performed('verify-totp');

	expect($this->recorded)->toBe([
		[ActivityAction::AuthRemoved, ActivitySubject::User, 4, 'jane', ['method' => TOTP::displayName()]],
		[ActivityAction::AuthAdded, ActivitySubject::User, 4, 'jane', ['method' => RecoveryCodes::displayName()]],
	]);
});

it('records utilities run, unless they failed or changed nothing', function () {
	$controller = new UtilitiesController('utilities', Craft::$app);
	$performed = function (string $id, array $body = []) use ($controller) {
		Craft::$app->getRequest()->setBodyParams($body);
		Event::trigger(UtilitiesController::class, UtilitiesController::EVENT_AFTER_ACTION, new ActionEvent(new Action($id, $controller)));
	};

	$performed('clear-caches-perform-action', ['caches' => ['data']]);
	$performed('invalidate-tags', ['tags' => ['template']]);
	$performed('find-and-replace-perform-action', ['params' => ['find' => 'foo', 'replace' => 'bar']]);
	$performed('find-and-replace-perform-action', ['params' => ['find' => 'foo', 'replace' => '']]);
	$performed('apply-new-migrations');
	$performed('view');

	Craft::$app->getResponse()->setStatusCode(500);
	$performed('apply-new-migrations');

	expect($this->recorded)->toBe([
		[ActivityAction::Cleared, ActivitySubject::Utility, null, ClearCaches::displayName(), ['caches' => ['data']]],
		[ActivityAction::Cleared, ActivitySubject::Utility, null, ClearCaches::displayName(), ['tags' => ['template']]],
		[ActivityAction::Ran, ActivitySubject::Utility, null, FindAndReplace::displayName(), ['find' => 'foo', 'replace' => 'bar']],
		[ActivityAction::Ran, ActivitySubject::Utility, null, Migrations::displayName(), []],
	]);
});

it('records asset indexing started, by the names of the volumes indexed', function () {
	$controller = new AssetIndexesController('asset-indexes', Craft::$app);
	$performed = function (string $id, array $body = []) use ($controller) {
		Craft::$app->getRequest()->setBodyParams($body);
		Event::trigger(AssetIndexesController::class, AssetIndexesController::EVENT_AFTER_ACTION, new ActionEvent(new Action($id, $controller)));
	};

	$performed('start-indexing', ['volumes' => ['1', '99']]);
	$performed('start-indexing');
	$performed('process-indexing-session', ['volumes' => ['1']]);

	expect($this->recorded)->toBe([
		[ActivityAction::Ran, ActivitySubject::Utility, null, AssetIndexes::displayName(), ['volumes' => ['Images']]],
		[ActivityAction::Ran, ActivitySubject::Utility, null, AssetIndexes::displayName(), []],
	]);
});

it('records elements of other types by their type', function () {
	($this->saved)(new Address(['id' => 7, 'siteId' => 1, 'title' => 'Home']), true);

	expect($this->recorded)->toBe([
		[ActivityAction::Created, ActivitySubject::Element, 7, 'Home', ['type' => 'Address'], 1],
	]);
});

it('records the element others were merged into', function () {
	Craft::$app->getElements()->shouldReceive('getElementById')->with(5)->andReturn(new Entry(['id' => 5, 'sectionId' => 1, 'typeId' => 10, 'siteId' => 1, 'title' => 'Hello']));
	Craft::$app->getElements()->shouldReceive('getElementById')->with(99)->andReturn(null);

	Event::trigger(Elements::class, Elements::EVENT_AFTER_MERGE_ELEMENTS, new MergeElementsEvent(['mergedElementId' => 6, 'prevailingElementId' => 5]));
	Event::trigger(Elements::class, Elements::EVENT_AFTER_MERGE_ELEMENTS, new MergeElementsEvent(['mergedElementId' => 6, 'prevailingElementId' => 99]));

	expect($this->recorded)->toBe([
		[ActivityAction::Merged, ActivitySubject::Entry, 5, 'Hello', ['merged' => 6], 1],
	]);
});

it('records drafts deleted on purpose, but not those applied or provisional', function () {
	$this->hub()->getSettings()->activityDrafts = true;
	$deleted = function (bool $provisional = false) {
		$draft = new Entry(['id' => 8, 'canonicalId' => 5, 'draftId' => 2, 'sectionId' => 1, 'typeId' => 10, 'siteId' => 1, 'title' => 'Hello', 'isProvisionalDraft' => $provisional]);
		$draft->attachBehavior('draft', new DraftBehavior(['draftName' => 'Spring update']));
		Event::trigger(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, new ElementEvent(['element' => $draft]));
	};

	$deleted();
	$deleted(provisional: true);

	expect($this->recorded)->toBe([
		[ActivityAction::DraftDeleted, ActivitySubject::Entry, 5, 'Hello', ['draftName' => 'Spring update'], 1],
	]);
});

it('records element actions performed by others within the batch of the first', function () {
	$this->activity->shouldReceive('beginBatch')->once()->with(ActivityAction::Bulk, ActivitySubject::Entry, 'Disable');
	$this->activity->shouldReceive('endBatch')->once();
	$action = Mockery::mock(craft\base\ElementActionInterface::class);
	$action->shouldReceive('getTriggerLabel')->andReturn('Disable');
	$event = new ElementActionEvent(['action' => $action, 'criteria' => Entry::find()]);

	Event::trigger(Elements::class, Elements::EVENT_BEFORE_PERFORM_ACTION, $event);
	Event::trigger(Elements::class, Elements::EVENT_BEFORE_PERFORM_ACTION, $event);
	Event::trigger(Elements::class, Elements::EVENT_AFTER_PERFORM_ACTION, $event);
});

it('records the files downloaded by the element action', function () {
	$this->activity->shouldReceive('beginBatch')->once();
	$this->activity->shouldReceive('endBatch')->once();
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'title' => 'Photo', 'filename' => 'photo.jpg'])]);
	$event = new ElementActionEvent(['action' => new DownloadAssetFile(), 'criteria' => Asset::find()]);

	Event::trigger(Elements::class, Elements::EVENT_BEFORE_PERFORM_ACTION, $event);
	Event::trigger(Elements::class, Elements::EVENT_AFTER_PERFORM_ACTION, $event);

	expect($this->recorded)->toBe([
		[ActivityAction::Downloaded, ActivitySubject::Asset, 1, 'Photo', [], 1],
	]);
});

it('ends the batch of element actions that failed along with the request', function () {
	$this->activity->shouldReceive('beginBatch')->once();
	$this->activity->shouldReceive('endBatch')->once();
	$action = Mockery::mock(craft\base\ElementActionInterface::class);
	$action->shouldReceive('getTriggerLabel')->andReturn('Disable');
	$controller = new ElementIndexesController('element-indexes', Craft::$app);

	Event::trigger(Elements::class, Elements::EVENT_BEFORE_PERFORM_ACTION, new ElementActionEvent(['action' => $action, 'criteria' => Entry::find()]));
	Event::trigger(ElementIndexesController::class, ElementIndexesController::EVENT_AFTER_ACTION, new yii\base\ActionEvent(new yii\base\Action('perform-action', $controller)));
	Event::trigger(ElementIndexesController::class, ElementIndexesController::EVENT_AFTER_ACTION, new yii\base\ActionEvent(new yii\base\Action('perform-action', $controller)));
});

it('records exports by how many elements they held, in which format', function () {
	// Element queries aren't counted by the database here.
	$query = new class(Entry::class) extends EntryQuery {
		public function count($q = '*', $db = null): bool|int|string|null
		{
			return 2;
		}
	};
	$controller = Mockery::mock(ElementIndexesController::class)->makePartial();
	$controller->shouldReceive('getElementQuery')->andReturn($query);
	$exported = function (string $id) use ($controller) {
		$event = new yii\base\ActionEvent(new yii\base\Action($id, $controller));
		$event->sender = $controller;
		Event::trigger(ElementIndexesController::class, ElementIndexesController::EVENT_AFTER_ACTION, $event);
	};
	Craft::$app->getRequest()->setBodyParams(['type' => Raw::class, 'format' => 'csv']);

	$exported('export');
	$exported('get-elements');

	expect($this->recorded)->toBe([
		[ActivityAction::Exported, ActivitySubject::Entry, null, null, ['count' => 2, 'exporter' => Raw::displayName(), 'format' => 'csv']],
	]);
});

it('records no sign-in methods changed without a signed-in user', function () {
	Craft::$app->getRequest()->setBodyParams(['method' => TOTP::class]);

	Event::trigger(AuthController::class, AuthController::EVENT_AFTER_ACTION, new yii\base\ActionEvent(new yii\base\Action('remove-method', new AuthController('auth', Craft::$app))));

	expect($this->recorded)->toBe([]);
});

it('records Craft and plugins updated by their schema versions', function () {
	$this->mockComponent('plugins', Plugins::class)->shouldReceive('getPlugin')->with('seo')->andReturn(null);
	$change = fn (string $path) => Event::trigger(ProjectConfig::class, ProjectConfig::EVENT_UPDATE_ITEM, new ConfigEvent(['path' => $path, 'oldValue' => '1', 'newValue' => '2']));

	$change(ProjectConfig::PATH_SCHEMA_VERSION);
	$change('plugins.seo.schemaVersion');

	expect($this->recorded)->toBe([
		[ActivityAction::Updated, ActivitySubject::System, null, 'Craft CMS', ['version' => Craft::$app->getVersion()]],
		[ActivityAction::Updated, ActivitySubject::Plugin, null, 'seo', ['handle' => 'seo']],
	]);
});

it('records changes within project config items and settings as updates of them, once while applied', function () {
	$projectConfig = $this->mockComponent('projectConfig', ProjectConfig::class);
	$projectConfig->shouldReceive('getIsApplyingExternalChanges')->andReturn(true);
	$projectConfig->shouldReceive('get')->with('routes.r1')->andReturn(['uriParts' => ['blog/', ['slug', '[^/]+']], 'template' => 'blog/_entry']);
	$this->activity->shouldReceive('beginBatch')->once();
	$change = fn (string $path, mixed $new = null) => Event::trigger(ProjectConfig::class, ProjectConfig::EVENT_UPDATE_ITEM, new ConfigEvent(['path' => $path, 'oldValue' => null, 'newValue' => $new]));

	$change('routes.r1.template', 'blog/_entry');
	$change('routes.r1.uriParts', []);
	$change('email.fromEmail', 'hello@example.test');
	$change('email.fromName', 'Hello');
	$change('users.groups.g1.name', 'Editors');
	$change('routes.r2', ['uriParts' => []]);

	expect($this->recorded)->toBe([
		[ActivityAction::Updated, ActivitySubject::Route, null, 'blog/<slug>'],
		[ActivityAction::Updated, ActivitySubject::Settings, null, 'Email Settings', ['attributes' => ['fromEmail']]],
		[ActivityAction::Updated, ActivitySubject::Route, null, null],
	]);
});
