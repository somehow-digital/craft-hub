<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use craft\base\Model;
use DateTime;

/**
 * Filters of the trashed files list. Lists hold their values as they appear in the URL; empty lists and null values
 * don't filter.
 *
 * @internal
 */
final class TrashedFileFilter extends Model
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

	/**
	 * Whether any filter is set.
	 */
	public function isFiltered(): bool
	{
		return get_object_vars($this) !== get_object_vars(new self());
	}
}
