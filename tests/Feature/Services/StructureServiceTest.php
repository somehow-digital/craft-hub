<?php

declare(strict_types=1);

use craft\elements\Address;
use craft\elements\Category;
use craft\elements\Tag;
use craft\fields\Entries;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\CategoryGroup;
use craft\models\EntryType;
use craft\models\TagGroup;
use LogicException;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FilterParams;
use SomehowDigital\Craft\Hub\models\LayoutUsage;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\Tests\Support\Fields\LayoutProviderField;

/**
 * @param array<mixed>|null $items
 * @return list<string>
 */
function handles(?array $items): array
{
	return array_values(array_map(fn(mixed $item): string => asString(at($item, 'handle')), $items ?? []));
}

beforeEach(function (): void {
	$this->createContentModel();
});

it('lists sections, entry types and fields by name', function (): void {
	expect(array_map(fn(SectionInfo $info): ?string => $info->section->handle, $this->hub()->structure->getSections()))->toBe([4 => 'archive', 1 => 'news', 2 => 'pages', 3 => 'settings'])
		->and(array_map(fn(EntryTypeInfo $info): ?string => $info->entryType->handle, $this->hub()->structure->getEntryTypes()))->toBe([10 => 'article', 12 => 'block', 14 => 'empty', 13 => 'orphan', 11 => 'page'])
		->and(array_keys($this->hub()->structure->getFields()))->toBe([2, 4, 5, 7, 3, 1, 9, 10, 6]);
});

it('looks up sections, entry types and fields by ID', function (): void {
	expect($this->hub()->structure->getSection(1)?->section->handle)->toBe('news')
		->and($this->hub()->structure->getSection(99))->toBeNull()
		->and($this->hub()->structure->getEntryType(12)?->entryType->handle)->toBe('block')
		->and($this->hub()->structure->getEntryType(99))->toBeNull()
		->and($this->hub()->structure->getField(3)?->field->handle)->toBe('related')
		->and($this->hub()->structure->getField(99))->toBeNull();
});

it('describes sections', function (): void {
	$news = $this->hub()->structure->getSection(1);
	$settings = $this->hub()->structure->getSection(3);

	expect(handles($news?->entryTypes))->toBe(['article'])
		->and($news?->hasUrls)->toBeTrue()
		->and($news?->stats->total)->toBe(5)
		->and(handles($news?->referenceFields))->toBe(['related'])
		->and(handles($news?->linkFields))->toBe(['cta'])
		->and($news?->references->references)->toBe(4)
		->and($settings?->hasUrls)->toBeFalse()
		->and($settings?->referenceFields)->toBe([])
		->and(handles($settings?->linkFields))->toBe(['cta'])
		->and($this->hub()->structure->getSection(2)?->references->references)->toBe(1)
		->and(present($this->hub()->structure->getSection(4))->isEmpty())->toBeTrue();
});

it('classifies entry types by how they are used', function (): void {
	$kinds = array_map(fn(EntryTypeInfo $info): array => $info->kinds, $this->hub()->structure->getEntryTypes());

	expect($kinds)->toBe([
		10 => [UsageKind::Section, UsageKind::Reference, UsageKind::Link, UsageKind::HasUrls],
		12 => [UsageKind::Nested, UsageKind::HasUrls],
		14 => [UsageKind::Section, UsageKind::Link, UsageKind::Empty],
		13 => [UsageKind::Unused],
		11 => [UsageKind::Section, UsageKind::Link, UsageKind::HasUrls],
	]);
});

it('describes entry types', function (): void {
	$article = $this->hub()->structure->getEntryType(10);
	$block = $this->hub()->structure->getEntryType(12);
	$page = $this->hub()->structure->getEntryType(11);

	expect(handles($article?->sections))->toBe(['news', 'pages'])
		->and(handles($article?->referenceFields))->toBe(['related'])
		// Fields are assigned once, even when they reach the entry type through several sections.
		->and(handles($article?->linkFields))->toBe(['cta'])
		->and($article?->stats->total)->toBe(5)
		->and(handles($block?->nestedFields))->toBe(['blocks'])
		->and($block?->stats->total)->toBe(7)
		->and(handles($page?->sections))->toBe(['pages', 'settings'])
		->and($page?->stats->total)->toBe(3);
});

it('describes nested entry fields', function (): void {
	$blocks = $this->hub()->structure->getField(2);
	$legacy = $this->hub()->structure->getField(7);

	expect($blocks?->isNested)->toBeTrue()
		->and($blocks?->isRelational)->toBeFalse()
		->and($blocks?->hasUrls)->toBeTrue()
		->and(handles($blocks?->entryTypes))->toBe(['block'])
		->and($blocks?->stats->total)->toBe(7)
		// Nested entry fields only hold entries once entry types are assigned.
		->and($legacy?->isNested)->toBeFalse()
		->and($legacy?->entryTypes)->toBe([]);
});

it('describes relational fields', function (): void {
	$related = $this->hub()->structure->getField(3);
	$cta = $this->hub()->structure->getField(4);
	$image = $this->hub()->structure->getField(5);

	expect($related?->isRelational)->toBeTrue()
		->and(handles($related?->sections))->toBe(['news'])
		->and($related?->references->references)->toBe(4)
		->and($cta?->isRelational)->toBeTrue()
		->and(handles($cta?->sections))->toBe(['news', 'pages', 'settings', 'archive'])
		->and($cta?->references->references)->toBe(1)
		->and($image?->isRelational)->toBeTrue()
		->and($image?->references->references)->toBe(3)
		->and($this->hub()->structure->getField(1)?->isRelational)->toBeFalse();
});

it('does not take Link fields without element link types for relational fields', function (): void {
	$this->field(Link::class, 20, 'website', ['types' => ['url']]);

	expect($this->hub()->structure->getField(20)?->isRelational)->toBeFalse()
		->and($this->hub()->structure->getField(20)?->sections)->toBe([]);
});

it('lists the layouts fields are placed in', function (): void {
	$summary = $this->hub()->structure->getField(1);
	$labels = array_map(fn(LayoutUsage $usage): array => [$usage->ownerType, $usage->label, $usage->instances], present($summary)->layouts);

	expect($labels)->toHaveCount(5)
		->and($labels)->toContain(['Entry Type', 'Block', 1], ['Entry Type', 'Article', 1], ['Entry Type', 'Page', 2], ['Volume', 'Images', 1], ['Users', 'Users', 1])
		->and($summary?->getInstanceCount())->toBe(6)
		->and(present($this->hub()->structure->getField(6))->isUnused())->toBeTrue();
});

it('links layouts to their owners', function (): void {
	$usages = [];
	foreach (present($this->hub()->structure->getField(1))->layouts as $usage) {
		$usages[$usage->label] = $usage;
	}

	expect($usages['Article']->model)->toBe($this->entryTypes[10])
		->and($usages['Article']->url)->toContain('settings/entry-types/10')
		->and($usages['Images']->url)->toContain('settings/assets/volumes/1')
		->and($usages['Users']->url)->toContain('settings/users/fields')
		->and($usages['Users']->model)->toBeNull();
});

it('describes layouts of fields, category and tag groups, addresses and other element types', function (): void {
	$plain = $this->field(PlainText::class, 30, 'plain');
	$provider = $this->field(LayoutProviderField::class, 31, 'provider');
	$provider->layout = $this->layout(Address::class, 'provider', [$plain]);
	$this->layouts[] = $provider->layout;
	$this->layouts[] = $this->layout(Address::class, 'addresses', [$plain]);
	$this->layouts[] = $this->layout(Category::class, 'unowned', [$plain]);

	$group = new CategoryGroup(['id' => 1, 'name' => 'Topics', 'handle' => 'topics']);
	$group->setFieldLayout($this->layout(Category::class, 'topics', [$plain]));
	$this->layouts[] = $group->getFieldLayout();
	mocked(Craft::$app->getCategories())->shouldReceive('getAllGroups')->andReturn([$group]);

	$tags = new TagGroup(['id' => 2, 'name' => 'Keywords', 'handle' => 'keywords']);
	$tags->setFieldLayout($this->layout(Tag::class, 'keywords', [$plain]));
	$this->layouts[] = $tags->getFieldLayout();
	mocked(Craft::$app->getTags())->shouldReceive('getAllTagGroups')->andReturn([$tags]);

	$usages = [];
	foreach (present($this->hub()->structure->getField(30))->layouts as $usage) {
		$usages[$usage->label] = $usage;
	}

	expect(array_map(fn(LayoutUsage $usage): string => $usage->ownerType, $usages))->toBe([
		'Provider' => 'Layout Provider',
		'Addresses' => 'Addresses',
		'Category' => 'Categories',
		'Topics' => 'Category Group',
		'Keywords' => 'Tag Group',
	])
		->and($usages['Provider']->model)->toBe($provider)
		->and($usages['Provider']->color)->toBe('gray')
		->and($usages['Addresses']->url)->toContain('settings/addresses')
		->and($usages['Category']->url)->toBeNull()
		->and($usages['Keywords']->url)->toContain('settings/tags/2');
});

it('scopes URLs and counts to the selected sites', function (): void {
	$this->request([FilterParams::SITE_PARAM => 'de']);

	$this->db()->respondToEntryRows(fn(string $sql): array => str_contains($sql, '`elements_sites`.`siteId`=2') ? [
		['sectionId' => 1, 'typeId' => 10, 'fieldId' => null, 'total' => 2, 'enabled' => 2, 'pending' => 0, 'expired' => 0, 'drafts' => 0, 'lastUpdated' => null],
	] : []);

	expect($this->hub()->structure->getSection(1)?->hasUrls)->toBeTrue()
		->and($this->hub()->structure->getSection(1)?->stats->total)->toBe(2)
		->and($this->hub()->structure->getSection(2)?->hasUrls)->toBeFalse()
		->and($this->hub()->structure->getField(2)?->hasUrls)->toBeFalse()
		->and($this->hub()->structure->getEntryType(12)?->kinds)->toBe([UsageKind::Nested, UsageKind::Empty]);
});

it('builds the graph once per set of sites', function (): void {
	$this->hub()->structure->getSections();
	$this->hub()->structure->getFields();
	$this->hub()->structure->getEntryTypes();

	mocked(Craft::$app->getEntries())->shouldNotReceive('getAllSections');
	$this->hub()->structure->getSections();
});

it('reads the statistics when they are first shown', function (): void {
	$sections = $this->hub()->structure->getSections();
	$this->hub()->structure->getEntryTypes();
	$this->hub()->structure->getFields();

	expect($this->db()->queriesMatching('FROM'))->toBe([]);

	// Sections only hold section entries, and their references are read when shown.
	expect($sections[1]->stats->total)->toBe(5)
		->and($this->db()->queriesMatching('{{hubEntryRows}}'))->toHaveCount(1)
		->and($this->db()->queriesMatching('FROM {{%relations}} {{relations}}'))->toBe([])
		->and($sections[1]->references->references)->toBe(4)
		->and($this->hub()->structure->getField(5)?->references->references)->toBe(3)
		->and($this->db()->queriesMatching('FROM {{%relations}} {{relations}}'))->toHaveCount(1)
		->and($this->hub()->structure->getEntryType(12)?->kinds)->toBe([UsageKind::Nested, UsageKind::HasUrls])
		->and($this->db()->queriesMatching('{{hubEntryRows}}'))->toHaveCount(2);
});

it('keeps statistics set before they are read', function (): void {
	$section = $this->hub()->structure->getSection(1) ?? throw new LogicException('Not found.');
	$section->stats = new EntryStatistics();

	expect($section->stats->total)->toBe(0)
		->and(isset($section->references))->toBeTrue()
		->and($this->db()->queriesMatching('FROM'))->toBe([]);
});

it('leaves out fields that are not among all fields', function (): void {
	$fields = mocked(Craft::$app->getFields());
	$stray = new Entries(['id' => 40, 'uid' => 'stray', 'handle' => 'stray', 'sources' => '*']);
	$strayMatrix = new Matrix(['id' => 41, 'uid' => 'stray-matrix', 'handle' => 'strayMatrix']);
	$fields->shouldReceive('getFieldsByType')->andReturnUsing(fn(string $type): array => match ($type) {
		Entries::class => [$stray],
		Matrix::class => [$strayMatrix],
		default => [],
	});

	expect($this->hub()->structure->getField(40))->toBeNull()
		->and($this->hub()->structure->getSection(1)?->referenceFields)->toBe([]);
});

it('leaves out nested entry types that are not among all entry types', function (): void {
	$stray = new EntryType(['id' => 50, 'uid' => 'stray', 'handle' => 'stray', 'name' => 'Stray']);
	$nested = $this->fields[7];
	if (!$nested instanceof Matrix) {
		throw new LogicException('Not a matrix.');
	}

	$nested->setEntryTypes([$stray]);

	expect($this->hub()->structure->getField(7)?->entryTypes)->toBe([])
		->and($this->hub()->structure->getEntryType(50))->toBeNull();
});
