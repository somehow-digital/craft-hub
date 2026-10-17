<?php

namespace SomehowDigital\Craft\Hub\handlers\activity;

use Craft;
use craft\auth\methods\AuthMethodInterface;
use craft\auth\methods\RecoveryCodes;
use craft\auth\methods\TOTP;
use craft\controllers\AuthController;
use craft\controllers\UsersController;
use craft\elements\User;
use craft\events\LoginFailureEvent;
use craft\events\UserEvent;
use craft\events\UserGroupsAssignEvent;
use craft\events\UserPermissionsEvent;
use craft\mail\Mailer;
use craft\mail\Message;
use craft\records\Authenticator as AuthenticatorRecord;
use craft\records\WebAuthn as WebAuthnRecord;
use craft\services\UserPermissions;
use craft\services\Users;
use craft\web\User as WebUser;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;
use yii\web\UserEvent as WebUserEvent;

/**
 * Users signing in, their accounts, groups and permissions, and how they authenticate.
 */
class AccountActivity extends ActivitySubscriber
{
	/** events of the users' accounts, by the action they are */
	private const USER_EVENTS = [
		Users::EVENT_AFTER_ACTIVATE_USER => ActivityAction::Activated,
		Users::EVENT_AFTER_DEACTIVATE_USER => ActivityAction::Deactivated,
		Users::EVENT_AFTER_SUSPEND_USER => ActivityAction::Suspended,
		Users::EVENT_AFTER_UNSUSPEND_USER => ActivityAction::Unsuspended,
		Users::EVENT_AFTER_LOCK_USER => ActivityAction::Locked,
		Users::EVENT_AFTER_UNLOCK_USER => ActivityAction::Unlocked,
	];

	/** @var array<int,string[]> permissions granted directly to the users being saved in the control panel, by their ID */
	private array $permissions = [];

	public function register(): void
	{
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
	 * Failed sign-ins are recorded without a user, as they're only an attempt. What's tried for unknown users isn't kept, as
	 * it may be a password typed into the wrong field, and anyone can try, so those are recorded once a minute per address.
	 */
	private function signInFailed(LoginFailureEvent $event): void
	{
		if ($event->user === null && !Craft::$app->getCache()->add('hub:signInFailed:' . Craft::$app->getRequest()->getUserIP(), true, 60)) {
			return;
		}

		$this->activity()->record(
			ActivityAction::SignInFailed,
			ActivitySubject::User,
			$event->user?->id,
			$event->user?->getName(),
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
}
