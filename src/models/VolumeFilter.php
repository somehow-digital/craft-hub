<?php

namespace SomehowDigital\Craft\Hub\models;

use DateTime;

/**
 * Filters of the volumes list. Lists hold their values as they appear in the URL; empty lists and null values don't filter.
 */
class VolumeFilter
{
	/** matched against the name and handle */
	public string $search = '';

	/** @var string[] IDs of the labels, or `none` for unlabeled items, see LabelService::getFilterValues() */
	public array $labels = [];

	/** UID of a custom saved filter, whose condition the items match, see FilterService */
	public ?string $savedFilter = null;

	/** @var string[] file kinds the volumes hold, any of which matches */
	public array $kinds = [];

	/** whether the volumes have URLs (`yes` or `no`) */
	public ?string $urls = null;

	public ?int $assetsMin = null;
	public ?int $assetsMax = null;

	/** total size in megabytes */
	public ?float $sizeMin = null;
	public ?float $sizeMax = null;

	/** number of images without alternative text */
	public ?int $altMin = null;
	public ?int $altMax = null;

	/** dates of the last upload, both days included */
	public ?DateTime $uploadedFrom = null;
	public ?DateTime $uploadedTo = null;

	/** only volumes without assets */
	public bool $empty = false;
}
