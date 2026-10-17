<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\Model;

class Settings extends Model
{
	/**
	 * Entries not updated within this many months are listed as stale.
	 */
	public int $staleThresholdMonths = 12;

	/**
	 * Files larger than this many megabytes are listed as large.
	 */
	public int $largeFileThresholdMb = 5;

	/**
	 * Images wider or taller than this many pixels are listed as oversized.
	 */
	public int $largeImageThresholdPx = 4000;

	/**
	 * Active users who haven't signed in within this many months are listed as idle.
	 */
	public int $idleUserThresholdMonths = 12;

	protected function defineRules(): array
	{
		return [
			[['staleThresholdMonths', 'largeFileThresholdMb', 'largeImageThresholdPx', 'idleUserThresholdMonths'], 'integer', 'min' => 1],
		];
	}

	public function getLargeFileThreshold(): int
	{
		return $this->largeFileThresholdMb * 1024 * 1024;
	}
}
