<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\base\Model;
use craft\models\EntryType;
use craft\models\Section;
use LogicException;
use SomehowDigital\Craft\Hub\enums\UsageKind;

/**
 * What Hub knows of an entry type: where it's used, and the counts of its entries.
 *
 * @property EntryStatistics $stats
 * @property list<UsageKind> $kinds
 *
 * @internal
 */
final class EntryTypeInfo extends Model
{
	use LazyPropertiesTrait;

	/** @var Section[] */
	public array $sections = [];

	/** @var FieldInterface[] nested entry fields (Matrix, CKEditor, …) using this entry type */
	public array $nestedFields = [];

	/** @var FieldInterface[] Entries fields that can reference entries of this type */
	public array $referenceFields = [];

	/** @var FieldInterface[] Link fields that can link to entries of this type */
	public array $linkFields = [];

	public bool $hasUrls = false;

	public function __construct(
		public EntryType $entryType
	) {
		parent::__construct();
		$this->setStats(new EntryStatistics());
		$this->setKinds([]);
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
	 * @return list<UsageKind>
	 */
	public function getKinds(): array
	{
		/** @var list<UsageKind> $value */
		$value = $this->getLazyValue('kinds');

		return $value;
	}

	/**
	 * @param list<UsageKind> $value
	 */
	public function setKinds(array $value): void
	{
		$this->setLazyValue('kinds', $value);
	}

	public function getRelationCount(): int
	{
		return count($this->nestedFields) + count($this->referenceFields) + count($this->linkFields);
	}

	/**
	 * @return list<string>
	 */
	public function getKindValues(): array
	{
		return array_map(fn(UsageKind $kind) => $kind->value, $this->kinds);
	}
}
