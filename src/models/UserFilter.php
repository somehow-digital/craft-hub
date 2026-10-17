<?php

namespace SomehowDigital\Craft\Hub\models;

use DateTime;

/**
 * Filters of the users list. Lists hold their values as they appear in the URL; empty lists and null values don't filter.
 */
class UserFilter
{
	/** users in no group, as a value of the groups filter */
	public const GROUP_NONE = 'none';

	public const LOGIN_RECENT = 'recent';
	public const LOGIN_IDLE = 'idle';
	public const LOGIN_NEVER = 'never';

	public const TWO_FACTOR_WITH = 'with';
	public const TWO_FACTOR_WITHOUT = 'without';

	/** matched against the username, full name and email */
	public string $search = '';

	/** @var string[] IDs of the labels, or `none` for unlabeled items, see LabelService::getFilterValues() */
	public array $labels = [];

	/** UID of a custom saved filter, whose condition the items match, see FilterService */
	public ?string $savedFilter = null;

	/** @var string[] user group handles, or `none` for users in no group */
	public array $groups = [];

	/** @var string[] user statuses (`active`, `pending`, `suspended`, `locked`, `inactive`) */
	public array $statuses = [];

	/** only admins */
	public bool $admin = false;

	/** @var string[] how recently the users signed in, see the `LOGIN_*` constants */
	public array $logins = [];

	/** dates of the last sign-in, both days included */
	public ?DateTime $loginFrom = null;
	public ?DateTime $loginTo = null;

	/** creation dates, both days included */
	public ?DateTime $createdFrom = null;
	public ?DateTime $createdTo = null;

	/** whether the users have set up an authenticator app or a passkey (`with` or `without`) */
	public ?string $twoFactor = null;

	/** only users who changed their email address without verifying the new one */
	public bool $unverifiedEmail = false;

	/** number of entries the users are authors of */
	public ?int $entriesMin = null;
	public ?int $entriesMax = null;

	/**
	 * @return string[]
	 */
	public static function getLogins(): array
	{
		return [self::LOGIN_RECENT, self::LOGIN_IDLE, self::LOGIN_NEVER];
	}
}
