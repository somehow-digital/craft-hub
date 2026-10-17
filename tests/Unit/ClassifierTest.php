<?php

use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\helpers\Classifier;

$sections = [
	'a' => 'single',
	'b' => 'channel',
	'c' => 'structure',
	'd' => 'single',
];

it('resolves all sections for a wildcard', function () use ($sections) {
	expect(Classifier::resolveSectionUids('*', $sections))->toBe(['a', 'b', 'c', 'd']);
	expect(Classifier::resolveSectionUids(['*'], $sections))->toBe(['a', 'b', 'c', 'd']);
});

it('resolves explicit section sources and skips unknown ones', function () use ($sections) {
	expect(Classifier::resolveSectionUids(['section:b', 'section:x', 'section:c'], $sections))->toBe(['b', 'c']);
});

it('resolves singles to all single sections', function () use ($sections) {
	expect(Classifier::resolveSectionUids(['singles', 'section:a'], $sections))->toBe(['a', 'd']);
});

it('resolves nothing for empty sources', function () use ($sections) {
	expect(Classifier::resolveSectionUids(null, $sections))->toBe([]);
	expect(Classifier::resolveSectionUids([], $sections))->toBe([]);
});

it('resolves all volumes for a wildcard', function () {
	expect(Classifier::resolveVolumeUids('*', ['a', 'b']))->toBe(['a', 'b']);
	expect(Classifier::resolveVolumeUids(['*'], ['a', 'b']))->toBe(['a', 'b']);
});

it('resolves explicit volume sources and skips unknown or other ones', function () {
	expect(Classifier::resolveVolumeUids(['volume:b', 'volume:x', 'folder:a', 'temp'], ['a', 'b']))->toBe(['b']);
});

it('resolves no volumes for empty sources', function () {
	expect(Classifier::resolveVolumeUids(null, ['a']))->toBe([]);
	expect(Classifier::resolveVolumeUids([], ['a']))->toBe([]);
});

it('marks entry types without usages as unused', function () {
	expect(Classifier::entryTypeKinds(0, 0, 0, 0, false, 0))->toBe([UsageKind::Unused]);
});

it('marks used entry types without entries as empty', function () {
	expect(Classifier::entryTypeKinds(0, 2, 0, 0, false, 0))->toBe([UsageKind::Nested, UsageKind::Empty]);
});

it('combines multiple usage kinds', function () {
	expect(Classifier::entryTypeKinds(1, 1, 3, 2, true, 10))->toBe([
		UsageKind::Section,
		UsageKind::Reference,
		UsageKind::Link,
		UsageKind::Nested,
		UsageKind::HasUrls,
	]);
});

it('does not count link fields as an assignment', function () {
	expect(Classifier::entryTypeKinds(0, 0, 0, 1, false, 0))->toBe([UsageKind::Link, UsageKind::Unused]);
});

it('finds similar field handles', function () {
	expect(Classifier::similarHandles('teaserImage', 'teaser_image'))->toBeTrue();
	expect(Classifier::similarHandles('teaserImage', 'teaserImg'))->toBeTrue();
	expect(Classifier::similarHandles('link', 'links'))->toBeTrue();
	expect(Classifier::similarHandles('heroImage', 'heroImages'))->toBeTrue();
});

it('keeps different field handles apart', function () {
	expect(Classifier::similarHandles('text', 'test'))->toBeFalse();
	expect(Classifier::similarHandles('url', 'uri'))->toBeFalse();
	expect(Classifier::similarHandles('heroImage', 'teaserImage'))->toBeFalse();
	expect(Classifier::similarHandles('body', 'bodyText'))->toBeFalse();
});

it('detects generic filenames', function (string $filename) {
	expect(Classifier::isGenericFilename($filename))->toBeTrue();
})->with([
	'IMG_1234.jpg',
	'DSC01234.JPG',
	'PXL_20240101_101010123.jpg',
	'Screenshot 2024-01-01 at 10.00.00.png',
	'Bildschirmfoto 2024-01-01 um 10.00.00.png',
	'WhatsApp Image 2024-01-01 at 10.00.00.jpeg',
	'image (1).png',
	'untitled.pdf',
	'12345.jpg',
	'd41d8cd98f00b204e9800998ecf8427e.png',
	'3f2504e0-4f89-41d3-9a0c-0305e82c3301.webp',
]);

it('accepts descriptive filenames', function (string $filename) {
	expect(Classifier::isGenericFilename($filename))->toBeFalse();
})->with([
	'team-photo-2024.jpg',
	'image-gallery-header.png',
	'imagery.jpg',
	'annual-report.pdf',
	'logo.svg',
]);
