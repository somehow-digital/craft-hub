<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\base\Model;
use craft\models\EntryType;
use craft\models\Section;
use LogicException;

/**
 * What Hub knows of a section: its entry types, the fields relating to it, and the counts of its entries.
 *
 * @property EntryStatistics $stats
 * @property ReferenceStatistics $references references from Entries and Link fields into this section
 *
 * @internal
 */
final class SectionInfo extends Model
{
	use LazyPropertiesTrait;

	/** @var EntryType[] */
	public array $entryTypes = [];

	/** @var FieldInterface[] Entries fields that can reference entries of this section */
	public array $referenceFields = [];

	/** @var FieldInterface[] Link fields that can link to entries of this section */
	public array $linkFields = [];

	public bool $hasUrls = false;

	public function __construct(
		public Section $section
	) {
		parent::__construct();
		$this->setStats(new EntryStatistics());
		$this->setReferences(new ReferenceStatistics());
	}

	public function getStats(): EntryStatistics
	{
		$value = $this->getLazyValue('stats');
		if (!$value instanceof EntryStatistics) {
			throw new LogicException('Not EntryStatistics.');
		}

		return $value;
	}

	public function setStats(EntryStatistics $value): void
	{
		$this->setLazyValue('stats', $value);
	}

	/**
	 * references from Entries and Link fields into this section
	 */
	public function getReferences(): ReferenceStatistics
	{
		$value = $this->getLazyValue('references');
		if (!$value instanceof ReferenceStatistics) {
			throw new LogicException('Not ReferenceStatistics.');
		}

		return $value;
	}

	public function setReferences(ReferenceStatistics $value): void
	{
		$this->setLazyValue('references', $value);
	}

	public function isEmpty(): bool
	{
		return $this->stats->total === 0;
	}
}
