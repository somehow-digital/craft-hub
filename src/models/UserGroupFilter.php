<?php

namespace SomehowDigital\Craft\Hub\models;

/**
 * Filters of the user groups list. Null values don't filter.
 */
class UserGroupFilter
{
	public const TWO_FACTOR_REQUIRED = 'required';
	public const TWO_FACTOR_OPTIONAL = 'optional';

	/** matched against the name, handle and description */
	public string $search = '';

	public ?int $usersMin = null;
	public ?int $usersMax = null;

	public ?int $permissionsMin = null;
	public ?int $permissionsMax = null;

	/** whether the groups require two-step verification (`required` or `optional`) */
	public ?string $twoFactor = null;

	/** only groups without users */
	public bool $empty = false;
}
