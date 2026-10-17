<?php

namespace SomehowDigital\Craft\Hub\models;

use DateTime;

/**
 * Filters of the activity log. Lists hold their values as they appear in the URL; empty lists and null values don't filter.
 */
class ActivityFilter
{
	/** activities without a user, as a value of the users filter */
	public const USER_NONE = 'none';

	/** matched against the name of the subject and the user, and the IP address */
	public string $search = '';

	/** @var string[] groups of actions, see ActivityGroup */
	public array $actions = [];

	/** @var string[] kinds of subjects, see ActivitySubject */
	public array $subjects = [];

	/** @var string[] IDs of the users, or `none` for activities without one */
	public array $users = [];

	/** days of the activities, both days included */
	public ?DateTime $dateFrom = null;
	public ?DateTime $dateTo = null;

	/** ID of a subject, to list the activities of one item; only applies along with the single kind of subject it's of */
	public ?int $item = null;

	/** ID of a batch's own activity, to list the activities of the batch on their own */
	public ?int $batch = null;
}
