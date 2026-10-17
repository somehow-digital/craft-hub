<?php

namespace SomehowDigital\Craft\Hub\models;

use DateTime;
use SomehowDigital\Craft\Hub\services\CheckService;

/**
 * Filters of the entries list. Lists hold their values as they appear in the URL; empty lists and null values don't filter.
 */
class EntryFilter
{
	/** matched against the title and slug */
	public string $search = '';

	/** @var string[] section handles */
	public array $sections = [];

	/** @var string[] entry type handles */
	public array $types = [];

	/** whether the entries are section entries or nested entries (`section` or `nested`) */
	public ?string $origin = null;

	/** @var string[] handles of the fields owning the nested entries */
	public array $fields = [];

	/** @var string[] entry statuses (`live`, `pending`, `expired`, `disabled`) */
	public array $statuses = [];

	/** @var int[] IDs of the entries' authors */
	public array $authors = [];

	/** whether the entries are referenced through a relation field (`referenced` or `unreferenced`) */
	public ?string $usage = null;

	public ?int $referencesMin = null;
	public ?int $referencesMax = null;

	/** post dates, both days included */
	public ?DateTime $postedFrom = null;
	public ?DateTime $postedTo = null;

	/** dates of the last update, both days included */
	public ?DateTime $updatedFrom = null;
	public ?DateTime $updatedTo = null;

	/** only entries not updated within this many months */
	public ?int $staleMonths = null;

	/** only entries with drafts */
	public bool $drafts = false;

	/** @var string[] severities of the most urgent failed check, or `passed`, see {@see CheckService::getDiagnoses()} */
	public array $diagnoses = [];
}
