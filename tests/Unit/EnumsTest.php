<?php

use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;
use SomehowDigital\Craft\Hub\conditions\EntryTypeCondition;
use SomehowDigital\Craft\Hub\conditions\FieldCondition;
use SomehowDigital\Craft\Hub\conditions\rules\HandleConditionRule;
use SomehowDigital\Craft\Hub\conditions\SectionCondition;
use SomehowDigital\Craft\Hub\conditions\VolumeCondition;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivityGroup;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\SectionType;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\services\ColumnService;

const ACTIVITY_COLORS = ['green', 'blue', 'red', 'orange', 'violet', 'gray', 'amber', 'teal', 'sky', 'indigo', 'pink', 'cyan'];

it('groups every activity action once, by what was done', function () {
	$grouped = array_merge(...array_map(fn (ActivityGroup $group) => $group->actions(), ActivityGroup::cases()));

	expect($grouped)->toHaveCount(count(ActivityAction::cases()))
		->and(ActivityGroup::Deleted->actions())->toBe([ActivityAction::Deleted, ActivityAction::Uninstalled, ActivityAction::TransformsDeleted, ActivityAction::DraftDeleted])
		->and(array_map(fn (ActivityGroup $group) => $group->actions(), ActivityGroup::cases()))->each->not->toBeEmpty();
});

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

it('describes every activity group, action and subject', function (string $enum) {
	$cases = $enum::cases();

	expect(array_unique(array_map(fn ($case) => $case->label(), $cases)))->toHaveCount(count($cases))
		->and(array_map(fn ($case) => $case->color(), $cases))->each->toBeIn(ACTIVITY_COLORS)
		->and(array_map(fn ($case) => $case->icon(), $cases))->each->toMatch('/^[a-z-]+$/');
})->with([ActivityGroup::class, ActivityAction::class, ActivitySubject::class]);

it('describes activity groups and actions', function (ActivityGroup|ActivityAction $case, string $label, string $color, string $icon) {
	expect($case->label())->toBe($label)
		->and($case->color())->toBe($color)
		->and($case->icon())->toBe($icon);
})->with([
	[ActivityGroup::Exported, 'Downloaded', 'gray', 'download'],
	[ActivityGroup::Access, 'Access changed', 'violet', 'key'],
	[ActivityAction::SignInFailed, 'Sign-in failed', 'red', 'triangle-exclamation'],
	[ActivityAction::Replaced, 'File replaced', 'blue', 'file-arrow-up'],
	[ActivityAction::AuthAdded, 'Sign-in method added', 'green', 'shield-halved'],
]);

it('groups activity actions by what was done', function (ActivityAction $action, ActivityGroup $group) {
	expect($action->group())->toBe($group);
})->with([
	[ActivityAction::DraftCreated, ActivityGroup::Created],
	[ActivityAction::Labeled, ActivityGroup::Updated],
	[ActivityAction::Reverted, ActivityGroup::Restored],
	[ActivityAction::Locked, ActivityGroup::Status],
	[ActivityAction::AdminGranted, ActivityGroup::Access],
	[ActivityAction::Impersonated, ActivityGroup::SignIn],
	[ActivityAction::Downloaded, ActivityGroup::Exported],
	[ActivityAction::BackedUp, ActivityGroup::Ran],
]);

it('tells the subject of an element', function (string $type, ActivitySubject $subject) {
	expect(ActivitySubject::fromElementType($type))->toBe($subject)
		->and(ActivitySubject::fromElement(new $type()))->toBe($subject)
		->and($subject->isElement())->toBeTrue();
})->with([
	[Entry::class, ActivitySubject::Entry],
	[Asset::class, ActivitySubject::Asset],
	[User::class, ActivitySubject::User],
	[Category::class, ActivitySubject::Category],
	[Tag::class, ActivitySubject::Tag],
	[GlobalSet::class, ActivitySubject::GlobalSet],
	[Address::class, ActivitySubject::Element],
]);

it('tells the subject of the items of a list', function () {
	expect(ActivitySubject::fromList(ColumnService::LIST_ENTRIES))->toBe(ActivitySubject::Entry)
		->and(ActivitySubject::fromList(ColumnService::LIST_FILES))->toBe(ActivitySubject::Asset)
		->and(ActivitySubject::fromList(ColumnService::LIST_USERS))->toBe(ActivitySubject::User)
		->and(ActivitySubject::fromList(ColumnService::LIST_SECTIONS))->toBe(ActivitySubject::Section)
		->and(ActivitySubject::fromList(ColumnService::LIST_ENTRY_TYPES))->toBe(ActivitySubject::EntryType)
		->and(ActivitySubject::fromList(ColumnService::LIST_FIELDS))->toBe(ActivitySubject::Field)
		->and(ActivitySubject::fromList(ColumnService::LIST_VOLUMES))->toBe(ActivitySubject::Volume)
		->and(ActivitySubject::fromList(ColumnService::LIST_USER_GROUPS))->toBe(ActivitySubject::UserGroup)
		->and(ActivitySubject::fromList('unknown'))->toBeNull()
		->and(ActivitySubject::Section->isElement())->toBeFalse();
});

it('describes the kinds of checks by the page listing them', function () {
	expect(array_map(fn (CheckKind $kind) => $kind->label(), CheckKind::cases()))->toBe(['Entries', 'Assets', 'Users', 'Entry Types', 'Sections', 'Fields', 'Volumes'])
		->and(array_map(fn (CheckKind $kind) => $kind->icon(), CheckKind::cases()))->toBe(['newspaper', 'image', 'user-group', 'shapes', 'layer-group', 'pen-ruler', 'hard-drive'])
		->and(array_map(fn (CheckKind $kind) => $kind->page(), CheckKind::cases()))->toBe([Page::Entries, Page::Assets, Page::Users, Page::EntryTypes, Page::Sections, Page::Fields, Page::Volumes]);
});

it('creates the condition of a kind of check with the rules of its config', function (CheckKind $kind, string $class) {
	$condition = $kind->createCondition(['conditionRules' => [['class' => HandleConditionRule::class, 'operator' => '=', 'value' => 'news'], 'invalid']]);

	expect($condition)->toBeInstanceOf($class)
		->and($condition->getConditionRules())->toHaveCount(1)
		->and($kind->createCondition(['conditionRules' => 'invalid'])->getConditionRules())->toBe([]);
})->with([
	[CheckKind::EntryTypes, EntryTypeCondition::class],
	[CheckKind::Sections, SectionCondition::class],
	[CheckKind::Fields, FieldCondition::class],
	[CheckKind::Volumes, VolumeCondition::class],
]);

it('tells the page listing an item', function (Closure $item, ?Page $page) {
	expect(Page::fromItem($item()))->toBe($page);
})->with([
	[fn () => new Entry(), Page::Entries],
	[fn () => new Asset(), Page::Assets],
	[fn () => new User(), Page::Users],
	[fn () => new UserGroup(), Page::Users],
	[fn () => new EntryType(), Page::EntryTypes],
	[fn () => new Section(), Page::Sections],
	[fn () => new PlainText(), Page::Fields],
	[fn () => new Volume(), Page::Volumes],
	[fn () => new Tag(), null],
]);
