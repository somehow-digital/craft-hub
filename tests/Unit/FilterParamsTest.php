<?php

use SomehowDigital\Craft\Hub\helpers\FilterParams;

it('reads the search, flags and choices of a list', function () {
	$params = new FilterParams(['q' => '  news ', 'drafts' => '1', 'empty' => 'yes', 'urls' => 'no', 'type' => ['channel']]);

	expect($params->search())->toBe('news')
		->and($params->flag('drafts'))->toBeTrue()
		->and($params->flag('empty'))->toBeFalse()
		->and($params->choice('urls', ['yes', 'no']))->toBe('no')
		->and($params->choice('urls', ['yes']))->toBeNull()
		->and($params->string('type'))->toBeNull()
		->and((new FilterParams([]))->search())->toBe('');
});

it('reads comma-separated values and IDs, without duplicates', function () {
	$params = new FilterParams(['volume' => 'images, documents,,images', 'author' => '1,x,-2,4']);

	expect($params->list('volume'))->toBe(['images', 'documents'])
		->and($params->list('volume', ['documents', 'other']))->toBe(['documents'])
		->and($params->list('missing'))->toBe([])
		->and($params->ids('author'))->toBe([1, 4]);
});

it('reads non-negative numbers of range filters', function () {
	$params = new FilterParams(['sizeMin' => '2.5', 'sizeMax' => '-1', 'width' => 'wide']);

	expect($params->number('sizeMin'))->toBe(2.5)
		->and($params->int('sizeMin'))->toBe(2)
		->and($params->number('sizeMax'))->toBeNull()
		->and($params->int('width'))->toBeNull();
});

it('reads days of date range filters in the time zone of the user', function () {
	$params = new FilterParams(['from' => '2026-03-01', 'to' => '03/01/2026']);

	expect($params->date('from')?->format('Y-m-d H:i'))->toBe('2026-03-01 00:00')
		->and($params->date('from')?->getTimezone()->getName())->toBe(Craft::$app->getTimeZone())
		->and($params->date('to'))->toBeNull()
		->and($params->date('missing'))->toBeNull();
});
