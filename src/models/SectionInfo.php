<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\models\EntryType;
use craft\models\Section;

class SectionInfo
{
	/** @var EntryType[] */
	public array $entryTypes = [];

	/** @var FieldInterface[] Entries fields that can reference entries of this section */
	public array $referenceFields = [];

	/** @var FieldInterface[] Link fields that can link to entries of this section */
	public array $linkFields = [];

	public bool $hasUrls = false;

	public EntryStatistics $stats;

	/** relations from Entries and Link fields into this section */
	public RelationStatistics $relations;

	public function __construct(public Section $section)
	{
		$this->stats = new EntryStatistics();
		$this->relations = new RelationStatistics();
	}

	public function isEmpty(): bool
	{
		return $this->stats->total === 0;
	}
}
