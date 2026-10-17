<?php

use SomehowDigital\Craft\Hub\helpers\SiteSet;

it('reads and writes sets of sites, sorted and without duplicates', function () {
	expect(SiteSet::parse('3,1'))->toBe([3, 1])
		->and(SiteSet::parse(null))->toBe([])
		->and(SiteSet::parse(''))->toBe([])
		->and(SiteSet::format([3, 1, 3]))->toBe('1,3')
		->and(SiteSet::format([]))->toBeNull();
});

it('stands for every site with a set of any site', function () {
	expect(SiteSet::format([2, SiteSet::ANY]))->toBe('0')
		->and(SiteSet::matches('0', [5]))->toBeTrue()
		->and(SiteSet::union('2', '0'))->toBe('0');
});

it('matches sets with any of the sites', function () {
	expect(SiteSet::matches('1,3', [3, 4]))->toBeTrue()
		->and(SiteSet::matches('1,3', [2]))->toBeFalse()
		->and(SiteSet::matches(null, [1]))->toBeFalse();
});

it('unites sets', function () {
	expect(SiteSet::union('3,1', '2'))->toBe('1,2,3')
		->and(SiteSet::union(null, '2'))->toBe('2')
		->and(SiteSet::union(null, null))->toBeNull();
});
