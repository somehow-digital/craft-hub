<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\models\EntryType;
use craft\models\Section;
use SomehowDigital\Craft\Hub\enums\DataKind;

/**
 * What Hub knows of a field: the layouts it's placed in, what it offers, and the data it holds.
 */
class FieldInfo
{
	use LazyProperties;

	/** @var LayoutUsage[] */
	public array $layouts = [];

	/** @var EntryType[] entry types available in a nested entry field */
	public array $entryTypes = [];

	/** @var Section[] sections an Entries or Link field can reference entries from */
	public array $sections = [];

	public bool $isNested = false;

	public bool $isRelational = false;

	public bool $hasUrls = false;

	public EntryStatistics $stats;

	public ReferenceStatistics $references;

	public function __construct(public FieldInterface $field)
	{
		$this->stats = new EntryStatistics();
		$this->references = new ReferenceStatistics();
	}

	public function getTypeName(): string
	{
		return $this->field::displayName();
	}

	public function getDataKind(): DataKind
	{
		return match (true) {
			$this->isNested => DataKind::Nested,
			$this->isRelational => DataKind::Relational,
			default => DataKind::Plain,
		};
	}

	public function getInstanceCount(): int
	{
		return array_sum(array_map(fn (LayoutUsage $usage) => $usage->instances, $this->layouts));
	}

	public function isUnused(): bool
	{
		return $this->layouts === [];
	}

	/**
	 * Whether the field is placed somewhere, but is empty (only tracked for nested and relational fields).
	 */
	public function isEmpty(): bool
	{
		if ($this->isUnused()) {
			return false;
		}

		if ($this->isNested) {
			return $this->stats->total === 0;
		}

		if ($this->isRelational) {
			return $this->references->references === 0;
		}

		return false;
	}

	/**
	 * @return string[]
	 */
	public function getOwnerTypes(): array
	{
		return array_values(array_unique(array_map(fn (LayoutUsage $usage) => $usage->ownerType, $this->layouts)));
	}
}
