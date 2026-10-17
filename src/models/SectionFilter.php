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

	/** @var string[] section types */
	public array $types = [];

	/** whether the sections have URLs (`yes` or `no`) */
	public ?string $urls = null;

	public ?int $entriesMin = null;
	public ?int $entriesMax = null;

	public ?int $relationsMin = null;
	public ?int $relationsMax = null;

	/** @var string[] how recently the entries were updated, see the `ACTIVITY_*` constants of {@see EntryStatistics} */
	public array $activities = [];

	/** only sections without entries */
	public bool $empty = false;

	/** @var string[] severities of the most urgent failed check, or `passed`, see {@see CheckService::getDiagnoses()} */
	public array $diagnoses = [];
}
