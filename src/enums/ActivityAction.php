<?php

namespace SomehowDigital\Craft\Hub\enums;

use Craft;

/**
 * What was done in an activity of the activity log.
 */
enum ActivityAction: string
{
	case Created = 'created';
	case Updated = 'updated';
	case Deleted = 'deleted';
	case Restored = 'restored';
	case Reverted = 'reverted';
	case SignedIn = 'signedIn';
	case SignedOut = 'signedOut';
	case SignInFailed = 'signInFailed';
	case Activated = 'activated';
	case Deactivated = 'deactivated';
	case Suspended = 'suspended';
	case Unsuspended = 'unsuspended';
	case Locked = 'locked';
	case Unlocked = 'unlocked';
	case GroupsChanged = 'groupsChanged';
	case PermissionsChanged = 'permissionsChanged';
	case Installed = 'installed';
	case Uninstalled = 'uninstalled';
	case Enabled = 'enabled';
	case Disabled = 'disabled';
	case TransformsDeleted = 'transformsDeleted';
	case TransformsGenerated = 'transformsGenerated';
	case Moved = 'moved';
	case Replaced = 'replaced';
	case Merged = 'merged';
	case DraftCreated = 'draftCreated';
	case DraftDeleted = 'draftDeleted';
	case Impersonated = 'impersonated';
	case AdminGranted = 'adminGranted';
	case AdminRevoked = 'adminRevoked';
	case PasswordChanged = 'passwordChanged';
	case PasswordResetRequested = 'passwordResetRequested';
	case EmailVerified = 'emailVerified';
	case AuthAdded = 'authAdded';
	case AuthRemoved = 'authRemoved';
	case Labeled = 'labeled';
	case Cleared = 'cleared';
	case Downloaded = 'downloaded';
	case Exported = 'exported';
	case Ran = 'ran';
	case BackedUp = 'backedUp';
	case Applied = 'applied';
	case Bulk = 'bulk';

	/**
	 * Generic kind of the action, the activity log is filtered by.
	 */
	public function group(): ActivityGroup
	{
		return match ($this) {
			self::Created, self::DraftCreated, self::TransformsGenerated, self::Installed => ActivityGroup::Created,
			self::Updated, self::Moved, self::Replaced, self::Merged, self::Labeled => ActivityGroup::Updated,
			self::Deleted, self::TransformsDeleted, self::DraftDeleted, self::Uninstalled => ActivityGroup::Deleted,
			self::Restored, self::Reverted => ActivityGroup::Restored,
			self::Activated, self::Deactivated, self::Suspended, self::Unsuspended, self::Locked, self::Unlocked, self::Enabled, self::Disabled => ActivityGroup::Status,
			self::GroupsChanged, self::PermissionsChanged, self::AdminGranted, self::AdminRevoked => ActivityGroup::Access,
			self::SignedIn, self::SignedOut, self::SignInFailed, self::Impersonated, self::PasswordChanged, self::PasswordResetRequested, self::EmailVerified, self::AuthAdded, self::AuthRemoved => ActivityGroup::SignIn,
			self::Downloaded, self::Exported => ActivityGroup::Exported,
			self::Cleared, self::Ran, self::BackedUp, self::Applied, self::Bulk => ActivityGroup::Ran,
		};
	}

	public function label(): string
	{
		return match ($this) {
			self::Created => Craft::t('hub', 'Created'),
			self::Updated => Craft::t('hub', 'Updated'),
			self::Deleted => Craft::t('hub', 'Deleted'),
			self::Restored => Craft::t('hub', 'Restored'),
			self::Reverted => Craft::t('hub', 'Reverted'),
			self::SignedIn => Craft::t('hub', 'Signed in'),
			self::SignedOut => Craft::t('hub', 'Signed out'),
			self::SignInFailed => Craft::t('hub', 'Sign-in failed'),
			self::Activated => Craft::t('hub', 'Activated'),
			self::Deactivated => Craft::t('hub', 'Deactivated'),
			self::Suspended => Craft::t('hub', 'Suspended'),
			self::Unsuspended => Craft::t('hub', 'Unsuspended'),
			self::Locked => Craft::t('hub', 'Locked'),
			self::Unlocked => Craft::t('hub', 'Unlocked'),
			self::GroupsChanged => Craft::t('hub', 'Groups changed'),
			self::PermissionsChanged => Craft::t('hub', 'Permissions changed'),
			self::Installed => Craft::t('hub', 'Installed'),
			self::Uninstalled => Craft::t('hub', 'Uninstalled'),
			self::Enabled => Craft::t('hub', 'Enabled'),
			self::Disabled => Craft::t('hub', 'Disabled'),
			self::TransformsDeleted => Craft::t('hub', 'Transforms deleted'),
			self::TransformsGenerated => Craft::t('hub', 'Transforms generated'),
			self::Moved => Craft::t('hub', 'Moved'),
			self::Replaced => Craft::t('hub', 'File replaced'),
			self::Merged => Craft::t('hub', 'Merged'),
			self::DraftCreated => Craft::t('hub', 'Draft created'),
			self::DraftDeleted => Craft::t('hub', 'Draft deleted'),
			self::Impersonated => Craft::t('hub', 'Impersonated'),
			self::AdminGranted => Craft::t('hub', 'Admin granted'),
			self::AdminRevoked => Craft::t('hub', 'Admin revoked'),
			self::PasswordChanged => Craft::t('hub', 'Password changed'),
			self::PasswordResetRequested => Craft::t('hub', 'Password reset requested'),
			self::EmailVerified => Craft::t('hub', 'Email verified'),
			self::AuthAdded => Craft::t('hub', 'Sign-in method added'),
			self::AuthRemoved => Craft::t('hub', 'Sign-in method removed'),
			self::Labeled => Craft::t('hub', 'Labels changed'),
			self::Cleared => Craft::t('hub', 'Cleared'),
			self::Downloaded => Craft::t('hub', 'Downloaded'),
			self::Exported => Craft::t('hub', 'Exported'),
			self::Ran => Craft::t('hub', 'Ran'),
			self::BackedUp => Craft::t('hub', 'Backed up'),
			self::Applied => Craft::t('hub', 'Applied'),
			self::Bulk => Craft::t('hub', 'Bulk action'),
		};
	}

	public function color(): string
	{
		return match ($this) {
			self::Created, self::Restored, self::Activated, self::Unsuspended, self::Unlocked, self::Installed, self::Enabled, self::TransformsGenerated, self::EmailVerified, self::AuthAdded, self::BackedUp => 'green',
			self::Updated, self::Reverted, self::Moved, self::Replaced, self::PasswordChanged, self::Applied, self::Bulk => 'blue',
			self::Deleted, self::TransformsDeleted, self::SignInFailed, self::Suspended, self::Locked, self::Uninstalled, self::AuthRemoved => 'red',
			self::GroupsChanged, self::PermissionsChanged, self::Merged, self::AdminGranted, self::Labeled => 'violet',
			self::Deactivated, self::Disabled, self::DraftDeleted, self::Impersonated, self::AdminRevoked, self::Cleared => 'orange',
			self::SignedIn, self::SignedOut, self::DraftCreated, self::PasswordResetRequested, self::Downloaded, self::Exported, self::Ran => 'gray',
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::Created => 'plus',
			self::Updated => 'pen',
			self::Deleted, self::TransformsDeleted => 'trash',
			self::Restored => 'trash-arrow-up',
			self::Reverted => 'clock-rotate-left',
			self::SignedIn => 'arrow-right-to-bracket',
			self::SignedOut => 'arrow-right-from-bracket',
			self::SignInFailed => 'triangle-exclamation',
			self::Activated, self::Unsuspended => 'user-check',
			self::Deactivated => 'user-xmark',
			self::Suspended => 'ban',
			self::Locked => 'lock',
			self::Unlocked => 'lock-open',
			self::GroupsChanged => 'users',
			self::PermissionsChanged => 'key',
			self::Installed => 'download',
			self::Uninstalled => 'xmark',
			self::Enabled => 'toggle-on',
			self::Disabled => 'toggle-off',
			self::TransformsGenerated => 'wand-magic-sparkles',
			self::Moved => 'arrows-up-down-left-right',
			self::Replaced => 'file-arrow-up',
			self::Merged => 'code-merge',
			self::DraftCreated, self::DraftDeleted => 'pen-ruler',
			self::Impersonated => 'user-secret',
			self::AdminGranted, self::AdminRevoked => 'crown',
			self::PasswordChanged => 'lock-keyhole',
			self::PasswordResetRequested => 'envelope',
			self::EmailVerified => 'envelope-circle-check',
			self::AuthAdded => 'shield-halved',
			self::AuthRemoved => 'shield',
			self::Labeled => 'tags',
			self::Cleared => 'broom',
			self::Downloaded => 'download',
			self::Exported => 'file-export',
			self::Ran => 'play',
			self::BackedUp => 'database',
			self::Applied => 'file-import',
			self::Bulk => 'layer-group',
		};
	}
}
