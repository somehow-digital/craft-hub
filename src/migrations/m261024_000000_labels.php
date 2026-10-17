<?php

namespace SomehowDigital\Craft\Hub\migrations;

use craft\db\Migration;

/**
 * Adds the labels to installs from before them; new installs get them from {@see Install}.
 */
class m261024_000000_labels extends Migration
{
	public function safeUp(): bool
	{
		return (new Install(['db' => $this->db]))->safeUp();
	}

	public function safeDown(): bool
	{
		return (new Install(['db' => $this->db]))->safeDown();
	}
}
