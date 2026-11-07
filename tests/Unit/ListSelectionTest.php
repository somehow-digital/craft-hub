<?php

declare(strict_types=1);

use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\helpers\ListSelection;

$selection = fn(array $ids, bool $review, array $actions = ['delete' => []]): ListSelection => new ListSelection('entries', 'hub/data/entries', $actions, true, $ids, $review);

it('limits the items of a list to the selected ones while reviewing them', function () use ($selection): void {
	$items = [1 => 'a', 2 => 'b', 3 => 'c'];

	expect($selection([3, 1], true)->limitItems($items))->toBe([1 => 'a', 3 => 'c'])
		->and($selection([3, 1], false)->limitItems($items))->toBe($items)
		->and($selection([], true)->limitItems($items))->toBe([]);
});

it('limits a query to the selected items while reviewing them, matching none without any', function () use ($selection): void {
	$query = function (ListSelection $selection): EntryQuery {
		$query = new EntryQuery(Entry::class);
		$selection->limitQuery($query);

		return $query;
	};

	expect($query($selection([3, 1], true))->id)->toBe([3, 1])
		->and($query($selection([], true))->id)->toBe([0])
		->and($query($selection([3, 1], false))->id)->toBeNull();
});

it('has no bulk variables for lists without actions', function () use ($selection): void {
	expect($selection([1], false, [])->variables(new stdClass()))->toBe(['bulkActions' => [], 'bulkSelection' => null]);
});
