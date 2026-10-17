<?php

namespace SomehowDigital\Craft\Hub\models;

/**
 * Counts of the references of a set of elements, their sources and targets.
 */
class ReferenceStatistics
{
	public int $references = 0;
	public int $sources = 0;
	public int $targets = 0;

	public function add(self $stats): self
	{
		$this->references += $stats->references;
		$this->sources += $stats->sources;
		$this->targets += $stats->targets;

		return $this;
	}
}
