<?php

namespace SomehowDigital\Craft\Hub\models;

use DateTime;

class AssetStatistics
{
	public int $total = 0;

	/** assets related to at least one element through a relation field */
	public int $used = 0;

	public int $images = 0;

	/** images without alternative text on any of the counted sites */
	public int $missingAlt = 0;

	/** file size in bytes */
	public int $size = 0;

	public ?DateTime $lastUploaded = null;

	public function getUnused(): int
	{
		return $this->total - $this->used;
	}

	public function getAverageSize(): int
	{
		return $this->total > 0 ? intdiv($this->size, $this->total) : 0;
	}

	public function add(self $stats): self
	{
		$this->total += $stats->total;
		$this->used += $stats->used;
		$this->images += $stats->images;
		$this->missingAlt += $stats->missingAlt;
		$this->size += $stats->size;

		if ($stats->lastUploaded !== null && ($this->lastUploaded === null || $stats->lastUploaded > $this->lastUploaded)) {
			$this->lastUploaded = $stats->lastUploaded;
		}

		return $this;
	}
}
