<?php

namespace SomehowDigital\Craft\Hub\models;

use DateTime;

/**
 * Counts of a set of entries, like those of a section or an entry type.
 */
class EntryStatistics
{
	public const ACTIVITY_RECENT = 'recent';
	public const ACTIVITY_STALE = 'stale';
	public const ACTIVITY_NONE = 'none';

	public int $total = 0;
	public int $enabled = 0;
	/** enabled entries with a post date in the future */
	public int $pending = 0;
	/** enabled entries with an expiry date in the past */
	public int $expired = 0;
	public int $drafts = 0;
	public ?DateTime $lastUpdated = null;

	public function getDisabled(): int
	{
		return $this->total - $this->enabled;
	}

	/**
	 * Enabled entries that are neither pending nor expired.
	 */
	public function getLive(): int
	{
		return $this->enabled - $this->pending - $this->expired;
	}

	/**
	 * @return string[]
	 */
	public static function getActivities(): array
	{
		return [self::ACTIVITY_RECENT, self::ACTIVITY_STALE, self::ACTIVITY_NONE];
	}

	/**
	 * Whether the entries were updated recently, are stale, or there are none, see the `ACTIVITY_*` constants; as of now, or
	 * of another day, like that of a snapshot.
	 */
	public function getActivity(int $staleMonths, ?DateTime $at = null): string
	{
		if ($this->lastUpdated === null) {
			return self::ACTIVITY_NONE;
		}

		$threshold = ($at !== null ? clone $at : new DateTime())->modify(sprintf('-%d months', $staleMonths));

		return $this->lastUpdated < $threshold ? self::ACTIVITY_STALE : self::ACTIVITY_RECENT;
	}

	public function add(self $stats): self
	{
		$this->total += $stats->total;
		$this->enabled += $stats->enabled;
		$this->pending += $stats->pending;
		$this->expired += $stats->expired;
		$this->drafts += $stats->drafts;

		if ($stats->lastUpdated !== null && ($this->lastUpdated === null || $stats->lastUpdated > $this->lastUpdated)) {
			$this->lastUpdated = $stats->lastUpdated;
		}

		return $this;
	}
}
