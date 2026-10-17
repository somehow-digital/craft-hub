<?php

use SomehowDigital\Craft\Hub\helpers\Range;
use yii\db\Expression;

it('has no condition for open bounds', function () {
	expect(Range::condition('assets.size', null, null))->toBeNull();
});

it('includes both bounds', function () {
	expect(Range::condition('assets.size', 1, 5))->toBe(['and', ['>=', 'assets.size', 1], ['<=', 'assets.size', 5]]);
});

it('leaves out an open bound', function () {
	expect(Range::condition('assets.size', 1.5, null))->toBe(['and', ['>=', 'assets.size', 1.5], []])
		->and(Range::condition('assets.size', null, 0))->toBe(['and', [], ['<=', 'assets.size', 0]]);
});

it('compares expressions', function () {
	$expression = new Expression('COUNT(*)');

	expect(Range::condition($expression, 2, null)[1])->toBe(['>=', $expression, 2]);
});

it('has no date param for open bounds', function () {
	expect(Range::dateParam(null, null))->toBeNull();
});

it('includes the whole days of both bounds', function () {
	$from = new DateTime('2024-01-10 15:30:00', new DateTimeZone('UTC'));
	$to = new DateTime('2024-01-20 08:00:00', new DateTimeZone('UTC'));

	expect(Range::dateParam($from, $to))->toBe(['and', '>= 2024-01-10T00:00:00+00:00', '< 2024-01-21T00:00:00+00:00'])
		->and($from->format('H:i'))->toBe('15:30')
		->and($to->format('d'))->toBe('20');
});

it('leaves out an open date bound', function () {
	$day = new DateTime('2024-02-29', new DateTimeZone('Europe/Vienna'));

	expect(Range::dateParam($day, null))->toBe(['and', '>= 2024-02-29T00:00:00+01:00'])
		->and(Range::dateParam(null, $day))->toBe(['and', '< 2024-03-01T00:00:00+01:00']);
});
