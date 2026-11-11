<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;

/**
 * The selection of a list on a page for its bulk actions, and whether only the selected items are shown, to review them
 * before applying an action. Nothing is selected in lists without actions, nor by users who may not bulk-edit the list,
 * who apply the actions to one item at a time, so they don't review a selection either.
 *
 * @internal
 */
final readonly class ListSelection
{
	/** query param of a list showing only its selected items, to review them before applying a bulk action */
	public const PARAM = 'selection';

	/**
	 * @param array<string,array<string,mixed>> $actions bulk actions of the list, see BulkActions::getActions()
	 * @param bool $multiple whether several items can be selected, see BulkActions::canSelect()
	 * @param int[] $ids IDs of the selected items
	 * @param bool $review whether only the selected items are shown
	 */
	public function __construct(
		public string $list,
		public string $path,
		public array $actions,
		public bool $multiple,
		public array $ids,
		public bool $review,
	) {}

	/**
	 * Limits a query to the selected items while reviewing them.
	 *
	 * @param ElementQuery<array-key,covariant ElementInterface> $query
	 */
	public function limitQuery(ElementQuery $query): void
	{
		if ($this->review) {
			$query->id($this->ids !== [] ? $this->ids : [0]);
		}
	}

	/**
	 * Limits the items of a list, keyed by their IDs, to the selected ones while reviewing them.
	 *
	 * @template T
	 * @param array<int,T> $items
	 * @return array<int,T>
	 */
	public function limitItems(array $items): array
	{
		return $this->review ? array_intersect_key($items, array_flip($this->ids)) : $items;
	}
}
