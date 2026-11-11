<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\migrations;

use craft\db\Migration;
use craft\db\Table;
use SomehowDigital\Craft\Hub\db\Tables;

/**
 * Creates the tables of the labels, the items labeled with them, the maintainers of the structure, the checks of the
 * diagnostics tabs, the saved filters of the lists, the activity log, the reference tags in the content, the last edits
 * of entries, the snapshots of the statistics and the files kept of trashed assets.
 *
 * @internal
 */
final class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->createLabelTables();
		$this->createMaintainerTable();
		$this->createCheckTable();
		$this->createFilterTable();
		$this->createActivityTable();
		$this->createContentTables();
		$this->createSnapshotTables();
		$this->createKeptFilesTable();
		$this->createSnoozeTable();

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Tables::SNOOZES);
		$this->dropTableIfExists(Tables::KEPT_FILES);
		$this->dropTableIfExists(Tables::SNAPSHOT_REFERENCES);
		$this->dropTableIfExists(Tables::SNAPSHOT_ASSETS);
		$this->dropTableIfExists(Tables::SNAPSHOT_ENTRIES);
		$this->dropTableIfExists(Tables::SNAPSHOTS);
		$this->dropTableIfExists(Tables::EDITS);
		$this->dropTableIfExists(Tables::REFERENCES);
		$this->dropTableIfExists(Tables::ACTIVITY);
		$this->dropTableIfExists(Tables::FILTERS);
		$this->dropTableIfExists(Tables::CHECKS);
		$this->dropTableIfExists(Tables::MAINTAINERS);
		$this->dropTableIfExists(Tables::LABELED);
		$this->dropTableIfExists(Tables::LABELS);

		return true;
	}

	/**
	 * Creates the table of what users snoozed for a check. Checks are told by their key, as default ones may have no row.
	 */
	private function createSnoozeTable(): void
	{
		$this->createTable(Tables::SNOOZES, [
			'userId' => $this->integer()->notNull(),
			'elementId' => $this->integer()->notNull(),
			'checkKey' => $this->string(100)->notNull(),
			'dateUntil' => $this->dateTime(),
			'PRIMARY KEY([[userId]], [[elementId]], [[checkKey]])',
		]);

		$this->createIndex(null, Tables::SNOOZES, ['dateUntil']);
		$this->createIndex(null, Tables::SNOOZES, ['elementId']);
		$this->addForeignKey(null, Tables::SNOOZES, ['userId'], Table::USERS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Tables::SNOOZES, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
	}

	private function createLabelTables(): void
	{
		$this->createTable(Tables::LABELS, [
			'id' => $this->primaryKey(),
			'name' => $this->string()->notNull(),
			'description' => $this->text(),
			'icon' => $this->string(),
			'color' => $this->string(16),
			'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		// Items are those of a list (see ColumnService::LIST_*) by their ID, as they aren't all elements.
		$this->createTable(Tables::LABELED, [
			'id' => $this->primaryKey(),
			'labelId' => $this->integer()->notNull(),
			'list' => $this->string(32)->notNull(),
			'itemId' => $this->integer()->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Tables::LABELED, ['labelId', 'list', 'itemId'], true);
		$this->createIndex(null, Tables::LABELED, ['list', 'itemId']);
		$this->addForeignKey(null, Tables::LABELED, ['labelId'], Tables::LABELS, ['id'], 'CASCADE');
	}

	private function createMaintainerTable(): void
	{
		// Items are those of a list (see ColumnService::LIST_*) by their ID, like labeled ones; each row is a user or a group.
		$this->createTable(Tables::MAINTAINERS, [
			'id' => $this->primaryKey(),
			'list' => $this->string(32)->notNull(),
			'itemId' => $this->integer()->notNull(),
			'userId' => $this->integer(),
			'groupId' => $this->integer(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Tables::MAINTAINERS, ['list', 'itemId', 'userId'], true);
		$this->createIndex(null, Tables::MAINTAINERS, ['list', 'itemId', 'groupId'], true);
		$this->createIndex(null, Tables::MAINTAINERS, ['userId']);
		$this->createIndex(null, Tables::MAINTAINERS, ['groupId']);
		$this->addForeignKey(null, Tables::MAINTAINERS, ['userId'], Table::USERS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Tables::MAINTAINERS, ['groupId'], Table::USERGROUPS, ['id'], 'CASCADE');
	}

	private function createCheckTable(): void
	{
		// Default checks have a row once configured, by their handle; custom ones have none, but a name and their filter.
		$this->createTable(Tables::CHECKS, [
			'id' => $this->primaryKey(),
			'kind' => $this->string(32)->notNull(),
			'handle' => $this->string(64),
			'filter' => $this->string(64),
			'name' => $this->string(),
			'description' => $this->text(),
			'passedDescription' => $this->text(),
			'icon' => $this->string(),
			'severity' => $this->string(16),
			'enabled' => $this->boolean()->notNull()->defaultValue(true),
			'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Tables::CHECKS, ['kind', 'handle'], true);
	}

	private function createFilterTable(): void
	{
		// Predefined filters have a row once configured, by their handle, with their threshold; custom ones have a condition,
		// and users' own ones the filters of the list they were saved with, along with the user.
		$this->createTable(Tables::FILTERS, [
			'id' => $this->primaryKey(),
			'kind' => $this->string(32)->notNull(),
			'handle' => $this->string(64),
			'userId' => $this->integer(),
			'name' => $this->string(),
			'description' => $this->text(),
			'icon' => $this->string(),
			'params' => $this->text(),
			'matchCondition' => $this->text(),
			'threshold' => $this->integer(),
			'enabled' => $this->boolean()->notNull()->defaultValue(true),
			'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Tables::FILTERS, ['kind', 'handle'], true);
		$this->createIndex(null, Tables::FILTERS, ['userId', 'kind']);
		$this->addForeignKey(null, Tables::FILTERS, ['userId'], Table::USERS, ['id'], 'CASCADE');
	}

	private function createActivityTable(): void
	{
		// Users and subjects are kept by their ID and name, without foreign keys, so activities outlive them. Activities of a
		// batch, like those of a bulk action, refer to the batch's own activity.
		$this->createTable(Tables::ACTIVITY, [
			'id' => $this->primaryKey(),
			'batchId' => $this->integer(),
			'action' => $this->string(32)->notNull(),
			'subject' => $this->string(32)->notNull(),
			'subjectId' => $this->integer(),
			'subjectTitle' => $this->string(),
			'siteId' => $this->integer(),
			'userId' => $this->integer(),
			'userName' => $this->string(),
			'ipAddress' => $this->string(45),
			'data' => $this->text(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Tables::ACTIVITY, ['dateCreated']);
		$this->createIndex(null, Tables::ACTIVITY, ['userId']);
		$this->createIndex(null, Tables::ACTIVITY, ['subject', 'subjectId']);
		$this->createIndex(null, Tables::ACTIVITY, ['action']);
		$this->createIndex(null, Tables::ACTIVITY, ['batchId']);
	}

	/**
	 * Creates the tables of the reference tags and the last edits, records the last edits of the existing entries and
	 * indexes their reference tags in the queue, which may take a while.
	 */
	private function createContentTables(): void
	{
		$this->createTable(Tables::REFERENCES, [
			'id' => $this->primaryKey(),
			'sourceId' => $this->integer()->notNull(),
			'sourceSiteId' => $this->integer()->notNull(),
			'fieldId' => $this->integer()->notNull(),
			'targetId' => $this->integer()->notNull(),
		]);

		$this->createIndex(null, Tables::REFERENCES, ['sourceId', 'sourceSiteId', 'fieldId', 'targetId'], true);
		$this->createIndex(null, Tables::REFERENCES, ['targetId']);
		$this->addForeignKey(null, Tables::REFERENCES, ['sourceId'], Table::ELEMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Tables::REFERENCES, ['sourceSiteId'], Table::SITES, ['id'], 'CASCADE');
		$this->addForeignKey(null, Tables::REFERENCES, ['fieldId'], Table::FIELDS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Tables::REFERENCES, ['targetId'], Table::ELEMENTS, ['id'], 'CASCADE');

		$this->createTable(Tables::EDITS, [
			'elementId' => $this->integer()->notNull(),
			'userId' => $this->integer(),
			'dateEdited' => $this->dateTime()->notNull(),
			'PRIMARY KEY([[elementId]])',
		]);

		$this->createIndex(null, Tables::EDITS, ['dateEdited']);
		$this->createIndex(null, Tables::EDITS, ['userId']);
		$this->addForeignKey(null, Tables::EDITS, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Tables::EDITS, ['userId'], Table::USERS, ['id'], 'SET NULL');
	}

	/**
	 * Creates the tables of the snapshots and their counts. Counts are kept by the IDs of what they're of, without foreign
	 * keys, so they outlive it, and by the sets of sites their items are on.
	 */
	private function createSnapshotTables(): void
	{
		$this->createTable(Tables::SNAPSHOTS, [
			'id' => $this->primaryKey(),
			'date' => $this->date()->notNull(),
			'metrics' => $this->text(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Tables::SNAPSHOTS, ['date'], true);

		$this->createTable(Tables::SNAPSHOT_ENTRIES, [
			'id' => $this->primaryKey(),
			'snapshotId' => $this->integer()->notNull(),
			'sectionId' => $this->integer(),
			'typeId' => $this->integer()->notNull(),
			'fieldId' => $this->integer(),
			'sites' => $this->text()->notNull(),
			'enabledSites' => $this->text(),
			'total' => $this->integer()->notNull()->defaultValue(0),
			'enabled' => $this->integer()->notNull()->defaultValue(0),
			'pending' => $this->integer()->notNull()->defaultValue(0),
			'expired' => $this->integer()->notNull()->defaultValue(0),
			'drafts' => $this->integer()->notNull()->defaultValue(0),
			'lastUpdated' => $this->dateTime(),
		]);

		$this->createTable(Tables::SNAPSHOT_ASSETS, [
			'id' => $this->primaryKey(),
			'snapshotId' => $this->integer()->notNull(),
			'volumeId' => $this->integer()->notNull(),
			'kind' => $this->string(50)->notNull(),
			'sites' => $this->text()->notNull(),
			'usedSites' => $this->text(),
			'altSites' => $this->text(),
			'total' => $this->integer()->notNull()->defaultValue(0),
			'size' => $this->bigInteger()->notNull()->defaultValue(0),
			'lastUploaded' => $this->dateTime(),
		]);

		$this->createTable(Tables::SNAPSHOT_REFERENCES, [
			'id' => $this->primaryKey(),
			'snapshotId' => $this->integer()->notNull(),
			'fieldId' => $this->integer()->notNull(),
			'sectionId' => $this->integer(),
			'volumeId' => $this->integer(),
			'siteId' => $this->integer(),
			'references' => $this->integer()->notNull()->defaultValue(0),
			'sources' => $this->integer()->notNull()->defaultValue(0),
			'targets' => $this->integer()->notNull()->defaultValue(0),
		]);

		foreach ([Tables::SNAPSHOT_ENTRIES, Tables::SNAPSHOT_ASSETS, Tables::SNAPSHOT_REFERENCES] as $table) {
			$this->createIndex(null, $table, ['snapshotId']);
			$this->addForeignKey(null, $table, ['snapshotId'], Tables::SNAPSHOTS, ['id'], 'CASCADE');
		}
	}

	/**
	 * Creates the table of the files kept of trashed assets, by their volume and path, without foreign keys, so they outlive
	 * the assets, whose files are deleted once Craft's garbage collection deleted them permanently.
	 */
	private function createKeptFilesTable(): void
	{
		$this->createTable(Tables::KEPT_FILES, [
			'assetId' => $this->integer()->notNull(),
			'volumeId' => $this->integer()->notNull(),
			'path' => $this->string(1000)->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
			'PRIMARY KEY([[assetId]])',
		]);
	}
}
