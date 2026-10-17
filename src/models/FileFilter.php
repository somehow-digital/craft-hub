<?php

namespace SomehowDigital\Craft\Hub\models;

use DateTime;
use SomehowDigital\Craft\Hub\services\CheckService;

/**
 * Filters of the files list. Lists hold their values as they appear in the URL; empty lists and null values don't filter.
 */
class FileFilter
{
	public const ORIENTATION_LANDSCAPE = 'landscape';
	public const ORIENTATION_PORTRAIT = 'portrait';
	public const ORIENTATION_SQUARE = 'square';

	/** matched against the filename and title */
	public string $search = '';

	/** @var string[] IDs of the labels, or `none` for unlabeled items, see LabelService::getFilterValues() */
	public array $labels = [];

	/** @var string[] volume handles */
	public array $volumes = [];

	/** @var string[] file kinds */
	public array $kinds = [];

	/** @var string[] lowercased filename extensions */
	public array $extensions = [];

	/** @var string[] image orientations, see the `ORIENTATION_*` constants */
	public array $orientations = [];

	/** @var int[] IDs of the users who uploaded the files */
	public array $uploaders = [];

	/** whether the files are referenced through a relation field (`used` or `unused`) */
	public ?string $usage = null;

	public ?int $referencesMin = null;
	public ?int $referencesMax = null;

	/** file size in megabytes */
	public ?float $sizeMin = null;
	public ?float $sizeMax = null;

	/** image dimensions in pixels */
	public ?int $widthMin = null;
	public ?int $widthMax = null;
	public ?int $heightMin = null;
	public ?int $heightMax = null;

	/** upload dates, both days included */
	public ?DateTime $uploadedFrom = null;
	public ?DateTime $uploadedTo = null;

	/** only images without alternative text */
	public bool $missingAlt = false;

	/** only images wider or taller than the large image threshold */
	public bool $oversized = false;

	/**
	 * @return string[]
	 */
	public static function getOrientations(): array
	{
		return [self::ORIENTATION_LANDSCAPE, self::ORIENTATION_PORTRAIT, self::ORIENTATION_SQUARE];
	}
}
