<?php

namespace SomehowDigital\Craft\Hub\models;

use SomehowDigital\Craft\Hub\services\CheckService;

/**
 * Filters of the sections list. Lists hold their values as they appear in the URL; empty lists and null values don't filter.
 */
class SectionFilter
{
	/** matched against the name and handle */
	public string $search = '';

	/** @var string[] IDs of the labels, or `none` for unlabeled items, see LabelService::getFilterValues() */
	public array $labels = [];

	/** UID of a custom saved filter, whose condition the items match, see FilterService */
	public ?string $savedFilter = null;

	/** @var string[] section types */
	public array $types = [];

	/** whether the sections have URLs (`yes` or `no`) */
	public ?string $urls = null;

	public ?int $entriesMin = null;
	public ?int $entriesMax = null;

	public ?int $referencesMin = null;
	public ?int $referencesMax = null;

	/** @var string[] how recently the entries were updated, see the `ACTIVITY_*` constants of {@see EntryStatistics} */
	public array $activities = [];

	/** only sections without entries */
	public bool $empty = false;
}
