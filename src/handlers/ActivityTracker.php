<?php

namespace SomehowDigital\Craft\Hub\handlers;

use Craft;
use craft\auth\methods\AuthMethodInterface;
use craft\auth\methods\RecoveryCodes;
use craft\auth\methods\TOTP;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\base\PluginInterface;
use craft\behaviors\DraftBehavior;
use craft\behaviors\RevisionBehavior;
use craft\controllers\AssetIndexesController;
use craft\controllers\AuthController;
use craft\controllers\ElementIndexesController;
use craft\controllers\UsersController;
use craft\controllers\UtilitiesController;
use craft\db\Connection;
use craft\elements\actions\DownloadAssetFile;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use craft\events\BackupEvent;
use craft\events\ConfigEvent;
use craft\events\DraftEvent;
use craft\events\ElementActionEvent;
use craft\events\ElementEvent;
use craft\events\LoginFailureEvent;
use craft\events\MergeElementsEvent;
use craft\events\MoveElementEvent;
use craft\events\PluginEvent;
use craft\events\ReplaceAssetEvent;
use craft\events\UserEvent;
use craft\events\UserGroupsAssignEvent;
use craft\events\UserPermissionsEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\mail\Mailer;
use craft\mail\Message;
use craft\records\Authenticator as AuthenticatorRecord;
use craft\records\GqlToken as GqlTokenRecord;
use craft\records\WebAuthn as WebAuthnRecord;
use craft\services\Assets;
use craft\services\Categories;
use craft\services\Drafts;
use craft\services\Elements;
use craft\services\Entries;
use craft\services\Fields;
use craft\services\ImageTransforms;
use craft\services\Plugins;
use craft\services\ProjectConfig;
use craft\services\Sites;
use craft\services\Structures;
use craft\services\Tags;
use craft\services\UserGroups;
use craft\services\UserPermissions;
use craft\services\Users;
use craft\services\Volumes;
use craft\utilities\AssetIndexes;
use craft\utilities\ClearCaches;
use craft\utilities\FindAndReplace;
use craft\utilities\Migrations;
use craft\web\User as WebUser;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ActivityService;
use WeakMap;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;
use yii\web\UserEvent as WebUserEvent;

/**
 * Records what users do in the activity log, as Craft's own events tell it, whether it's done in the control panel, on
 * the site or on the command line.
 *
 * Only what users would call a change is recorded: drafts and revisions are left out until they're applied or reverted
 * to, unless drafts are recorded too (see Settings::$activityDrafts), as are nested elements, which are saved along with
 * their owners, and elements saved again or propagated to other sites. Element actions are recorded as a batch of what
 * they changed, as are project config changes applied from the YAML files.
 */
class ActivityTracker
{
	/** attributes Craft sets as elements are saved, which aren't changes users made */
	private const IGNORED_ATTRIBUTES = [
		'id', 'uid', 'canonicalId', 'draftId', 'revisionId', 'isProvisionalDraft', 'siteSettingsId', 'fieldLayoutId',
		'structureId', 'root', 'lft', 'rgt', 'level', 'uri', 'dateCreated', 'dateUpdated', 'dateLastMerged',
	];

	/** attributes of users that are secrets, set along with others, or recorded on their own */
	private const IGNORED_USER_ATTRIBUTES = [
		'admin', 'password', 'lastPasswordChangeDate', 'invalidLoginWindowStart', 'invalidLoginCount', 'verificationCode',
		'verificationCodeIssuedDate',
	];

	/** attributes of records Craft sets as they're used or saved */
	private const IGNORED_RECORD_ATTRIBUTES = ['id', 'uid', 'dateCreated', 'dateUpdated', 'lastUsed', 'dateLastUsed'];

	/** events of the things holding the content, as the service, the save and delete events, the event's property and the kind */
	private const CONFIG_EVENTS = [
		[Entries::class, Entries::EVENT_AFTER_SAVE_SECTION, Entries::EVENT_AFTER_DELETE_SECTION, 'section', ActivitySubject::Section],
		[Entries::class, Entries::EVENT_AFTER_SAVE_ENTRY_TYPE, Entries::EVENT_AFTER_DELETE_ENTRY_TYPE, 'entryType', ActivitySubject::EntryType],
		[Fields::class, Fields::EVENT_AFTER_SAVE_FIELD, Fields::EVENT_AFTER_DELETE_FIELD, 'field', ActivitySubject::Field],
		[Volumes::class, Volumes::EVENT_AFTER_SAVE_VOLUME, Volumes::EVENT_AFTER_DELETE_VOLUME, 'volume', ActivitySubject::Volume],
		[UserGroups::class, UserGroups::EVENT_AFTER_SAVE_USER_GROUP, UserGroups::EVENT_AFTER_DELETE_USER_GROUP, 'userGroup', ActivitySubject::UserGroup],
		[Sites::class, Sites::EVENT_AFTER_SAVE_SITE, Sites::EVENT_AFTER_DELETE_SITE, 'site', ActivitySubject::Site],
		[Sites::class, Sites::EVENT_AFTER_SAVE_SITE_GROUP, Sites::EVENT_AFTER_DELETE_SITE_GROUP, 'group', ActivitySubject::SiteGroup],
		[Categories::class, Categories::EVENT_AFTER_SAVE_GROUP, Categories::EVENT_AFTER_DELETE_GROUP, 'categoryGroup', ActivitySubject::CategoryGroup],
		[Tags::class, Tags::EVENT_AFTER_SAVE_GROUP, Tags::EVENT_AFTER_DELETE_GROUP, 'tagGroup', ActivitySubject::TagGroup],
		[ImageTransforms::class, ImageTransforms::EVENT_AFTER_SAVE_IMAGE_TRANSFORM, ImageTransforms::EVENT_AFTER_DELETE_IMAGE_TRANSFORM, 'imageTransform', ActivitySubject::ImageTransform],
	];

	/** project config items without events of their own, as the path holding them and their kind */
	private const CONFIG_ITEMS = [
		ProjectConfig::PATH_FS => ActivitySubject::Filesystem,
		ProjectConfig::PATH_ROUTES => ActivitySubject::Route,
		ProjectConfig::PATH_GRAPHQL_SCHEMAS => ActivitySubject::GqlSchema,
	];

	/** project config paths of the system's settings, by the name of their settings page */
	private const CONFIG_SETTINGS = [
		ProjectConfig::PATH_SYSTEM => 'General Settings',
		'email' => 'Email Settings',
		ProjectConfig::PATH_USERS => 'User Settings',
		ProjectConfig::PATH_ADDRESSES => 'Addresses',
	];

	/** settings within the paths of the system's settings that are recorded otherwise */
	private const IGNORED_SETTINGS = ['schemaVersion', 'groups'];

	/** events of the users' accounts, by the action they are */
	private const USER_EVENTS = [
		Users::EVENT_AFTER_ACTIVATE_USER => ActivityAction::Activated,
		Users::EVENT_AFTER_DEACTIVATE_USER => ActivityAction::Deactivated,
		Users::EVENT_AFTER_SUSPEND_USER => ActivityAction::Suspended,
		Users::EVENT_AFTER_UNSUSPEND_USER => ActivityAction::Unsuspended,
		Users::EVENT_AFTER_LOCK_USER => ActivityAction::Locked,
		Users::EVENT_AFTER_UNLOCK_USER => ActivityAction::Unlocked,
	];

	/** events of the plugins, by the action they are */
	private const PLUGIN_EVENTS = [
		Plugins::EVENT_AFTER_INSTALL_PLUGIN => ActivityAction::Installed,
		Plugins::EVENT_AFTER_UNINSTALL_PLUGIN => ActivityAction::Uninstalled,
		Plugins::EVENT_AFTER_ENABLE_PLUGIN => ActivityAction::Enabled,
		Plugins::EVENT_AFTER_DISABLE_PLUGIN => ActivityAction::Disabled,
		Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS => ActivityAction::Updated,
	];

	/** project config events, by the action they are */
	private const PROJECT_CONFIG_EVENTS = [
		ProjectConfig::EVENT_ADD_ITEM => ActivityAction::Created,
		ProjectConfig::EVENT_UPDATE_ITEM => ActivityAction::Updated,
		ProjectConfig::EVENT_REMOVE_ITEM => ActivityAction::Deleted,
	];

	/** @var array<int,string[]> permissions granted directly to the users being saved in the control panel, by their ID */
	private array $permissions = [];

	/** @var WeakMap<ElementInterface,true> elements being saved, which are moved in their structures along with it */
	private WeakMap $saving;

	/** number of drafts being applied, which are deleted once they are */
	private int $applyingDrafts = 0;

	/** whether an element action is performed, recorded as a batch */
	private bool $performing = false;

	/** whether project config changes are applied from the YAML files, recorded as a batch */
	private bool $applying = false;

	/** @var array<string,true> things recorded while the project config is applied, which may change by several paths */
	private array $applied = [];

	public function __construct()
	{
		$this->saving = new WeakMap();
	}

	public function register(): void
	{
		Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, fn (ElementEvent $event) => $this->saving[$event->element] = true);
		Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, fn (ElementEvent $event) => $this->elementSaved($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, fn (ElementEvent $event) => $this->elementDeleted($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_FOR_SITE, fn (ElementEvent $event) => $this->elementDeletedForSite($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, fn (ElementEvent $event) => $this->elementRestored($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_MERGE_ELEMENTS, fn (MergeElementsEvent $event) => $this->elementsMerged($event));
		Event::on(Structures::class, Structures::EVENT_AFTER_MOVE_ELEMENT, fn (MoveElementEvent $event) => $this->elementMoved($event));
		Event::on(Assets::class, Assets::EVENT_AFTER_REPLACE_ASSET, fn (ReplaceAssetEvent $event) => $this->element(ActivityAction::Replaced, $event->asset, ['filename' => $event->filename]));

		Event::on(Elements::class, Elements::EVENT_BEFORE_PERFORM_ACTION, fn (ElementActionEvent $event) => $this->performingAction($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_PERFORM_ACTION, fn (ElementActionEvent $event) => $this->performedAction($event));
		Event::on(ElementIndexesController::class, Controller::EVENT_AFTER_ACTION, fn (ActionEvent $event) => $this->elementIndexAction($event));

		if (Hub::getInstance()->settings->activityDrafts) {
			Event::on(Drafts::class, Drafts::EVENT_AFTER_CREATE_DRAFT, fn (DraftEvent $event) => $this->draftCreated($event));
			Event::on(Drafts::class, Drafts::EVENT_BEFORE_APPLY_DRAFT, fn () => $this->applyingDrafts++);
			Event::on(Drafts::class, Drafts::EVENT_AFTER_APPLY_DRAFT, fn () => $this->applyingDrafts--);
		}

		Event::on(WebUser::class, WebUser::EVENT_AFTER_LOGIN, fn (WebUserEvent $event) => $this->signedIn($event));
		Event::on(WebUser::class, WebUser::EVENT_BEFORE_LOGOUT, fn (WebUserEvent $event) => $this->signedOut($event));
		Event::on(UsersController::class, UsersController::EVENT_LOGIN_FAILURE, fn (LoginFailureEvent $event) => $this->signInFailed($event));

		foreach (self::USER_EVENTS as $name => $action) {
			Event::on(Users::class, $name, fn (UserEvent $event) => $this->user($action, $event->user));
		}

		Event::on(Users::class, Users::EVENT_AFTER_VERIFY_EMAIL, fn (UserEvent $event) => $this->user(ActivityAction::EmailVerified, $event->user, $event->user));
		Event::on(Mailer::class, BaseMailer::EVENT_AFTER_SEND, fn (MailEvent $event) => $this->mailSent($event));

		Event::on(Users::class, Users::EVENT_AFTER_ASSIGN_USER_TO_GROUPS, fn (UserGroupsAssignEvent $event) => $this->groupsAssigned($event));
		Event::on(UsersController::class, UsersController::EVENT_BEFORE_ASSIGN_GROUPS_AND_PERMISSIONS, fn (UserEvent $event) => $this->rememberPermissions($event));
		Event::on(UserPermissions::class, UserPermissions::EVENT_AFTER_SAVE_USER_PERMISSIONS, fn (UserPermissionsEvent $event) => $this->permissionsSaved($event));

		Event::on(AuthenticatorRecord::class, BaseActiveRecord::EVENT_AFTER_INSERT, fn (AfterSaveEvent $event) => $this->authenticatorSaved($event));
		Event::on(AuthenticatorRecord::class, BaseActiveRecord::EVENT_AFTER_UPDATE, fn (AfterSaveEvent $event) => $this->authenticatorSaved($event));
		Event::on(WebAuthnRecord::class, BaseActiveRecord::EVENT_AFTER_INSERT, fn (Event $event) => $this->passkey(ActivityAction::AuthAdded, $event));
		Event::on(WebAuthnRecord::class, BaseActiveRecord::EVENT_AFTER_DELETE, fn (Event $event) => $this->passkey(ActivityAction::AuthRemoved, $event));
		Event::on(AuthController::class, Controller::EVENT_AFTER_ACTION, fn (ActionEvent $event) => $this->authAction($event));

		foreach (self::CONFIG_EVENTS as [$class, $save, $delete, $property, $subject]) {
			// Each event holds its model in a property of its own.
			Event::on($class, $save, fn (Event $event) => $this->config(ArrayHelper::getValue($event, 'isNew') ? ActivityAction::Created : ActivityAction::Updated, $subject, ArrayHelper::getValue($event, $property)));
			Event::on($class, $delete, fn (Event $event) => $this->config(ActivityAction::Deleted, $subject, ArrayHelper::getValue($event, $property)));
		}

		foreach (self::PROJECT_CONFIG_EVENTS as $name => $action) {
			Event::on(ProjectConfig::class, $name, fn (ConfigEvent $event) => $this->configChanged($action, $event));
		}

		Event::on(ProjectConfig::class, ProjectConfig::EVENT_AFTER_APPLY_CHANGES, fn () => $this->configApplied());

		Event::on(GqlTokenRecord::class, BaseActiveRecord::EVENT_AFTER_INSERT, fn (AfterSaveEvent $event) => $this->token(ActivityAction::Created, $event));
		Event::on(GqlTokenRecord::class, BaseActiveRecord::EVENT_AFTER_UPDATE, fn (AfterSaveEvent $event) => $this->token(ActivityAction::Updated, $event));
		Event::on(GqlTokenRecord::class, BaseActiveRecord::EVENT_AFTER_DELETE, fn (Event $event) => $this->token(ActivityAction::Deleted, $event));

		foreach (self::PLUGIN_EVENTS as $name => $action) {
			Event::on(Plugins::class, $name, fn (PluginEvent $event) => $this->plugin($action, $event->plugin));
		}

		Event::on(UtilitiesController::class, Controller::EVENT_AFTER_ACTION, fn (ActionEvent $event) => $this->utility($event));
		Event::on(AssetIndexesController::class, Controller::EVENT_AFTER_ACTION, fn (ActionEvent $event) => $this->assetIndexing($event));
		Event::on(Connection::class, Connection::EVENT_AFTER_CREATE_BACKUP, fn (BackupEvent $event) => $this->activity()->record(ActivityAction::BackedUp, ActivitySubject::Database, null, basename($event->file)));
		Event::on(Connection::class, Connection::EVENT_AFTER_RESTORE_BACKUP, fn (BackupEvent $event) => $this->activity()->record(ActivityAction::Restored, ActivitySubject::Database, null, basename($event->file)));
	}

	private function elementSaved(ElementEvent $event): void
	{
		$element = $event->element;
		unset($this->saving[$element]);

		if (!$this->tracks($element) || $element->propagating || $element->resaving) {
			return;
		}

		// Drafts and revisions are applied by saving the canonical element as a duplicate of them.
		$derivative = $element->updatingFromDerivative ? $element->duplicateOf : null;

		$behavior = $derivative?->getBehavior('revision');

		if ($behavior instanceof RevisionBehavior) {
			$this->element(ActivityAction::Reverted, $element, ['revision' => $behavior->revisionNum]);

			return;
		}

		$isNew = $event->isNew || $element->firstSave;
		$dirty = $element->getDirtyAttributes();
		$admin = $element instanceof User && ($isNew ? $element->admin : in_array('admin', $dirty, true));
		$password = $element instanceof User && !$isNew && in_array('password', $dirty, true);

		// Unpublished drafts are applied by saving them for the first time; duplicates are saved as new elements.
		if ($isNew) {
			$this->element(ActivityAction::Created, $element, $derivative === null && $element->duplicateOf !== null ? ['duplicateOf' => $element->duplicateOf->getUiLabel()] : []);
		} else {
			// Changes saved in the control panel are applied from provisional drafts, which are named like any other.
			$draft = $derivative !== null && !$derivative->isProvisionalDraft ? $derivative->getBehavior('draft') : null;
			$ignored = $element instanceof User ? [...self::IGNORED_ATTRIBUTES, ...self::IGNORED_USER_ATTRIBUTES] : self::IGNORED_ATTRIBUTES;
			$data = array_filter([
				'draft' => $draft instanceof DraftBehavior ? $draft->draftName : null,
				'attributes' => array_values(array_diff($dirty, $ignored)),
				'fields' => array_values($element->getDirtyFields()),
			]);

			// Users saved only to change what's recorded on its own aren't updated otherwise.
			if ($data !== [] || (!$admin && !$password)) {
				$this->element(ActivityAction::Updated, $element, $data);
			}
		}

		if ($admin) {
			$this->element($element->admin ? ActivityAction::AdminGranted : ActivityAction::AdminRevoked, $element);
		}

		if ($password) {
			$this->element(ActivityAction::PasswordChanged, $element);
		}
	}

	private function elementDeleted(ElementEvent $event): void
	{
		$element = $event->element;

		if ($this->tracks($element)) {
			$this->element(ActivityAction::Deleted, $element, $element->hardDelete ? ['hardDelete' => true] : []);

			return;
		}

		// Drafts are deleted once they're applied, and provisional ones as their changes are discarded.
		$draft = $element->getBehavior('draft');

		if (
			Hub::getInstance()->settings->activityDrafts
			&& $draft instanceof DraftBehavior
			&& !$element->isProvisionalDraft
			&& $this->applyingDrafts === 0
			&& !$this->isNested($element)
		) {
			$this->activity()->record(ActivityAction::DraftDeleted, ActivitySubject::fromElement($element), $element->getCanonicalId(), $element->getUiLabel(), array_filter(['draftName' => $draft->draftName]), $element->siteId);
		}
	}

	/**
	 * Elements deleted for a site while they're kept on others; those kept on none are deleted altogether.
	 */
	private function elementDeletedForSite(ElementEvent $event): void
	{
		if ($this->tracks($event->element)) {
			$this->element(ActivityAction::Deleted, $event->element, ['forSite' => true]);
		}
	}

	private function elementRestored(ElementEvent $event): void
	{
		if ($this->tracks($event->element)) {
			$this->element(ActivityAction::Restored, $event->element);
		}
	}

	/**
	 * Merged elements are deleted, so the one they were merged into is recorded.
	 */
	private function elementsMerged(MergeElementsEvent $event): void
	{
		$element = Craft::$app->getElements()->getElementById($event->prevailingElementId);

		if ($element !== null && $this->tracks($element)) {
			$this->element(ActivityAction::Merged, $element, ['merged' => $event->mergedElementId]);
		}
	}

	/**
	 * Elements moved in their structure on their own, as they're moved along with being saved too.
	 */
	private function elementMoved(MoveElementEvent $event): void
	{
		$element = $event->element;

		if (isset($this->saving[$element]) || !$this->tracks($element) || $element->propagating || $element->resaving) {
			return;
		}

		$this->element(ActivityAction::Moved, $element, array_filter([
			'position' => $event->action,
			'target' => $event->getTargetElement()?->getUiLabel(),
		]));
	}

	/**
	 * Drafts of elements created on purpose, rather than provisional ones of the changes not yet saved.
	 */
	private function draftCreated(DraftEvent $event): void
	{
		if (!$event->provisional && $this->tracks($event->canonical)) {
			$this->element(ActivityAction::DraftCreated, $event->canonical, array_filter(['draftName' => $event->draftName]));
		}
	}

	/**
	 * Whether changes of an element are recorded: drafts and revisions aren't until they're applied, nor are nested
	 * elements, which are saved along with their owners. Unpublished drafts have no canonical element, so they're no
	 * derivatives.
	 */
	private function tracks(ElementInterface $element): bool
	{
		return !$element->getIsDraft()
			&& !$element->getIsRevision()
			&& !$element->getIsDerivative()
			&& !$this->isNested($element);
	}

	private function isNested(ElementInterface $element): bool
	{
		return $element instanceof NestedElementInterface && $element->getPrimaryOwnerId() !== null;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function element(ActivityAction $action, ElementInterface $element, array $data = []): void
	{
		$subject = ActivitySubject::fromElement($element);

		if ($subject === ActivitySubject::Element) {
			$data['type'] = $element::displayName();
		}

		$this->activity()->record($action, $subject, $element->id, $element->getUiLabel(), $data, $element->siteId);
	}

	/**
	 * Element actions are recorded as a batch of what they changed, named like the action.
	 */
	private function performingAction(ElementActionEvent $event): void
	{
		if ($this->performing) {
			return;
		}

		$type = $event->criteria instanceof ElementQuery ? $event->criteria->elementType : null;

		$this->performing = true;
		$this->activity()->beginBatch(
			ActivityAction::Bulk,
			$type !== null ? ActivitySubject::fromElementType($type) : ActivitySubject::Element,
			$event->action->getTriggerLabel(),
		);
	}

	/**
	 * Downloading files changes nothing, so the files are recorded as downloaded.
	 */
	private function performedAction(ElementActionEvent $event): void
	{
		if ($event->action instanceof DownloadAssetFile) {
			foreach ($event->criteria->all() as $asset) {
				if ($asset instanceof Asset) {
					$this->element(ActivityAction::Downloaded, $asset);
				}
			}
		}

		$this->endAction();
	}

	/**
	 * Element actions that failed end their batch along with the request; exports are recorded by how many elements they
	 * held, in which format.
	 */
	private function elementIndexAction(ActionEvent $event): void
	{
		if ($event->action->id === 'perform-action') {
			$this->endAction();

			return;
		}

		if ($event->action->id !== 'export' || !$event->sender instanceof ElementIndexesController) {
			return;
		}

		$query = $event->sender->getElementQuery();
		$request = Craft::$app->getRequest();
		$exporter = $request->getBodyParam('type');

		$this->activity()->record(
			ActivityAction::Exported,
			$query instanceof ElementQuery ? ActivitySubject::fromElementType($query->elementType) : ActivitySubject::Element,
			data: array_filter([
				'count' => (int)(clone $query)->count(),
				'exporter' => is_string($exporter) && class_exists($exporter) && method_exists($exporter, 'displayName') ? $exporter::displayName() : null,
				'format' => is_string($format = $request->getBodyParam('format')) ? $format : null,
			]),
		);
	}

	private function endAction(): void
	{
		if ($this->performing) {
			$this->performing = false;
			$this->activity()->endBatch();
		}
	}

	/**
	 * Sign-ins by the "Keep me signed in" cookie aren't recorded, as they continue an earlier session. Impersonations
	 * sign users in without them, by the control panel or links of `craft users/impersonate`, so they're recorded as
	 * done by the impersonating user.
	 */
	private function signedIn(WebUserEvent $event): void
	{
		if ($event->cookieBased || !$event->identity instanceof User) {
			return;
		}

		$impersonatorId = $event->sender instanceof WebUser ? $event->sender->getImpersonatorId() : null;

		if ($impersonatorId !== null) {
			$this->activity()->record(ActivityAction::Impersonated, ActivitySubject::User, $event->identity->id, $event->identity->getName(), user: Craft::$app->getUsers()->getUserById($impersonatorId));

			return;
		}

		$this->activity()->record(ActivityAction::SignedIn, ActivitySubject::User, $event->identity->id, $event->identity->getName(), user: $event->identity);
	}

	private function signedOut(WebUserEvent $event): void
	{
		if ($event->identity instanceof User) {
			$this->activity()->record(ActivityAction::SignedOut, ActivitySubject::User, $event->identity->id, $event->identity->getName(), user: $event->identity);
		}
	}

	/**
	 * Failed sign-ins are recorded without a user, as they're only an attempt; for unknown users, the name they tried is kept.
	 */
	private function signInFailed(LoginFailureEvent $event): void
	{
		$loginName = Craft::$app->getRequest()->getBodyParam('loginName');

		$this->activity()->record(
			ActivityAction::SignInFailed,
			ActivitySubject::User,
			$event->user?->id,
			$event->user?->getName() ?? (is_string($loginName) ? $loginName : null),
			array_filter(['reason' => $event->authError]),
			anonymous: true,
		);
	}

	private function user(ActivityAction $action, User $user, ?User $by = null): void
	{
		$this->activity()->record($action, ActivitySubject::User, $user->id, $user->getName(), user: $by);
	}

	/**
	 * Password resets are requested by email, by the users themselves or for them; the address is kept for unknown users.
	 */
	private function mailSent(MailEvent $event): void
	{
		$message = $event->message;

		if (!$event->isSuccessful || !$message instanceof Message || $message->key !== 'forgot_password') {
			return;
		}

		$to = $message->getTo();
		$email = is_array($to) ? array_key_first($to) : $to;
		$user = is_string($email) ? Craft::$app->getUsers()->getUserByUsernameOrEmail($email) : null;

		$this->activity()->record(ActivityAction::PasswordResetRequested, ActivitySubject::User, $user?->id, $user?->getName() ?? (is_string($email) ? $email : null));
	}

	/**
	 * Craft only assigns groups when they changed, telling which were added and removed.
	 */
	private function groupsAssigned(UserGroupsAssignEvent $event): void
	{
		$groups = Craft::$app->getUserGroups();
		$names = fn (array $ids) => array_values(array_filter(array_map(fn ($id) => $groups->getGroupById((int)$id)?->name, $ids)));

		$this->activity()->record(
			ActivityAction::GroupsChanged,
			ActivitySubject::User,
			$event->userId,
			Craft::$app->getUsers()->getUserById($event->userId)?->getName(),
			array_filter(['added' => $names($event->newGroupIds), 'removed' => $names($event->removedGroupIds)]),
		);
	}

	/**
	 * Craft saves the permissions of users each time they're saved in the control panel, so those they had are
	 * remembered to tell whether they changed.
	 */
	private function rememberPermissions(UserEvent $event): void
	{
		if ($event->user->id) {
			$this->permissions[$event->user->id] = $this->activity()->getUserPermissions($event->user->id);
		}
	}

	private function permissionsSaved(UserPermissionsEvent $event): void
	{
		$before = $this->permissions[$event->userId] ?? null;
		unset($this->permissions[$event->userId]);

		$this->permissionsChanged(ActivitySubject::User, $event->userId, Craft::$app->getUsers()->getUserById($event->userId)?->getName(), $before, $event->permissions);
	}

	/**
	 * @param string[]|null $before permissions before, unless unknown
	 * @param string[] $after
	 */
	private function permissionsChanged(ActivitySubject $subject, ?int $id, ?string $title, ?array $before, array $after): void
	{
		$granted = $before !== null ? array_values(array_diff($after, $before)) : $after;
		$revoked = $before !== null ? array_values(array_diff($before, $after)) : [];

		if ($granted !== [] || $revoked !== []) {
			$this->activity()->record(ActivityAction::PermissionsChanged, $subject, $id, $title, array_filter(['granted' => $granted, 'revoked' => $revoked]));
		}
	}

	/**
	 * Authenticator apps are set up by saving their secret, which is saved again as they're set up anew.
	 */
	private function authenticatorSaved(AfterSaveEvent $event): void
	{
		$record = $event->sender;

		if ($record instanceof AuthenticatorRecord && ($event->name === BaseActiveRecord::EVENT_AFTER_INSERT || array_key_exists('auth2faSecret', $event->changedAttributes))) {
			$this->auth(ActivityAction::AuthAdded, (int)$record->userId, TOTP::displayName());
		}
	}

	private function passkey(ActivityAction $action, Event $event): void
	{
		$record = $event->sender;

		if ($record instanceof WebAuthnRecord) {
			$this->auth($action, (int)$record->userId, Craft::t('app', 'Passkey'), $record->credentialName);
		}
	}

	/**
	 * Authentication methods are removed without saving anything Craft tells of, as are recovery codes generated.
	 */
	private function authAction(ActionEvent $event): void
	{
		$user = Craft::$app->getUser()->getIdentity();

		if (!$user instanceof User || !Craft::$app->getResponse()->getIsSuccessful()) {
			return;
		}

		if ($event->action->id === 'remove-method') {
			$method = Craft::$app->getRequest()->getBodyParam('method');

			if (is_string($method) && is_subclass_of($method, AuthMethodInterface::class)) {
				$this->auth(ActivityAction::AuthRemoved, (int)$user->id, $method::displayName());
			}
		} elseif ($event->action->id === 'generate-recovery-codes') {
			$this->auth(ActivityAction::AuthAdded, (int)$user->id, RecoveryCodes::displayName());
		}
	}

	private function auth(ActivityAction $action, int $userId, string $method, ?string $name = null): void
	{
		$this->activity()->record($action, ActivitySubject::User, $userId, Craft::$app->getUsers()->getUserById($userId)?->getName(), array_filter(['method' => $method, 'name' => $name]));
	}

	/**
	 * Changes of the things holding the content, along with the others, while they're applied from the project config files.
	 *
	 * @param object{id:int|null,name:string|null} $model
	 */
	private function config(ActivityAction $action, ActivitySubject $subject, object $model): void
	{
		$this->applying();

		$this->activity()->record($action, $subject, $model->id ?? null, $model->name ?? $model->handle ?? null);
	}

	/**
	 * Project config changes without events of their own: the permissions of user groups, items like filesystems and
	 * routes, the system's settings, and the schema versions of Craft and plugins, which change as they're updated.
	 * Changes applied from the project config files may come in by any path within an item or settings, so they change
	 * its item, or the setting at its path.
	 */
	private function configChanged(ActivityAction $action, ConfigEvent $event): void
	{
		$applying = $this->applying();
		$path = $event->path;

		if (preg_match('/^users\.groups\.([^.]+)\.permissions$/', $path, $matches)) {
			$group = Craft::$app->getUserGroups()->getGroupByUid($matches[1]);
			$this->permissionsChanged(ActivitySubject::UserGroup, $group?->id, $group?->name, (array)($event->oldValue ?? []), (array)($event->newValue ?? []));

			return;
		}

		if (!$applying && $action === ActivityAction::Updated && $path === ProjectConfig::PATH_SCHEMA_VERSION) {
			$this->activity()->record(ActivityAction::Updated, ActivitySubject::System, null, 'Craft CMS', ['version' => Craft::$app->getVersion()]);

			return;
		}

		if (!$applying && $action === ActivityAction::Updated && preg_match('/^plugins\.([^.]+)\.schemaVersion$/', $path, $matches)) {
			$plugin = Craft::$app->getPlugins()->getPlugin($matches[1]);
			$this->activity()->record(ActivityAction::Updated, ActivitySubject::Plugin, null, $plugin->name ?? $matches[1], array_filter(['handle' => $matches[1], 'version' => $plugin?->getVersion()]));

			return;
		}

		foreach (self::CONFIG_ITEMS as $prefix => $subject) {
			if (str_starts_with($path, "$prefix.")) {
				$this->configItem($subject, $prefix, $path, $action, $event);

				return;
			}
		}

		if (str_starts_with($path, ProjectConfig::PATH_USER_GROUPS)) {
			return;
		}

		if ($applying && preg_match('/^plugins\.([^.]+)\.settings(\.|$)/', $path, $matches)) {
			$this->pluginSettingsApplied($matches[1]);

			return;
		}

		foreach (self::CONFIG_SETTINGS as $prefix => $title) {
			if ($path === $prefix || str_starts_with($path, "$prefix.")) {
				$this->settingsChanged($prefix, $title, $path, $event, $applying);

				return;
			}
		}
	}

	/**
	 * The system's settings changed, by the keys of the settings within them. While the project config is applied, they
	 * may change by several paths, so all that change are told by the first.
	 */
	private function settingsChanged(string $prefix, string $title, string $path, ConfigEvent $event, bool $applying): void
	{
		$projectConfig = Craft::$app->getProjectConfig();

		$keys = match (true) {
			$applying => $this->changedKeys($projectConfig->get($prefix), $projectConfig->get($prefix, true)),
			$path === $prefix => $this->changedKeys($event->oldValue, $event->newValue),
			default => [explode('.', substr($path, strlen($prefix) + 1))[0]],
		};
		$keys = array_values(array_diff($keys, self::IGNORED_SETTINGS));

		if ($keys !== [] && $this->once("settings:$prefix")) {
			$this->activity()->record(ActivityAction::Updated, ActivitySubject::Settings, null, Craft::t('app', $title), ['attributes' => $keys]);
		}
	}

	/**
	 * Settings of plugins are saved without an event of their own while the project config is applied.
	 */
	private function pluginSettingsApplied(string $handle): void
	{
		if ($this->once("plugin:$handle")) {
			$plugin = Craft::$app->getPlugins()->getPlugin($handle);
			$this->activity()->record(ActivityAction::Updated, ActivitySubject::Plugin, null, $plugin->name ?? $handle, ['handle' => $handle]);
		}
	}

	/**
	 * An item of the project config, by its key within the path holding the items, named by its config.
	 */
	private function configItem(ActivitySubject $subject, string $prefix, string $path, ActivityAction $action, ConfigEvent $event): void
	{
		[$key] = explode('.', substr($path, strlen($prefix) + 1));
		$itemPath = "$prefix.$key";

		// Changes within an item are updates of it.
		if ($path !== $itemPath) {
			$action = ActivityAction::Updated;
			$config = Craft::$app->getProjectConfig()->get($itemPath);
		} else {
			$config = $event->newValue ?? $event->oldValue;
		}

		if (!$this->once($itemPath)) {
			return;
		}

		$config = is_array($config) ? $config : [];
		$id = $subject === ActivitySubject::GqlSchema ? Craft::$app->getGql()->getSchemaByUid($key)?->id : null;

		$this->activity()->record($action, $subject, $id, $subject === ActivitySubject::Route ? $this->routeTitle($config) : (is_string($config['name'] ?? null) ? $config['name'] : $key));
	}

	/**
	 * Routes are named by their URI, as given in their settings, with the names of their tokens.
	 *
	 * @param array<string,mixed> $config
	 */
	private function routeTitle(array $config): ?string
	{
		$parts = array_map(fn (mixed $part) => is_array($part) ? '<' . ($part[0] ?? '') . '>' : (string)$part, (array)($config['uriParts'] ?? []));

		return $parts !== [] ? implode('', $parts) : null;
	}

	/**
	 * Keys of the settings that changed between two configs.
	 *
	 * @return string[]
	 */
	private function changedKeys(mixed $old, mixed $new): array
	{
		$old = is_array($old) ? $old : [];
		$new = is_array($new) ? $new : [];

		return array_values(array_filter(
			array_unique([...array_keys($old), ...array_keys($new)]),
			fn (string|int $key) => ProjectConfigHelper::encodeValueAsString($old[$key] ?? null) !== ProjectConfigHelper::encodeValueAsString($new[$key] ?? null),
		));
	}

	/**
	 * GraphQL tokens, named by their name, which are saved again as they're used.
	 */
	private function token(ActivityAction $action, Event $event): void
	{
		$record = $event->sender;

		if (!$record instanceof GqlTokenRecord) {
			return;
		}

		$changes = $event instanceof AfterSaveEvent ? array_values(array_diff(array_keys($event->changedAttributes), self::IGNORED_RECORD_ATTRIBUTES)) : [];

		if ($action === ActivityAction::Updated && $changes === []) {
			return;
		}

		$this->applying();

		$this->activity()->record($action, ActivitySubject::GqlToken, $action !== ActivityAction::Deleted ? (int)$record->id : null, $record->name, $action === ActivityAction::Updated ? ['attributes' => $changes] : []);
	}

	private function plugin(ActivityAction $action, PluginInterface $plugin): void
	{
		$this->applying();

		$this->activity()->record($action, ActivitySubject::Plugin, null, $plugin->name ?? $plugin->handle, ['handle' => $plugin->handle]);
	}

	/**
	 * Begins the batch of the project config changes applied from the YAML files, as soon as they're applied.
	 *
	 * @return bool whether they're applied
	 */
	private function applying(): bool
	{
		if (!Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
			return false;
		}

		if (!$this->applying) {
			$this->applying = true;
			$this->activity()->beginBatch(ActivityAction::Applied, ActivitySubject::ProjectConfig, Craft::t('app', 'Project Config'));
		}

		return true;
	}

	private function configApplied(): void
	{
		if ($this->applying) {
			$this->applying = false;
			$this->applied = [];
			$this->activity()->endBatch();
		}
	}

	/**
	 * Whether something is recorded for the first time while the project config is applied, as it may change by several
	 * paths then; always otherwise.
	 */
	private function once(string $key): bool
	{
		if (!$this->applying) {
			return true;
		}

		if (isset($this->applied[$key])) {
			return false;
		}

		return $this->applied[$key] = true;
	}

	/**
	 * Utilities run from the control panel, unless they failed; backups are recorded as they're created.
	 */
	private function utility(ActionEvent $event): void
	{
		if (!Craft::$app->getResponse()->getIsSuccessful()) {
			return;
		}

		$request = Craft::$app->getRequest();
		$params = $request->getBodyParam('params');

		[$action, $title, $data] = match ($event->action->id) {
			'clear-caches-perform-action' => [ActivityAction::Cleared, ClearCaches::displayName(), ['caches' => (array)$request->getBodyParam('caches')]],
			'invalidate-tags' => [ActivityAction::Cleared, ClearCaches::displayName(), ['tags' => (array)$request->getBodyParam('tags')]],
			'find-and-replace-perform-action' => is_array($params) && !empty($params['find']) && !empty($params['replace'])
				? [ActivityAction::Ran, FindAndReplace::displayName(), ['find' => (string)$params['find'], 'replace' => (string)$params['replace']]]
				: [null, null, []],
			'apply-new-migrations' => [ActivityAction::Ran, Migrations::displayName(), []],
			default => [null, null, []],
		};

		if ($action !== null) {
			$this->activity()->record($action, ActivitySubject::Utility, null, $title, $data);
		}
	}

	/**
	 * Asset indexing started, by the names of the volumes indexed.
	 */
	private function assetIndexing(ActionEvent $event): void
	{
		if ($event->action->id !== 'start-indexing' || !Craft::$app->getResponse()->getIsSuccessful()) {
			return;
		}

		$volumes = Craft::$app->getVolumes();
		$names = array_values(array_filter(array_map(fn (mixed $id) => $volumes->getVolumeById((int)$id)?->name, (array)Craft::$app->getRequest()->getBodyParam('volumes'))));

		$this->activity()->record(ActivityAction::Ran, ActivitySubject::Utility, null, AssetIndexes::displayName(), array_filter(['volumes' => $names]));
	}

	private function activity(): ActivityService
	{
		/** @var ActivityService */
		return Hub::getInstance()->activity;
	}
}
