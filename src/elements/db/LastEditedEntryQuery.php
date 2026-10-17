<?php

namespace SomehowDigital\Craft\Hub\elements\db;

use craft\elements\db\EntryQuery;
use SomehowDigital\Craft\Hub\services\EditService;

/**
 * Entries sorted by when they were last edited, see EditService. There may be many entries to sort, for which joining the
 * edits is several times faster than looking them up for each entry, and the sort can only be joined here, as prepared
 * element queries are put together from a subquery.
 */
class LastEditedEntryQuery extends EntryQuery
{
	public const LAST_EDITED = 'COALESCE([[hubLastEdits.dateEdited]], [[elements.dateUpdated]])';

	protected function afterPrepare(): bool
	{
		$join = '[[hubLastEdits.elementId]] = [[elements.id]]';
		$this->subQuery->leftJoin(['hubLastEdits' => EditService::TABLE], $join);
		$this->query->leftJoin(['hubLastEdits' => EditService::TABLE], $join);

		return parent::afterPrepare();
	}
}
