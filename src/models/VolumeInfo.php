<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\base\Model;
use craft\models\Volume;
use LogicException;

/**
 * What Hub knows of a volume: the fields relating to it, its file kinds, and the counts of its assets.
 *
 * @property AssetStatistics $stats
 * @property ReferenceStatistics $references references from Assets and Link fields into this volume
 * @property array<string,AssetStatistics> $kinds file kind => stats, largest first
 *
 * @internal
 */
final class VolumeInfo extends Model
{
	use LazyPropertiesTrait;

	/** @var FieldInterface[] Assets fields that can relate to assets of this volume */
	public array $assetFields = [];

	/** @var FieldInterface[] Link fields that can link to assets of this volume */
	public array $linkFields = [];

	public bool $hasUrls = false;

	public function __construct(
		public Volume $volume
	) {
		parent::__construct();
		$this->setStats(new AssetStatistics());
		$this->setReferences(new ReferenceStatistics());
		$this->setKinds([]);
	}

	public function getStats(): AssetStatistics
	{
		$value = $this->getLazyValue('stats');
		if (!$value instanceof AssetStatistics) {
			throw new LogicException('Not AssetStatistics.');
		}

		return $value;
	}

	public function setStats(AssetStatistics $value): void
	{
		$this->setLazyValue('stats', $value);
	}

	/**
	 * references from Assets and Link fields into this volume
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

	/** file kind => stats, largest first */
	/**
	 * @return array<string,AssetStatistics>
	 */
	public function getKinds(): array
	{
		/** @var array<string,AssetStatistics> $value */
		$value = $this->getLazyValue('kinds');

		return $value;
	}

	/**
	 * @param array<string,AssetStatistics> $value
	 */
	public function setKinds(array $value): void
	{
		$this->setLazyValue('kinds', $value);
	}

	public function isEmpty(): bool
	{
		return $this->stats->total === 0;
	}

	/**
	 * @return list<string>
	 */
	public function getKindValues(): array
	{
		return array_keys($this->kinds);
	}
}
