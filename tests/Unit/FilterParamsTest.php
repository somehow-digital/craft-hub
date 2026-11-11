<?php

declare(strict_types=1);

use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FilterParams;

it('reads the search, flags and choices of a list', function (): void {
	$params = new FilterParams(['q' => '  news ', 'drafts' => '1', 'empty' => 'yes', 'urls' => 'no', 'type' => ['channel']], Craft::$app->getTimeZone());

	expect($params->search())->toBe('news')
		->and($params->flag('drafts'))->toBeTrue()
		->and($params->flag('empty'))->toBeFalse()
		->and($params->choice('urls', ['yes', 'no']))->toBe('no')
		->and($params->choice('urls', ['yes']))->toBeNull()
		->and($params->string('type'))->toBeNull()
		->and((new FilterParams([], Craft::$app->getTimeZone()))->search())->toBe('');
});

it('reads comma-separated values and IDs, without duplicates', function (): void {
	$params = new FilterParams(['volume' => 'images, documents,,images', 'author' => '1,x,-2,4'], Craft::$app->getTimeZone());

	expect($params->list('volume'))->toBe(['images', 'documents'])
		->and($params->list('volume', ['documents', 'other']))->toBe(['documents'])
		->and($params->list('missing'))->toBe([])
		->and($params->ids('author'))->toBe([1, 4]);
});

it('reads the users of a filter by users, with `me` first', function (): void {
	$params = new FilterParams(['author' => '4,me,x,me,0', 'editor' => '2'], Craft::$app->getTimeZone());

	expect($params->users('author'))->toBe(['me', 4])
		->and($params->users('editor'))->toBe([2])
		->and($params->users('missing'))->toBe([]);
});

it('resolves `me` to the current user, or to no one without one', function (): void {
	expect(Hub::getInstance()->users->resolveIds(['me', 4]))->toBe([0, 4]);

	mocked(Craft::$app->getUser())->shouldReceive('getId')->andReturn(7);

	expect(Hub::getInstance()->users->resolveIds(['me', 4]))->toBe([7, 4]);
});

it('reads non-negative numbers of range filters', function (): void {
	$params = new FilterParams(['sizeMin' => '2.5', 'sizeMax' => '-1', 'width' => 'wide'], Craft::$app->getTimeZone());

	expect($params->number('sizeMin'))->toBe(2.5)
		->and($params->int('sizeMin'))->toBe(2)
		->and($params->number('sizeMax'))->toBeNull()
		->and($params->int('width'))->toBeNull();
});

it('reads days of date range filters in the time zone of the user', function (): void {
	$params = new FilterParams(['from' => '2026-03-01', 'to' => '03/01/2026'], Craft::$app->getTimeZone());

	expect($params->date('from')?->format('Y-m-d H:i'))->toBe('2026-03-01 00:00')
		->and($params->date('from')?->getTimezone()->getName())->toBe(Craft::$app->getTimeZone())
		->and($params->date('to'))->toBeNull()
		->and($params->date('missing'))->toBeNull();
});

it('leaves the site scope and the page out of the params of a list', function (): void {
	expect(FilterParams::listParams(['hubSite' => 'en', 'site' => 'en', 'page' => '2', 'panel' => 'sites', 'q' => 'news', 'sort' => 'name']))->toBe(['q' => 'news', 'sort' => 'name'])
		->and(FilterParams::listParams(null))->toBe([])
		->and(FilterParams::listParams('q=news'))->toBe([]);
});
