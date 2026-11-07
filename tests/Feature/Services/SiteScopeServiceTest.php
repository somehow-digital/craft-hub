<?php

declare(strict_types=1);

use SomehowDigital\Craft\Hub\services\SiteScopeService;

beforeEach(function (): void {
	$this->site(1, 'en', true);
	$this->site(2, 'de');
});

it('keeps the selected sites for the Hub pages opened without any', function (): void {
	$this->request(['hubSite' => 'de'], path: 'hub/data/entries');
	(new SiteScopeService())->getSites();

	$this->request(path: 'hub/structure/fields');

	expect((new SiteScopeService())->getSiteParam())->toBe('de');
});

it('forgets the kept sites as all sites are selected', function (): void {
	$this->session['hub:sites'] = 'de';

	$this->request(['hubSite' => ''], path: 'hub/data/entries');
	expect((new SiteScopeService())->getSites())->toBe([]);

	$this->request(path: 'hub/data/entries');
	expect((new SiteScopeService())->getSites())->toBe([]);
});

it('prefers the requested sites over those kept', function (): void {
	$this->session['hub:sites'] = 'de';
	$this->request(['hubSite' => 'en'], path: 'hub/data/entries');

	expect((new SiteScopeService())->getSiteParam())->toBe('en')
		->and($this->session['hub:sites'])->toBe('en');
});

it('leaves out kept sites the user may no longer edit', function (): void {
	$this->session['hub:sites'] = 'de';
	$this->editableSiteIds = [1];
	$this->request(path: 'hub/data/entries');

	expect((new SiteScopeService())->getSites())->toBe([])
		->and($this->session)->not->toHaveKey('hub:sites');
});

it('ignores and keeps the kept sites outside of Hub', function (string $path, bool $cp): void {
	$this->session['hub:sites'] = 'de';
	$this->request(cp: $cp, path: $path);

	expect((new SiteScopeService())->getSites())->toBe([])
		->and($this->session['hub:sites'])->toBe('de');
})->with([
	['dashboard', true],
	['hub', false],
]);
