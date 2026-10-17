<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\models\Volume;

class FileKindInfo
{
	/** @var Volume[] volumes holding files of this kind */
	public array $volumes = [];

	public AssetStatistics $stats;

	public function __construct(public string $kind)
	{
		$this->stats = new AssetStatistics();
	}

	public function getLabel(): string
	{
		return AssetsHelper::getFileKindLabel($this->kind);
	}

	public function getIcon(): string
	{
		return match ($this->kind) {
			Asset::KIND_IMAGE, Asset::KIND_PHOTOSHOP, Asset::KIND_ILLUSTRATOR => 'image',
			Asset::KIND_VIDEO, Asset::KIND_FLASH => 'film',
			Asset::KIND_AUDIO => 'music',
			Asset::KIND_PDF => 'file-pdf',
			Asset::KIND_WORD, Asset::KIND_TEXT => 'file-lines',
			Asset::KIND_EXCEL, Asset::KIND_ACCESS => 'table',
			Asset::KIND_POWERPOINT => 'presentation-screen',
			Asset::KIND_COMPRESSED => 'file-zipper',
			Asset::KIND_HTML, Asset::KIND_JAVASCRIPT, Asset::KIND_JSON, Asset::KIND_PHP, Asset::KIND_XML => 'code',
			Asset::KIND_CAPTIONS_SUBTITLES => 'closed-captioning',
			default => 'file',
		};
	}

	public function getColor(): string
	{
		return match ($this->kind) {
			Asset::KIND_IMAGE, Asset::KIND_PHOTOSHOP, Asset::KIND_ILLUSTRATOR => 'sky',
			Asset::KIND_VIDEO, Asset::KIND_FLASH => 'violet',
			Asset::KIND_AUDIO => 'pink',
			Asset::KIND_PDF => 'red',
			Asset::KIND_WORD, Asset::KIND_TEXT => 'blue',
			Asset::KIND_EXCEL, Asset::KIND_ACCESS => 'green',
			Asset::KIND_POWERPOINT => 'orange',
			Asset::KIND_COMPRESSED => 'amber',
			Asset::KIND_HTML, Asset::KIND_JAVASCRIPT, Asset::KIND_JSON, Asset::KIND_PHP, Asset::KIND_XML => 'teal',
			default => 'gray',
		};
	}
}
