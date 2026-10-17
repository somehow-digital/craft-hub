<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\models\EntryType;
use craft\models\Section;

/**
 * What Hub knows of a section: its entry types, the fields relating to it, and the counts of its entries.
 */
class SectionInfo
{
	use LazyProperties;

	/** @var EntryType[] */
	public array $entryTypes = [];

	/** @var FieldInterface[] Entries fields that can reference entries of this section */
	public array $referenceFields = [];

	/** @var FieldInterface[] Link fields that can link to entries of this section */
	public array $linkFields = [];

	public bool $hasUrls = false;

	public EntryStatistics $stats;

	/** references from Entries and Link fields into this section */
	public ReferenceStatistics $references;

	public function __construct(public Section $section)
	{
		$this->stats = new EntryStatistics();
		$this->references = new ReferenceStatistics();
	}

	public function isEmpty(): bool
	{
		return $this->stats->total === 0;
	}
}
