<?php

namespace SomehowDigital\Craft\Hub\models;

/**
 * Counts of the users, by status and by how they sign in.
 * Sign-ins and two-step verification are only counted for active users, as only they can sign in.
 */
class UserStatistics
{
	public int $total = 0;

	public int $active = 0;
	public int $pending = 0;
	public int $suspended = 0;
	public int $locked = 0;

	/** users who can't sign in, kept e.g. as authors */
	public int $inactive = 0;

	public int $admins = 0;

	/** active users who signed in within the idle threshold */
	public int $recentLogin = 0;

	/** active users who haven't signed in within the idle threshold, including those who never did since they were created before it */
	public int $idle = 0;

	/** active users with an authenticator app or a passkey */
	public int $twoFactor = 0;

	/** active admins without an authenticator app or a passkey */
	public int $adminsWithoutTwoFactor = 0;

	/** users who changed their email address without verifying the new one */
	public int $unverifiedEmail = 0;

	/**
	 * Active users who haven't signed in yet, but were created within the idle threshold.
	 */
	public function getNew(): int
	{
		return max(0, $this->active - $this->recentLogin - $this->idle);
	}
}
