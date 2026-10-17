<?php

use SomehowDigital\Craft\Hub\helpers\Aggregator;

$rows = [
	['sectionId' => 1, 'typeId' => 10, 'total' => 3],
	['sectionId' => 1, 'typeId' => 11, 'total' => 4],
	['sectionId' => 2, 'typeId' => 10, 'total' => 5],
	['sectionId' => null, 'typeId' => 10, 'total' => 7],
];

$sum = fn (?int $sum, array $row) => ($sum ?? 0) + $row['total'];

it('sums rows by any combination of criteria', function () use ($rows, $sum) {
	$sums = Aggregator::index($rows, ['sectionId', 'typeId'], $sum);

	expect($sums[Aggregator::key([null, null])])->toBe(19);
	expect($sums[Aggregator::key([1, null])])->toBe(7);
	expect($sums[Aggregator::key([null, 10])])->toBe(15);
	expect($sums[Aggregator::key([1, 10])])->toBe(3);
	expect($sums[Aggregator::key([2, 11])] ?? null)->toBeNull();
});

it('leaves rows out of sums by a criterion they have no value for', function () use ($rows, $sum) {
	$sums = Aggregator::index($rows, ['sectionId', 'typeId'], $sum);

	expect($sums[Aggregator::key([1, null])] + $sums[Aggregator::key([2, null])])->toBe(12);
	expect(count($sums))->toBe(8);
});

it('matches string criteria against database values', function () use ($sum) {
	$sums = Aggregator::index([['volumeId' => '3', 'kind' => 'image', 'total' => 2]], ['volumeId', 'kind'], $sum);

	expect($sums[Aggregator::key([3, 'image'])])->toBe(2);
	expect($sums[Aggregator::key([3, null])])->toBe(2);
});
