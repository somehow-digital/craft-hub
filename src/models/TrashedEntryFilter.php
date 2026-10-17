<?php

namespace SomehowDigital\Craft\Hub\models;

use DateTime;

/**
 * Filters of the trashed entries list. Lists hold their values as they appear in the URL; empty lists and null values don't filter.
 */
class TrashedEntryFilter
{
	/** matched against the title and slug */
	public string $search = '';

	/** @var string[] section handles */
	public array $sections = [];

	/** @var string[] entry type handles */
	public array $types = [];

	/** dates the entries were deleted, both days included */
	public ?DateTime $deletedFrom = null;
	public ?DateTime $deletedTo = null;
}
