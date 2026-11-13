<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\elements\db;

use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\db\Tables;

/**
 * Entries sorted by when they were last edited, see the edit service. There may be many entries to sort, for which joining the
 * edits is several times faster than looking them up for each entry, and the sort can only be joined here, as prepared
 * element queries are put together from a subquery.
 *
 * @internal
 * @extends EntryQuery<array-key,Entry>
 */
final class LastEditedEntryQuery extends EntryQuery
{
	public const LAST_EDITED = 'COALESCE([[hubLastEdits.dateEdited]], [[elements.dateUpdated]])';

	protected function afterPrepare(): bool
	{
		$join = '[[hubLastEdits.elementId]] = [[elements.id]]';
		$this->subQuery?->leftJoin(['hubLastEdits' => Tables::EDITS], $join);
		$this->query?->leftJoin(['hubLastEdits' => Tables::EDITS], $join);

		return parent::afterPrepare();
	}
}
