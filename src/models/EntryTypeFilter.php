<?php

namespace SomehowDigital\Craft\Hub\models;

use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\services\CheckService;

/**
 * Filters of the entry types list. Lists hold their values as they appear in the URL; empty lists and null values don't filter.
 */
class EntryTypeFilter
{
	/** matched against the name and handle */
	public string $search = '';

	/** @var string[] usage kinds, any of which matches, see {@see UsageKind} */
	public array $kinds = [];

	public ?int $entriesMin = null;
	public ?int $entriesMax = null;

	public ?int $relationsMin = null;
	public ?int $relationsMax = null;

	/** @var string[] how recently the entries were updated, see the `ACTIVITY_*` constants of {@see EntryStatistics} */
	public array $activities = [];

	/** @var string[] severities of the most urgent failed check, or `passed`, see {@see CheckService::getDiagnoses()} */
	public array $diagnoses = [];
}
