<?php

namespace SomehowDigital\Craft\Hub\enums;

use Craft;

/**
 * Generic kind of what was done in an activity, the activity log is filtered by; the type of the subject narrows it
 * down, like deleted assets rather than deleted anything. Each action belongs to one, see ActivityAction::group().
 */
enum ActivityGroup: string
{
	case Created = 'created';
	case Updated = 'updated';
	case Deleted = 'deleted';
	case Restored = 'restored';
	case Status = 'status';
	case Access = 'access';
	case SignIn = 'signIn';
	case Exported = 'exported';
	case Ran = 'ran';

	/**
	 * The actions of the group.
	 *
	 * @return ActivityAction[]
	 */
	public function actions(): array
	{
		return array_values(array_filter(ActivityAction::cases(), fn (ActivityAction $action) => $action->group() === $this));
	}

	public function label(): string
	{
		return match ($this) {
			self::Created => Craft::t('hub', 'Created'),
			self::Updated => Craft::t('hub', 'Updated'),
			self::Deleted => Craft::t('hub', 'Deleted'),
			self::Restored => Craft::t('hub', 'Restored'),
			self::Status => Craft::t('hub', 'Status changed'),
			self::Access => Craft::t('hub', 'Access changed'),
			self::SignIn => Craft::t('hub', 'Sign-in'),
			self::Exported => Craft::t('hub', 'Downloaded'),
			self::Ran => Craft::t('hub', 'Ran'),
		};
	}

	public function color(): string
	{
		return match ($this) {
			self::Created, self::Restored => 'green',
			self::Updated => 'blue',
			self::Deleted => 'red',
			self::Status => 'orange',
			self::Access => 'violet',
			self::SignIn, self::Exported, self::Ran => 'gray',
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::Created => 'plus',
			self::Updated => 'pen',
			self::Deleted => 'trash',
			self::Restored => 'trash-arrow-up',
			self::Status => 'toggle-on',
			self::Access => 'key',
			self::SignIn => 'arrow-right-to-bracket',
			self::Exported => 'download',
			self::Ran => 'play',
		};
	}
}
