<?php

namespace SomehowDigital\Craft\Hub\models;

class RelationStatistics
{
	public int $relations = 0;
	public int $sources = 0;
	public int $targets = 0;

	public function add(self $stats): self
	{
		$this->relations += $stats->relations;
		$this->sources += $stats->sources;
		$this->targets += $stats->targets;

		return $this;
	}
}
