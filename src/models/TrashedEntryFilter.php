<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use craft\base\Model;
use DateTime;

/**
 * Filters of the trashed entries list. Lists hold their values as they appear in the URL; empty lists and null values
 * don't filter.
 *
 * @internal
 */
final class TrashedEntryFilter extends Model
{
	/** matched against the title and slug */
	public string $search = '';

	/** @var string[] section handles */
	public array $sections = [];

	/** @var string[] handles of the fields owning the nested entries */
	public array $fields = [];

	/** @var string[] entry type handles */
	public array $types = [];

	/** dates the entries were deleted, both days included */
	public ?DateTime $deletedFrom = null;

	public ?DateTime $deletedTo = null;

	/**
	 * Whether any filter is set.
	 */
	public function isFiltered(): bool
	{
		return get_object_vars($this) !== get_object_vars(new self());
	}
}
