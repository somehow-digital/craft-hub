<?php

namespace SomehowDigital\Craft\Hub\models;

use DateTime;

class EntryStatistics
{
	public int $total = 0;
	public int $enabled = 0;
	public int $drafts = 0;
	public ?DateTime $lastUpdated = null;

	public function getDisabled(): int
	{
		return $this->total - $this->enabled;
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
