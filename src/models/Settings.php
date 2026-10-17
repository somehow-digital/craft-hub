<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\Model;

class Settings extends Model
{
	/**
	 * Whether the items of the lists can be labeled and the lists filtered by their labels; the labels can be managed either way.
	 */
	public bool $enableLabels = true;

	/**
	 * Whether the index and detail pages have a diagnostics tab, listing the checks of their items.
	 */
	public bool $enableDiagnostics = true;

	/**
	 * Whether the index and detail pages have a statistics tab, summarizing their items.
	 */
	public bool $enableStatistics = true;

	/**
	 * Whether snapshots of the statistics are taken, so the statistics tabs show how they changed, see SnapshotService.
	 */
	public bool $enableSnapshots = true;

	/**
	 * A snapshot is taken every this many days.
	 */
	public int $snapshotInterval = 1;

	/**
	 * Snapshots are kept for this many days, deleted along with Craft's garbage; 0 keeps them forever.
	 */
	public int $snapshotRetentionDays = 730;

	/**
	 * Whether what users do is recorded in the activity log, which users who may view it find below the "Hub" navigation item.
	 */
	public bool $enableActivity = true;

	/**
	 * Activities are kept for this many days, deleted along with Craft's garbage; 0 keeps them forever.
	 */
	public int $activityRetentionDays = 90;

	/**
	 * Whether drafts created and deleted are recorded in the activity log too, rather than only once they're applied.
	 */
	public bool $activityDrafts = false;

	protected function defineRules(): array
	{
		return [
			[['enableLabels', 'enableDiagnostics', 'enableStatistics', 'enableSnapshots', 'enableActivity', 'activityDrafts'], 'boolean'],
			[['activityRetentionDays', 'snapshotRetentionDays'], 'integer', 'min' => 0],
			[['snapshotInterval'], 'integer', 'min' => 1],
		];
	}
}
