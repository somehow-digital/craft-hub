<?php

namespace SomehowDigital\Craft\Hub\migrations;

use craft\db\Migration;
use SomehowDigital\Craft\Hub\services\LabelService;

/**
 * Creates the tables of the labels and the items labeled with them.
 */
class Install extends Migration
{
	public function safeUp(): bool
	{
		if ($this->db->tableExists(LabelService::TABLE_LABELS)) {
			return true;
		}

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

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(LabelService::TABLE_LABELED);
		$this->dropTableIfExists(LabelService::TABLE_LABELS);

		return true;
	}
}
