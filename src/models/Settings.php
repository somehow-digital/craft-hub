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
	 * Whether the index and detail pages have a statistics panel, summarizing their items.
	 */
	public bool $enableStatistics = true;

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
			[['enableLabels', 'enableDiagnostics', 'enableStatistics', 'enableActivity', 'activityDrafts'], 'boolean'],
			[['activityRetentionDays'], 'integer', 'min' => 0],
		];
	}
}
