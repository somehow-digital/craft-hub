<?php

use SomehowDigital\Craft\Hub\helpers\Paginator;

it('paginates lists filtered in memory, keeping their keys', function (int $page, int $currentPage, array $keys) {
	$items = array_combine(range(101, 220), range(1, 120));
	[$pageInfo, $results] = (new Paginator($page))->items($items);

	expect(array_keys($results))->toBe($keys)
		->and($pageInfo->currentPage)->toBe($currentPage)
		->and($pageInfo->totalPages)->toBe(3)
		->and($pageInfo->total)->toBe(120)
		->and($pageInfo->first)->toBe(($currentPage - 1) * Paginator::PAGE_SIZE + 1)
		->and($pageInfo->last)->toBe(($currentPage - 1) * Paginator::PAGE_SIZE + count($keys));
})->with([
	'first page' => [1, 1, range(101, 150)],
	'last page' => [3, 3, range(201, 220)],
	'beyond the last page' => [9, 3, range(201, 220)],
	'before the first page' => [0, 1, range(101, 150)],
]);

it('paginates empty lists into a single page', function () {
	[$pageInfo, $results] = (new Paginator(4))->items([]);

	expect($results)->toBe([])
		->and($pageInfo->currentPage)->toBe(1)
		->and($pageInfo->totalPages)->toBe(1)
		->and($pageInfo->total)->toBe(0);
});

it('paginates by the page size given', function () {
	[$pageInfo, $results] = (new Paginator(2, 3))->items(['a', 'b', 'c', 'd', 'e']);

	expect($results)->toBe([3 => 'd', 4 => 'e'])
		->and($pageInfo->totalPages)->toBe(2)
		->and($pageInfo->first)->toBe(4)
		->and($pageInfo->last)->toBe(5);
});
