<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\models\EntryType;
use craft\models\Section;
use SomehowDigital\Craft\Hub\enums\UsageKind;

class EntryTypeInfo
{
	use LazyProperties;

	/** @var Section[] */
	public array $sections = [];

	/** @var FieldInterface[] nested entry fields (Matrix, CKEditor, …) using this entry type */
	public array $nestedFields = [];

	/** @var FieldInterface[] Entries fields that can reference entries of this type */
	public array $referenceFields = [];

	/** @var FieldInterface[] Link fields that can link to entries of this type */
	public array $linkFields = [];

	/** @var UsageKind[] */
	public array $kinds = [];

	public bool $hasUrls = false;

	public EntryStatistics $stats;

	public function __construct(public EntryType $entryType)
	{
		$this->stats = new EntryStatistics();
	}

	public function hasKind(UsageKind $kind): bool
	{
		return in_array($kind, $this->kinds, true);
	}

	public function getRelationCount(): int
	{
		return count($this->nestedFields) + count($this->referenceFields) + count($this->linkFields);
	}

	/**
	 * @return string[]
	 */
	public function getKindValues(): array
	{
		return array_map(fn (UsageKind $kind) => $kind->value, $this->kinds);
	}
}
