<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\models\Volume;

class VolumeInfo
{
	/** @var FieldInterface[] Assets fields that can relate to assets of this volume */
	public array $assetFields = [];

	/** @var FieldInterface[] Link fields that can link to assets of this volume */
	public array $linkFields = [];

	/** @var array<string,AssetStatistics> file kind => stats, largest first */
	public array $kinds = [];

	public bool $hasUrls = false;

	public AssetStatistics $stats;

	/** relations from Assets and Link fields into this volume */
	public RelationStatistics $relations;

	public function __construct(public Volume $volume)
	{
		$this->stats = new AssetStatistics();
		$this->relations = new RelationStatistics();
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
