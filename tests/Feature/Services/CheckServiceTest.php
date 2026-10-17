<?php

use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->createContentModel();

	$this->checks = new CheckService();
	$this->structure = $this->hub()->structure;
});

/**
 * @param Check[] $checks
 * @return array<string,Check> keyed by the key of their check
 */
function byKey(array $checks): array
{
	return array_column(array_map(fn (Check $check) => ['key' => $check->key, 'check' => $check], $checks), 'check', 'key');
}

/**
 * The keys of the failed checks, in order.
 *
 * @param Check[] $checks
 * @return string[]
 */
function failedChecks(array $checks): array
{
	return array_values(array_map(fn (Check $check) => (string)$check->key, array_filter($checks, fn (Check $check) => !$check->passed)));
}

it('checks sections by their filters, counting the entries flagged by the checks of entries', function () {
	$this->db()->respondToCounts(fn () => [['sectionId' => 1, 'typeId' => 10, 'total' => 2]]);

	$news = byKey($this->checks->forSection($this->structure->getSection(1)));
	$archive = $this->checks->forSection($this->structure->getSection(4));

	expect(array_keys($news))->toBe(['entries.unreferenced', 'entries.stale', 'entries.drafts', 'entries.disabled', 'sections.empty'])
		->and($news['sections.empty']->passed)->toBeTrue()
		->and($news['entries.drafts']->heading)->toBe('Entries with drafts')
		->and($news['entries.drafts']->count)->toBe(2)
		->and($news['entries.drafts']->url)->toContain('hub/structure/sections/1/entries')
		->and(failedChecks($archive))->toBe(['sections.empty'])
		->and(byKey($archive)['sections.empty']->severity)->toBe(Severity::Warning);
});

it('computes the checks of an item once', function () {
	$info = $this->structure->getSection(1);

	expect($this->checks->forSection($info))->toBe($this->checks->forSection($info))
		->and($this->db()->counts())->toHaveCount(4);
});

it('leaves out links to lists the user cannot open', function () {
	$this->actingAs(permissions: []);

	expect(byKey($this->checks->forSection($this->structure->getSection(1)))['entries.drafts']->url)->toBeNull();
});

it('checks entry types, counting their entries only if they are assigned', function () {
	$orphan = byKey($this->checks->forEntryType($this->structure->getEntryType(13)));
	$article = byKey($this->checks->forEntryType($this->structure->getEntryType(10)));

	expect(failedChecks($orphan))->toBe(['entryTypes.unused'])
		->and($orphan)->not->toHaveKey('entries.drafts')
		->and($article['entryTypes.unused']->passed)->toBeTrue()
		->and($article['entries.drafts']->url)->toContain('hub/structure/entry-types/10/entries');
});

it('checks fields', function () {
	$checks = byKey($this->checks->forField($this->structure->getField(10)));

	expect(failedChecks($checks))->toBe(['fields.unused'])
		->and($checks['fields.unused']->severity)->toBe(Severity::Error)
		->and($checks['fields.unused']->heading)->toBe('Usage');
});

it('checks volumes, counting the assets flagged by the checks of assets', function () {
	$this->db()->respondToCounts(fn () => [['volumeId' => 1, 'total' => 3]]);

	$checks = byKey($this->checks->forVolume($this->hub()->volumes->getVolume(1)));

	expect($checks['assets.unused']->count)->toBe(3)
		->and($checks['assets.unused']->passed)->toBeFalse()
		->and($checks['assets.unused']->url)->toContain('hub/structure/volumes/1/assets')
		->and($checks)->toHaveKey('volumes.empty');
});

it('checks entries by the filters matching them', function () {
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => $query->status === ['disabled'] ? [new Entry(['id' => 1, 'siteId' => 1])] : []);

	$checks = byKey($this->checks->forEntry(new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1])));

	expect(failedChecks($checks))->toBe(['entries.disabled'])
		->and($checks['entries.disabled']->heading)->toBe('Disabled')
		->and($checks['entries.drafts']->passedDescription)->toBe('The entry has no drafts.');
});

it('checks files and users', function () {
	ElementQueries::respond(Asset::class, []);
	ElementQueries::respond(User::class, []);

	expect(failedChecks($this->checks->forFile(new Asset(['id' => 1]))))->toBe([])
		->and(array_keys(byKey($this->checks->forUser(new User(['id' => 1])))))->toContain('users.locked');
});

it('leaves out disabled checks and checks custom ones', function () {
	$this->db()->respond('FROM {{%hub_checks}}', [
		['id' => 1, 'kind' => 'sections', 'handle' => 'empty', 'filter' => null, 'severity' => null, 'enabled' => 0, 'sortOrder' => 1, 'uid' => 'empty'],
		[
			'id' => 2, 'kind' => 'sections', 'handle' => null, 'filter' => 'withoutUrls', 'name' => 'Public', 'description' => 'No URLs.', 'passedDescription' => null,
			'icon' => null, 'severity' => 'warning', 'enabled' => 1, 'sortOrder' => 2, 'uid' => 'public',
		],
	]);

	$checks = byKey($this->checks->forSection($this->structure->getSection(3)));

	expect($checks)->not->toHaveKey('sections.empty')
		->and($checks['sections.public']->passed)->toBeFalse()
		->and($checks['sections.public']->severity)->toBe(Severity::Warning)
		->and($checks['sections.public']->passedDescription)->toBe('Not flagged by this check.');
});
