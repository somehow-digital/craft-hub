<?php

use SomehowDigital\Craft\Hub\helpers\Ids;

it('keeps the positive IDs of a list', function () {
	expect(Ids::positive(['3', 0, '-1', 'x', 2]))->toBe([3, 2]);
});

it('finds no IDs in anything else', function (mixed $value) {
	expect(Ids::positive($value))->toBe([]);
})->with([[null], ['1,2'], [5]]);
