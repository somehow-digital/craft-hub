<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\db;

/**
 * The tables of the plugin, which its migration creates and its services query.
 *
 * @internal
 */
final class Tables
{
	public const ACTIVITY = '{{%hub_activity}}';
	public const CHECKS = '{{%hub_checks}}';
	public const EDITS = '{{%hub_edits}}';
	public const FILTERS = '{{%hub_filters}}';
	public const KEPT_FILES = '{{%hub_kept_files}}';
	public const LABELED = '{{%hub_labeled}}';
	public const LABELS = '{{%hub_labels}}';
	public const MAINTAINERS = '{{%hub_maintainers}}';
	public const REFERENCES = '{{%hub_references}}';
	public const SNAPSHOTS = '{{%hub_snapshots}}';
	public const SNAPSHOT_ASSETS = '{{%hub_snapshot_assets}}';
	public const SNAPSHOT_ENTRIES = '{{%hub_snapshot_entries}}';
	public const SNAPSHOT_REFERENCES = '{{%hub_snapshot_references}}';
	public const SNOOZES = '{{%hub_snoozes}}';
}
