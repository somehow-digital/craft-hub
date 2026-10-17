<?php

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ReviewService;

beforeEach(function () {
	$this->createContentModel();

	// The review counts are tested on their own, here they are what the checks are made of.
	$this->review = Mockery::mock(ReviewService::class)->makePartial();
	$this->review->shouldReceive('countUnreferenced')->andReturn(0)->byDefault();
	$this->review->shouldReceive('countStale')->andReturn(0)->byDefault();
	$this->review->shouldReceive('countLargeFiles')->andReturn(0)->byDefault();
	$this->review->shouldReceive('countLargeImages')->andReturn(0)->byDefault();
	$this->review->shouldReceive('getReferencedMissingAlt')->andReturn([])->byDefault();
	$this->review->shouldReceive('getReferencedUnavailable')->andReturn([])->byDefault();
	$this->review->shouldReceive('getDraftCounts')->andReturn([])->byDefault();
	$this->hub()->set('review', $this->review);

	$this->checks = new CheckService();
	$this->structure = $this->hub()->structure;
});

/**
 * @param Check[] $checks
 * @return array<string,Check> keyed by heading
 */
function byHeading(array $checks): array
{
	return array_column(array_map(fn (Check $check) => ['heading' => $check->heading, 'check' => $check], $checks), 'check', 'heading');
}

/**
 * The headings of the failed checks, in order.
 *
 * @param Check[] $checks
 * @return string[]
 */
function failedChecks(array $checks): array
{
	return array_values(array_map(fn (Check $check) => $check->heading, array_filter($checks, fn (Check $check) => !$check->passed)));
}

it('checks sections with entries and URLs', function () {
	$this->request(path: 'hub/structure/sections/1/entries');
	$this->review->shouldReceive('countStale')->with(12, 1, null)->andReturn(2);
	$checks = $this->checks->forSection($this->structure->getSection(1));
	$byHeading = byHeading($checks);

	expect(array_keys($byHeading))->toBe(['Activity', 'Disabled entries', 'Drafts', 'Entries', 'Entry types'])
		->and($byHeading['Activity']->count)->toBe(2)
		->and($byHeading['Activity']->url)->toContain('list=stale')
		->and($byHeading['Activity']->url)->toContain('hub/structure/sections/1/diagnostics')
		->and($byHeading['Disabled entries']->count)->toBe(1)
		->and($byHeading['Disabled entries']->url)->toContain('hub/data/entries')
		->and($byHeading['Disabled entries']->url)->toContain('section=news')
		->and($byHeading['Disabled entries']->url)->toContain('status=disabled')
		->and($byHeading['Drafts']->url)->toContain('drafts=1')
		->and($byHeading['Entries']->passed)->toBeTrue()
		->and($byHeading['Entries']->url)->toContain('entries/news')
		->and($byHeading['Entry types']->passed)->toBeTrue();
});

it('computes the checks of a section once', function () {
	$info = $this->structure->getSection(1);

	expect($this->checks->forSection($info))->toBe($this->checks->forSection($info));
	$this->review->shouldHaveReceived('countStale')->once();
});

it('checks sections without URLs', function () {
	$this->review->shouldReceive('countUnreferenced')->with(3, null)->andReturn(1);
	$byHeading = byHeading($this->checks->forSection($this->structure->getSection(3)));

	expect($byHeading['Reachability']->passed)->toBeTrue()
		->and($byHeading['Reachability']->url)->toContain('settings/sections/3')
		->and($byHeading['References']->passed)->toBeFalse()
		->and($byHeading['References']->url)->toContain('list=unreferenced');
});

it('checks the entry types of sections', function () {
	$pages = byHeading($this->checks->forSection($this->structure->getSection(2)));
	$archive = byHeading($this->checks->forSection($this->structure->getSection(4)));

	expect(array_map(fn ($item) => $item->handle, $pages['Entry types']->items))->toBe(['article'])
		->and($pages['Entry types']->passed)->toBeFalse()
		->and($archive['Entries']->passed)->toBeFalse()
		->and($archive['Entries']->severity)->toBe(Severity::Warning)
		->and($archive['Entry types']->passedDescription)->toBe('Not checked, as the section has no entries.')
		->and($archive['Reachability']->passed)->toBeTrue();
});

it('leaves out links the user cannot open', function () {
	$this->actingAs(permissions: []);
	$byHeading = byHeading($this->checks->forSection($this->structure->getSection(3)));

	expect($byHeading['Entries']->url)->toBeNull()
		->and($byHeading['Reachability']->url)->toBeNull();
});

it('fails sections that cannot be reached', function () {
	// Without it, the Link field can relate to entries of any section.
	$this->fields[4]->types = ['url'];
	$this->section(5, 'hidden', craft\models\Section::TYPE_CHANNEL, [$this->entryTypes[13]], [1 => false]);

	$byHeading = byHeading($this->checks->forSection($this->structure->getSection(5)));

	expect($byHeading['Reachability']->passed)->toBeFalse();
});

it('sorts failed checks first, by severity', function () {
	$this->review->shouldReceive('countUnreferenced')->andReturn(1);
	$this->review->shouldReceive('countStale')->andReturn(1);

	expect(failedChecks($this->checks->forSection($this->structure->getSection(3))))->toBe(['References', 'Activity']);
});

it('checks entry types', function () {
	$this->review->shouldReceive('countUnreferenced')->with(null, 10)->andReturn(3);
	$article = byHeading($this->checks->forEntryType($this->structure->getEntryType(10)));

	expect(array_keys($article))->toBe(['References', 'Assignments', 'Fields', 'Disabled entries', 'Drafts', 'Usage', 'Entries', 'Activity'])
		->and($article['References']->count)->toBe(3)
		->and(array_map(fn ($item) => $item->handle, $article['Assignments']->items))->toBe(['pages'])
		->and(array_map(fn ($item) => $item->handle, $article['Fields']->items))->toBe(['teaserImage'])
		->and($article['Usage']->url)->toContain('settings/entry-types/10')
		->and($article['Disabled entries']->url)->toContain('type=article');
});

it('checks unused and empty entry types', function () {
	$orphan = byHeading($this->checks->forEntryType($this->structure->getEntryType(13)));
	$empty = byHeading($this->checks->forEntryType($this->structure->getEntryType(14)));
	$block = byHeading($this->checks->forEntryType($this->structure->getEntryType(12)));

	expect(failedChecks($orphan))->toBe(['Usage'])
		->and($orphan['Entries']->passedDescription)->toBe('Not checked, as the entry type is not assigned.')
		->and($orphan)->not->toHaveKey('References')
		->and(failedChecks($empty))->toBe(['Entries'])
		->and($empty['Assignments']->passedDescription)->toBe('Not checked, as no entries of this type exist.')
		->and($block)->not->toHaveKey('References')
		->and($block['Assignments']->passed)->toBeTrue();
});

it('links to the entries of entry types used by sections and fields alike', function () {
	$this->section(5, 'blocks', craft\models\Section::TYPE_CHANNEL, [$this->entryTypes[12]]);
	$this->db()->respondToEntryRows([
		['sectionId' => 5, 'typeId' => 12, 'fieldId' => null, 'total' => 1, 'enabled' => 0, 'drafts' => 0, 'lastUpdated' => null],
		['sectionId' => null, 'typeId' => 12, 'fieldId' => 2, 'total' => 0, 'enabled' => 0, 'drafts' => 0, 'lastUpdated' => null],
	]);

	$block = byHeading($this->checks->forEntryType($this->structure->getEntryType(12)));

	expect($block['Disabled entries']->passed)->toBeFalse()
		->and($block['Disabled entries']->url)->toContain('type=block')
		->and($block['Disabled entries']->url)->toContain('status=disabled')
		->and(array_map(fn ($item) => $item->handle, $block['Assignments']->items))->toBe(['blocks']);
});

it('checks unused fields', function () {
	$teaserImg = byHeading($this->checks->forField($this->structure->getField(10)));

	expect(array_keys($teaserImg))->toBe(['Usage', 'Similar fields', 'Content'])
		->and($teaserImg['Usage']->severity)->toBe(Severity::Error)
		->and(array_map(fn ($item) => $item->handle, $teaserImg['Similar fields']->items))->toBe(['teaserImage'])
		->and($teaserImg['Content']->passed)->toBeTrue()
		->and($teaserImg['Content']->passedDescription)->toBe('Not checked, as the field is not placed in any field layout.');
});

it('checks nested entry fields', function () {
	$blocks = byHeading($this->checks->forField($this->structure->getField(2)));
	$legacy = byHeading($this->checks->forField($this->structure->getField(7)));

	expect(failedChecks($blocks))->toBe(['Instructions'])
		->and($blocks['Content']->passedDescription)->toBe('The field holds 7 nested entries.')
		->and($blocks['Entry types']->passed)->toBeTrue()
		->and($blocks['Instructions']->url)->toContain('settings/fields/edit/2')
		->and($legacy)->not->toHaveKeys(['Content', 'Entry types']);
});

it('checks the entry types of nested entry fields', function () {
	$this->db()->respondToEntryRows([]);
	$blocks = byHeading($this->checks->forField($this->structure->getField(2)));

	expect($blocks['Content']->passed)->toBeFalse()
		->and($blocks['Entry types']->passedDescription)->toBe('Not checked, as the field holds no entries.');
});

it('checks relational fields', function () {
	$related = byHeading($this->checks->forField($this->structure->getField(3)));
	$cta = byHeading($this->checks->forField($this->structure->getField(4)));
	$summary = byHeading($this->checks->forField($this->structure->getField(1)));

	expect($related['Content']->passedDescription)->toBe('The field holds 4 references.')
		->and($related['Sources']->passed)->toBeTrue()
		->and(array_map(fn ($item) => $item->handle, $cta['Sources']->items))->toBe(['archive'])
		->and(failedChecks($summary))->toBe([])
		->and($summary)->not->toHaveKey('Content');
});

it('leaves out settings links for non-admins', function () {
	$this->actingAs();

	expect(byHeading($this->checks->forField($this->structure->getField(1)))['Usage']->url)->toBeNull();
});

it('checks volumes', function () {
	$this->db()->respond('FROM {{%assets}} {{assets}}', [
		['volumeId' => 1, 'kind' => 'image', 'total' => 4, 'used' => 1, 'images' => 4, 'missingAlt' => 2, 'size' => 100, 'lastUploaded' => null],
	]);
	$this->review->shouldReceive('countLargeFiles')->with(1)->andReturn(1);
	$this->review->shouldReceive('countLargeImages')->with(1)->andReturn(2);

	$images = byHeading($this->checks->forVolume($this->hub()->volumes->getVolume(1)));

	expect(failedChecks($images))->toBe(['Usage', 'Alternative text', 'File size', 'Image dimensions'])
		->and($images['Usage']->count)->toBe(3)
		->and($images['Usage']->url)->toContain('list=unused')
		->and($images['Alternative text']->url)->toContain('list=alt')
		->and($images['File size']->url)->toContain('list=large')
		->and($images['Image dimensions']->url)->toContain('oversized=1')
		->and($images['Image dimensions']->url)->toContain('volume=images')
		->and($images['Relations']->passed)->toBeTrue()
		->and($images['Assets']->url)->toContain('settings/assets/volumes/1');
});

it('checks empty volumes', function () {
	$volume = byHeading($this->checks->forVolume($this->hub()->volumes->getVolume(1)));

	expect(failedChecks($volume))->toBe(['Assets'])
		->and($volume['Alternative text']->passedDescription)->toBe('Not checked, as the volume has no images.');
});

it('checks files', function () {
	$this->actingAs(canSave: false);
	$asset = new Asset(['id' => 1, 'kind' => Asset::KIND_IMAGE, 'filename' => 'IMG_0001.jpg', 'size' => 6 * 1024 * 1024, 'width' => 5000, 'height' => 100]);
	$sites = [new Asset(['id' => 1, 'siteId' => 1, 'alt' => '']), new Asset(['id' => 1, 'siteId' => 2, 'alt' => 'Alt'])];

	$checks = byHeading($this->checks->forFile($asset, 0, $sites, [['error' => true], ['error' => false]]));

	expect(failedChecks($checks))->toBe(['Usage', 'File size', 'Transforms', 'Alternative text', 'Image dimensions', 'Filename'])
		->and($checks['Alternative text']->severity)->toBe(Severity::Notice)
		->and($checks['Alternative text']->description)->toBe('This image has no alternative text on these sites: En.')
		->and($checks['Transforms']->count)->toBe(1)
		->and($checks['File size']->url)->toBeNull();
});

it('checks files that pass', function () {
	$asset = new Asset(['id' => 1, 'kind' => Asset::KIND_PDF, 'filename' => 'annual-report.pdf', 'size' => 1024]);

	$checks = byHeading($this->checks->forFile($asset, 2, [], []));

	expect(failedChecks($checks))->toBe([])
		->and(array_keys($checks))->toBe(['Usage', 'File size', 'Filename'])
		->and($checks['Usage']->passedDescription)->toBe('2 elements reference this file.');
});

it('checks images without alternative text on any site', function () {
	$asset = new Asset(['id' => 1, 'kind' => Asset::KIND_IMAGE, 'filename' => 'team.jpg', 'size' => 1024, 'width' => 10, 'height' => 10]);

	$checks = byHeading($this->checks->forFile($asset, 1, [new Asset(['siteId' => 1])], [['error' => false]]));

	expect(failedChecks($checks))->toBe(['Alternative text'])
		->and($checks['Alternative text']->severity)->toBe(Severity::Warning)
		->and($checks['Transforms']->count)->toBeNull();
});

it('checks entries', function () {
	$this->actingAs(canSave: false);
	$entry = new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1]);
	$linked = new Entry(['id' => 2, 'siteId' => 1]);
	$image = new Asset(['id' => 3]);

	$this->review->shouldReceive('getReferencedMissingAlt')->andReturn([1 => [$image]]);
	$this->review->shouldReceive('getReferencedUnavailable')->andReturn([1 => [$linked]]);
	$this->review->shouldReceive('getDraftCounts')->andReturn([1 => 2]);

	$checks = byHeading($this->checks->forEntry($entry, 0, true));

	expect(failedChecks($checks))->toBe(['References', 'Images', 'Linked entries', 'Activity', 'Drafts'])
		->and($checks['Images']->items)->toBe([$image])
		->and($checks['Linked entries']->items)->toBe([$linked])
		->and($checks['Drafts']->count)->toBe(2)
		->and($checks['Activity']->url)->toBeNull();
});

it('checks entries that pass', function () {
	$entries = [new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1]), new Entry(['id' => 2, 'siteId' => 1, 'sectionId' => 1])];

	$results = $this->checks->forEntries($entries, [1 => 1], []);

	expect(array_keys($results))->toBe([1, 2])
		->and(failedChecks($results[1]))->toBe([])
		->and(failedChecks($results[2]))->toBe(['References']);
});

it('does not require references of nested entries', function () {
	$checks = byHeading($this->checks->forEntry(new Entry(['id' => 1, 'siteId' => 1, 'fieldId' => 2]), 0, false));

	expect($checks)->not->toHaveKey('References')
		->and(failedChecks($checks))->toBe([]);
});

it('does not require references of entries with URLs', function () {
	$entry = Mockery::mock(Entry::class)->makePartial();
	$entry->id = 1;
	$entry->shouldReceive('getUrl')->andReturn('https://example.test/en/news');
	$entry->shouldReceive('getCpEditUrl')->andReturn('https://example.test/admin/entries/1');

	$checks = byHeading($this->checks->forEntry($entry, 0, false));

	expect($checks)->not->toHaveKey('References')
		->and($checks['Activity']->url)->toBe('https://example.test/admin/entries/1')
		->and($checks['Drafts']->action)->toBe('Open drafts');
});

/**
 * An active user who signed in recently, unless said otherwise.
 *
 * @param array<string,mixed> $config
 */
function checkedUser(array $config = []): User
{
	return new User([
		'id' => 3,
		'siteId' => 1,
		'active' => true,
		'lastLoginDate' => new DateTime('-1 day'),
		'dateCreated' => new DateTime('-2 years'),
		...$config,
	]);
}

it('checks users that pass', function () {
	Craft::$app->edition = CmsEdition::Pro;
	$checks = $this->checks->forUser(checkedUser(), false);

	expect(array_keys(byHeading($checks)))->toBe(['Account', 'Sign-ins', 'Email'])
		->and(failedChecks($checks))->toBe([])
		->and($checks[0]->url)->toContain('users/3');
});

it('checks admins without two-step verification', function () {
	$checks = byHeading($this->checks->forUser(checkedUser(['admin' => true]), false));

	expect($checks['Two-step verification']->passed)->toBeFalse()
		->and($checks['Two-step verification']->severity)->toBe(Severity::Warning)
		->and(byHeading($this->checks->forUser(checkedUser(['admin' => true]), true))['Two-step verification']->passed)->toBeTrue();
});

it('checks accounts that cannot be used', function (array $config, Severity $severity, string $icon) {
	$checks = $this->checks->forUser(checkedUser($config), false);

	// Only active users are checked for their sign-ins and two-step verification.
	expect(failedChecks($checks))->toBe(['Account'])
		->and($checks[0]->severity)->toBe($severity)
		->and($checks[0]->icon)->toBe($icon)
		->and(array_keys(byHeading($checks)))->toBe(['Account', 'Email']);
})->with([
	// Locked users are unlocked once their cooldown passes, so they are locked out right now.
	'locked' => [['locked' => true, 'lockoutDate' => new DateTime(), 'suspended' => true, 'admin' => true], Severity::Warning, 'lock'],
	'suspended' => [['suspended' => true], Severity::Notice, 'ban'],
	'pending' => [['active' => false, 'pending' => true], Severity::Notice, 'envelope'],
]);

it('passes users who cannot sign in, but are kept as authors', function () {
	$checks = $this->checks->forUser(checkedUser(['active' => false]), false);

	expect(failedChecks($checks))->toBe([])
		->and($checks[0]->passedDescription)->toBe('The user can’t sign in, but is kept, e.g. as an author.');
});

it('checks users who have not signed in for a while', function (array $config, bool $passed, string $description) {
	$check = byHeading($this->checks->forUser(checkedUser($config), false))['Sign-ins'];

	expect($check->passed)->toBe($passed)
		->and($passed ? $check->passedDescription : $check->description)->toStartWith($description);
})->with([
	'idle' => [['lastLoginDate' => new DateTime('-13 months')], false, 'This user hasn’t signed in for 12 months.'],
	'never, created long ago' => [['lastLoginDate' => null], false, 'This user has never signed in.'],
	'never, created recently' => [['lastLoginDate' => null, 'dateCreated' => new DateTime('-1 week')], true, 'The user hasn’t signed in yet'],
]);

it('checks unverified email addresses', function () {
	$check = byHeading($this->checks->forUser(checkedUser(['unverifiedEmail' => 'new@example.test']), false))['Email'];

	expect($check->passed)->toBeFalse()
		->and($check->description)->toContain('new@example.test');
});

it('checks several users at once', function () {
	$results = $this->checks->forUsers([checkedUser(), checkedUser(['id' => 4, 'admin' => true])], [4 => true]);

	expect(array_keys($results))->toBe([3, 4])
		->and(array_keys(byHeading($results[4])))->toContain('Two-step verification');
});
