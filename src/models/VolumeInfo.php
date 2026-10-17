<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\models\Volume;

/**
 * What Hub knows of a volume: the fields relating to it, its file kinds, and the counts of its assets.
 */
class VolumeInfo
{
	use LazyProperties;

	/** @var FieldInterface[] Assets fields that can relate to assets of this volume */
	public array $assetFields = [];

	/** @var FieldInterface[] Link fields that can link to assets of this volume */
	public array $linkFields = [];

	/** @var array<string,AssetStatistics> file kind => stats, largest first */
	public array $kinds = [];

	public bool $hasUrls = false;

	public AssetStatistics $stats;

	/** references from Assets and Link fields into this volume */
	public ReferenceStatistics $references;

	public function __construct(public Volume $volume)
	{
		$this->stats = new AssetStatistics();
		$this->references = new ReferenceStatistics();
	}

	public function isEmpty(): bool
	{
		return $this->stats->total === 0;
	}

	/**
	 * @return string[]
	 */
	public function getKindValues(): array
	{
		return array_keys($this->kinds);
	}
}
