<?php

namespace SomehowDigital\Craft\Hub\models;

use DateTime;

class EntryStatistics
{
	public const ACTIVITY_RECENT = 'recent';
	public const ACTIVITY_STALE = 'stale';
	public const ACTIVITY_NONE = 'none';

	public int $total = 0;
	public int $enabled = 0;
	public int $drafts = 0;
	public ?DateTime $lastUpdated = null;

	public function getDisabled(): int
	{
		return $this->total - $this->enabled;
	}

	/**
	 * @return string[]
	 */
	public static function getActivities(): array
	{
		return [self::ACTIVITY_RECENT, self::ACTIVITY_STALE, self::ACTIVITY_NONE];
	}

	/**
	 * Whether the entries were updated recently, are stale, or there are none, see the `ACTIVITY_*` constants.
	 */
	public function getActivity(int $staleMonths): string
	{
		if ($this->lastUpdated === null) {
			return self::ACTIVITY_NONE;
		}

		return $this->lastUpdated < new DateTime(sprintf('-%d months', $staleMonths)) ? self::ACTIVITY_STALE : self::ACTIVITY_RECENT;
	}

	public function add(self $stats): self
	{
		$this->total += $stats->total;
		$this->enabled += $stats->enabled;
		$this->drafts += $stats->drafts;

		if ($stats->lastUpdated !== null && ($this->lastUpdated === null || $stats->lastUpdated > $this->lastUpdated)) {
			$this->lastUpdated = $stats->lastUpdated;
		}

		return $this;
	}
}
