<?php

namespace SomehowDigital\Craft\Hub\models;

use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\services\CheckService;

/**
 * Filters of the fields list. Lists hold their values as they appear in the URL; empty lists and null values don't filter.
 */
class FieldFilter
{
	/** matched against the name and handle */
	public string $search = '';

	/** @var string[] IDs of the labels, or `none` for unlabeled items, see LabelService::getFilterValues() */
	public array $labels = [];

	/** UID of a custom saved filter, whose condition the items match, see FilterService */
	public ?string $savedFilter = null;

	/** @var string[] lowercased field type names */
	public array $types = [];

	/** @var string[] kinds of data, see {@see DataKind} */
	public array $kinds = [];

	/** @var string[] lowercased types of the layouts the fields are placed in, any of which matches */
	public array $owners = [];

	/** number of layouts the fields are placed in */
	public ?int $layoutsMin = null;
	public ?int $layoutsMax = null;

	/** only fields not placed in any layout */
	public bool $unused = false;

	/** only fields placed in layouts, but without data */
	public bool $empty = false;
}
