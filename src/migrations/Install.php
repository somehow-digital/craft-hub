<?php

namespace SomehowDigital\Craft\Hub\migrations;

use craft\db\Migration;
use craft\db\Table;
use craft\helpers\Queue;
use SomehowDigital\Craft\Hub\jobs\IndexReferences;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\DiagnosticsService;
use SomehowDigital\Craft\Hub\services\EditService;
use SomehowDigital\Craft\Hub\services\FilterService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use SomehowDigital\Craft\Hub\services\SnapshotService;

/**
 * Creates the tables of the labels, the items labeled with them, the checks of the diagnostics tabs, the saved filters of
 * the lists, the activity log, the reference tags in the content, the last edits of entries and the snapshots of the statistics.
 */
class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->createLabelTables();
		$this->createCheckTable();
		$this->createFilterTable();
		$this->createActivityTable();
		$this->createContentTables();
		$this->createSnapshotTables();

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(SnapshotService::TABLE_REFERENCES);
		$this->dropTableIfExists(SnapshotService::TABLE_ASSETS);
		$this->dropTableIfExists(SnapshotService::TABLE_ENTRIES);
		$this->dropTableIfExists(SnapshotService::TABLE);
		$this->dropTableIfExists(EditService::TABLE);
		$this->dropTableIfExists(ReferenceService::TABLE);
		$this->dropTableIfExists(ActivityService::TABLE);
		$this->dropTableIfExists(FilterService::TABLE);
		$this->dropTableIfExists(DiagnosticsService::TABLE_CHECKS);
		$this->dropTableIfExists(LabelService::TABLE_LABELED);
		$this->dropTableIfExists(LabelService::TABLE_LABELS);

		return true;
	}

	private function createLabelTables(): void
	{
		$this->createTable(LabelService::TABLE_LABELS, [
			'id' => $this->primaryKey(),
			'name' => $this->string()->notNull(),
			'icon' => $this->string(),
			'color' => $this->string(16),
			'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		// Items are those of a list (see ColumnService::LIST_*) by their ID, as they aren't all elements.
		$this->createTable(LabelService::TABLE_LABELED, [
			'id' => $this->primaryKey(),
			'labelId' => $this->integer()->notNull(),
			'list' => $this->string(32)->notNull(),
			'itemId' => $this->integer()->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, LabelService::TABLE_LABELED, ['labelId', 'list', 'itemId'], true);
		$this->createIndex(null, LabelService::TABLE_LABELED, ['list', 'itemId']);
		$this->addForeignKey(null, LabelService::TABLE_LABELED, ['labelId'], LabelService::TABLE_LABELS, ['id'], 'CASCADE');
	}

	private function createCheckTable(): void
	{
		// Default checks have a row once configured, by their handle; custom ones have none, but a name and their filter.
		$this->createTable(DiagnosticsService::TABLE_CHECKS, [
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

		$this->createIndex(null, DiagnosticsService::TABLE_CHECKS, ['kind', 'handle'], true);
	}

	private function createFilterTable(): void
	{
		// Predefined filters have a row once configured, by their handle, with their threshold; custom ones have a condition,
		// and users' own ones the filters of the list they were saved with, along with the user.
		$this->createTable(FilterService::TABLE, [
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

		$this->createIndex(null, FilterService::TABLE, ['kind', 'handle'], true);
		$this->createIndex(null, FilterService::TABLE, ['userId', 'kind']);
		$this->addForeignKey(null, FilterService::TABLE, ['userId'], Table::USERS, ['id'], 'CASCADE');
	}

	private function createActivityTable(): void
	{
		// Users and subjects are kept by their ID and name, without foreign keys, so activities outlive them. Activities of a
		// batch, like those of a bulk action, refer to the batch's own activity.
		$this->createTable(ActivityService::TABLE, [
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

		$this->createIndex(null, ActivityService::TABLE, ['dateCreated']);
		$this->createIndex(null, ActivityService::TABLE, ['userId']);
		$this->createIndex(null, ActivityService::TABLE, ['subject', 'subjectId']);
		$this->createIndex(null, ActivityService::TABLE, ['action']);
		$this->createIndex(null, ActivityService::TABLE, ['batchId']);
	}

	/**
	 * Creates the tables of the reference tags and the last edits, records the last edits of the existing entries and
	 * indexes their reference tags in the queue, which may take a while.
	 */
	private function createContentTables(): void
	{
		$this->createTable(ReferenceService::TABLE, [
			'id' => $this->primaryKey(),
			'sourceId' => $this->integer()->notNull(),
			'sourceSiteId' => $this->integer()->notNull(),
			'fieldId' => $this->integer()->notNull(),
			'targetId' => $this->integer()->notNull(),
		]);

		$this->createIndex(null, ReferenceService::TABLE, ['sourceId', 'sourceSiteId', 'fieldId', 'targetId'], true);
		$this->createIndex(null, ReferenceService::TABLE, ['targetId']);
		$this->addForeignKey(null, ReferenceService::TABLE, ['sourceId'], Table::ELEMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, ReferenceService::TABLE, ['sourceSiteId'], Table::SITES, ['id'], 'CASCADE');
		$this->addForeignKey(null, ReferenceService::TABLE, ['fieldId'], Table::FIELDS, ['id'], 'CASCADE');
		$this->addForeignKey(null, ReferenceService::TABLE, ['targetId'], Table::ELEMENTS, ['id'], 'CASCADE');

		Queue::push(new IndexReferences());

		$this->createTable(EditService::TABLE, [
			'elementId' => $this->integer()->notNull(),
			'userId' => $this->integer(),
			'dateEdited' => $this->dateTime()->notNull(),
			'PRIMARY KEY([[elementId]])',
		]);

		$this->createIndex(null, EditService::TABLE, ['dateEdited']);
		$this->addForeignKey(null, EditService::TABLE, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, EditService::TABLE, ['userId'], Table::USERS, ['id'], 'SET NULL');

		(new EditService())->backfill();
	}

	/**
	 * Creates the tables of the snapshots and their counts. Counts are kept by the IDs of what they're of, without foreign
	 * keys, so they outlive it, and by the sets of sites their items are on (see SiteSet).
	 */
	private function createSnapshotTables(): void
	{
		$this->createTable(SnapshotService::TABLE, [
			'id' => $this->primaryKey(),
			'date' => $this->date()->notNull(),
			'metrics' => $this->text(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, SnapshotService::TABLE, ['date'], true);

		$this->createTable(SnapshotService::TABLE_ENTRIES, [
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

		$this->createTable(SnapshotService::TABLE_ASSETS, [
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

		$this->createTable(SnapshotService::TABLE_REFERENCES, [
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

		foreach ([SnapshotService::TABLE_ENTRIES, SnapshotService::TABLE_ASSETS, SnapshotService::TABLE_REFERENCES] as $table) {
			$this->createIndex(null, $table, ['snapshotId']);
			$this->addForeignKey(null, $table, ['snapshotId'], SnapshotService::TABLE, ['id'], 'CASCADE');
		}
	}
}
