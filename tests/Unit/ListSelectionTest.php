<?php

declare(strict_types=1);

use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\ListSelection;

/**
 * @param list<int> $ids
 * @param array<string,array<string,mixed>> $actions
 */
function selectionOf(array $ids, bool $review, array $actions = ['delete' => []]): ListSelection
{
	return new ListSelection('entries', 'hub/data/entries', $actions, true, $ids, $review);
}

it('limits the items of a list to the selected ones while reviewing them', function (): void {
	$items = [1 => 'a', 2 => 'b', 3 => 'c'];

	expect(selectionOf([3, 1], true)->limitItems($items))->toBe([1 => 'a', 3 => 'c'])
		->and(selectionOf([3, 1], false)->limitItems($items))->toBe($items)
		->and(selectionOf([], true)->limitItems($items))->toBe([]);
});

it('limits a query to the selected items while reviewing them, matching none without any', function (): void {
	$query = function (ListSelection $selection): EntryQuery {
		$query = new EntryQuery(Entry::class);
		$selection->limitQuery($query);

		return $query;
	};

	expect($query(selectionOf([3, 1], true))->id)->toBe([3, 1])
		->and($query(selectionOf([], true))->id)->toBe([0])
		->and($query(selectionOf([3, 1], false))->id)->toBeNull();
});

it('has no bulk variables for lists without actions', function (): void {
	expect(Hub::getInstance()->selection->variables(selectionOf([1], false, []), new stdClass()))->toBe(['bulkActions' => [], 'bulkSelection' => null]);
});
