<?php

namespace SomehowDigital\Craft\Hub\models;

use DateTime;

/**
 * Filters of the trashed files list. Lists hold their values as they appear in the URL; empty lists and null values don't filter.
 */
class TrashedFileFilter
{
	/** matched against the filename and title */
	public string $search = '';

	/** @var string[] volume handles */
	public array $volumes = [];

	/** @var string[] file kinds */
	public array $kinds = [];

	/** whether the files were kept when they were deleted, so they can be restored (`kept` or `deleted`) */
	public ?string $file = null;

	/** dates the files were deleted, both days included */
	public ?DateTime $deletedFrom = null;
	public ?DateTime $deletedTo = null;
}
