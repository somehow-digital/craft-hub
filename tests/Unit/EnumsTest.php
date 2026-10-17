<?php

use craft\models\Section;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\SectionType;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\enums\UsageKind;

it('sorts severities by urgency', function () {
	expect(Severity::sorted())->toBe([Severity::Error, Severity::Warning, Severity::Notice])
		->and(array_map(fn (Severity $severity) => $severity->rank(), Severity::sorted()))->toBe([0, 1, 2]);
});

it('describes severities', function (Severity $severity, string $label, string $color) {
	expect($severity->label())->toBe($label)
		->and($severity->color())->toBe($color);
})->with([
	[Severity::Error, 'Clean up', 'red'],
	[Severity::Warning, 'Needs attention', 'orange'],
	[Severity::Notice, 'Worth a look', 'blue'],
]);

it('lists all usage kinds but URLs', function () {
	expect(UsageKind::listed())->toBe([
		UsageKind::Section,
		UsageKind::Reference,
		UsageKind::Link,
		UsageKind::Nested,
		UsageKind::Unused,
		UsageKind::Empty,
	]);
});

it('describes usage kinds', function (UsageKind $kind, string $label, string $color, string $icon) {
	expect($kind->label())->toBe($label)
		->and($kind->description())->toEndWith('.')
		->and($kind->color())->toBe($color)
		->and($kind->icon())->toBe($icon);
})->with([
	[UsageKind::Section, 'Section', 'blue', 'layer-group'],
	[UsageKind::Reference, 'Reference', 'teal', 'link'],
	[UsageKind::Link, 'Link', 'cyan', 'arrow-up-right-from-square'],
	[UsageKind::Nested, 'Matrix', 'violet', 'diagram-nested'],
	[UsageKind::HasUrls, 'Has URLs', 'green', 'globe'],
	[UsageKind::Unused, 'Unused', 'red', 'ghost'],
	[UsageKind::Empty, 'Empty', 'orange', 'box-open'],
]);

it('describes usage kinds differently', function () {
	$descriptions = array_map(fn (UsageKind $kind) => $kind->description(), UsageKind::cases());

	expect(array_unique($descriptions))->toHaveCount(count(UsageKind::cases()));
});

it('describes data kinds like the usage kinds they hold', function (DataKind $kind, string $label, string $color, string $icon) {
	expect($kind->label())->toBe($label)
		->and($kind->color())->toBe($color)
		->and($kind->icon())->toBe($icon);
})->with([
	[DataKind::Nested, 'Nested entries', 'violet', 'diagram-nested'],
	[DataKind::Relational, 'References', 'teal', 'link'],
	[DataKind::Plain, 'Plain values', 'gray', 'font'],
]);

it('describes section types', function (SectionType $type, string $value, string $label, string $color, string $icon) {
	expect($type->value)->toBe($value)
		->and($type->label())->toBe($label)
		->and($type->color())->toBe($color)
		->and($type->icon())->toBe($icon);
})->with([
	[SectionType::Single, Section::TYPE_SINGLE, 'Single', 'sky', 'file'],
	[SectionType::Channel, Section::TYPE_CHANNEL, 'Channel', 'indigo', 'list'],
	[SectionType::Structure, Section::TYPE_STRUCTURE, 'Structure', 'teal', 'sitemap'],
]);
