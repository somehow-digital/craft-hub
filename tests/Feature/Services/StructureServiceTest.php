<?php

use craft\elements\Address;
use craft\elements\Category;
use craft\fields\PlainText;
use craft\models\CategoryGroup;
use craft\models\TagGroup;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\LayoutUsage;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\Tests\Support\Fields\LayoutProviderField;

/**
 * @param array<int,object> $items
 * @return array<int,string>
 */
function handles(array $items): array
{
	return array_map(fn (object $item) => $item->handle, array_values($items));
}

beforeEach(function () {
	$this->createContentModel();
	$this->structure = $this->hub()->structure;
});

it('lists sections, entry types and fields by name', function () {
	expect(array_map(fn (SectionInfo $info) => $info->section->handle, $this->structure->getSections()))->toBe([4 => 'archive', 1 => 'news', 2 => 'pages', 3 => 'settings'])
		->and(array_map(fn (EntryTypeInfo $info) => $info->entryType->handle, $this->structure->getEntryTypes()))->toBe([10 => 'article', 12 => 'block', 14 => 'empty', 13 => 'orphan', 11 => 'page'])
		->and(array_keys($this->structure->getFields()))->toBe([2, 4, 5, 7, 3, 1, 9, 10, 6]);
});

it('looks up sections, entry types and fields by ID', function () {
	expect($this->structure->getSection(1)->section->handle)->toBe('news')
		->and($this->structure->getSection(99))->toBeNull()
		->and($this->structure->getEntryType(12)->entryType->handle)->toBe('block')
		->and($this->structure->getEntryType(99))->toBeNull()
		->and($this->structure->getField(3)->field->handle)->toBe('related')
		->and($this->structure->getField(99))->toBeNull();
});

it('describes sections', function () {
	$news = $this->structure->getSection(1);
	$settings = $this->structure->getSection(3);

	expect(handles($news->entryTypes))->toBe(['article'])
		->and($news->hasUrls)->toBeTrue()
		->and($news->stats->total)->toBe(5)
		->and(handles($news->referenceFields))->toBe(['related'])
		->and(handles($news->linkFields))->toBe(['cta'])
		->and($news->references->references)->toBe(4)
		->and($settings->hasUrls)->toBeFalse()
		->and($settings->referenceFields)->toBe([])
		->and(handles($settings->linkFields))->toBe(['cta'])
		->and($this->structure->getSection(2)->references->references)->toBe(1)
		->and($this->structure->getSection(4)->isEmpty())->toBeTrue();
});

it('classifies entry types by how they are used', function () {
	$kinds = array_map(fn (EntryTypeInfo $info) => $info->kinds, $this->structure->getEntryTypes());

	expect($kinds)->toBe([
		10 => [UsageKind::Section, UsageKind::Reference, UsageKind::Link, UsageKind::HasUrls],
		12 => [UsageKind::Nested, UsageKind::HasUrls],
		14 => [UsageKind::Section, UsageKind::Link, UsageKind::Empty],
		13 => [UsageKind::Unused],
		11 => [UsageKind::Section, UsageKind::Link, UsageKind::HasUrls],
	]);
});

it('describes entry types', function () {
	$article = $this->structure->getEntryType(10);
	$block = $this->structure->getEntryType(12);
	$page = $this->structure->getEntryType(11);

	expect(handles($article->sections))->toBe(['news', 'pages'])
		->and(handles($article->referenceFields))->toBe(['related'])
		// Fields are assigned once, even when they reach the entry type through several sections.
		->and(handles($article->linkFields))->toBe(['cta'])
		->and($article->stats->total)->toBe(5)
		->and(handles($block->nestedFields))->toBe(['blocks'])
		->and($block->stats->total)->toBe(7)
		->and(handles($page->sections))->toBe(['pages', 'settings'])
		->and($page->stats->total)->toBe(3);
});

it('describes nested entry fields', function () {
	$blocks = $this->structure->getField(2);
	$legacy = $this->structure->getField(7);

	expect($blocks->isNested)->toBeTrue()
		->and($blocks->isRelational)->toBeFalse()
		->and($blocks->hasUrls)->toBeTrue()
		->and(handles($blocks->entryTypes))->toBe(['block'])
		->and($blocks->stats->total)->toBe(7)
		// Nested entry fields only hold entries once entry types are assigned.
		->and($legacy->isNested)->toBeFalse()
		->and($legacy->entryTypes)->toBe([]);
});

it('describes relational fields', function () {
	$related = $this->structure->getField(3);
	$cta = $this->structure->getField(4);
	$image = $this->structure->getField(5);

	expect($related->isRelational)->toBeTrue()
		->and(handles($related->sections))->toBe(['news'])
		->and($related->references->references)->toBe(4)
		->and($cta->isRelational)->toBeTrue()
		->and(handles($cta->sections))->toBe(['news', 'pages', 'settings', 'archive'])
		->and($cta->references->references)->toBe(1)
		->and($image->isRelational)->toBeTrue()
		->and($image->references->references)->toBe(3)
		->and($this->structure->getField(1)->isRelational)->toBeFalse();
});

it('does not take Link fields without element link types for relational fields', function () {
	$this->field(craft\fields\Link::class, 20, 'website', ['types' => ['url']]);

	expect($this->structure->getField(20)->isRelational)->toBeFalse()
		->and($this->structure->getField(20)->sections)->toBe([]);
});

it('lists the layouts fields are placed in', function () {
	$summary = $this->structure->getField(1);
	$labels = array_map(fn (LayoutUsage $usage) => [$usage->ownerType, $usage->label, $usage->instances], $summary->layouts);

	expect($labels)->toHaveCount(5)
		->and($labels)->toContain(['Entry Type', 'Block', 1], ['Entry Type', 'Article', 1], ['Entry Type', 'Page', 2], ['Volume', 'Images', 1], ['Users', 'Users', 1])
		->and($summary->getInstanceCount())->toBe(6)
		->and($this->structure->getField(6)->isUnused())->toBeTrue();
});

it('links layouts to their owners', function () {
	$usages = [];
	foreach ($this->structure->getField(1)->layouts as $usage) {
		$usages[$usage->label] = $usage;
	}

	expect($usages['Article']->model)->toBe($this->entryTypes[10])
		->and($usages['Article']->url)->toContain('settings/entry-types/10')
		->and($usages['Images']->url)->toContain('settings/assets/volumes/1')
		->and($usages['Users']->url)->toContain('settings/users/fields')
		->and($usages['Users']->model)->toBeNull();
});

it('describes layouts of fields, category and tag groups, addresses and other element types', function () {
	$plain = $this->field(PlainText::class, 30, 'plain');
	$provider = $this->field(LayoutProviderField::class, 31, 'provider');
	$provider->layout = $this->layout(Address::class, 'provider', [$plain]);
	$this->layouts[] = $provider->layout;
	$this->layouts[] = $this->layout(Address::class, 'addresses', [$plain]);
	$this->layouts[] = $this->layout(Category::class, 'unowned', [$plain]);

	$group = new CategoryGroup(['id' => 1, 'name' => 'Topics', 'handle' => 'topics']);
	$group->setFieldLayout($this->layout(Category::class, 'topics', [$plain]));
	$this->layouts[] = $group->getFieldLayout();
	Craft::$app->getCategories()->shouldReceive('getAllGroups')->andReturn([$group]);

	$tags = new TagGroup(['id' => 2, 'name' => 'Keywords', 'handle' => 'keywords']);
	$tags->setFieldLayout($this->layout(craft\elements\Tag::class, 'keywords', [$plain]));
	$this->layouts[] = $tags->getFieldLayout();
	Craft::$app->getTags()->shouldReceive('getAllTagGroups')->andReturn([$tags]);

	$usages = [];
	foreach ($this->structure->getField(30)->layouts as $usage) {
		$usages[$usage->label] = $usage;
	}

	expect(array_map(fn (LayoutUsage $usage) => $usage->ownerType, $usages))->toBe([
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

it('scopes URLs and counts to the selected sites', function () {
	$this->request([Hub::SITE_PARAM => 'de']);

	$this->db()->respondToEntryRows(fn (string $sql) => str_contains($sql, '`elements_sites`.`siteId`=2') ? [
		['sectionId' => 1, 'typeId' => 10, 'fieldId' => null, 'total' => 2, 'enabled' => 2, 'pending' => 0, 'expired' => 0, 'drafts' => 0, 'lastUpdated' => null],
	] : []);

	expect($this->structure->getSection(1)->hasUrls)->toBeTrue()
		->and($this->structure->getSection(1)->stats->total)->toBe(2)
		->and($this->structure->getSection(2)->hasUrls)->toBeFalse()
		->and($this->structure->getField(2)->hasUrls)->toBeFalse()
		->and($this->structure->getEntryType(12)->kinds)->toBe([UsageKind::Nested, UsageKind::Empty]);
});

it('builds the graph once per set of sites', function () {
	$this->structure->getSections();
	$this->structure->getFields();
	$this->structure->getEntryTypes();

	Craft::$app->getEntries()->shouldNotReceive('getAllSections');
	$this->structure->getSections();
});

it('reads the statistics when they are first shown', function () {
	$sections = $this->structure->getSections();
	$this->structure->getEntryTypes();
	$this->structure->getFields();

	expect($this->db()->queriesMatching('FROM'))->toBe([]);

	// Sections only hold section entries, and their references are read when shown.
	expect($sections[1]->stats->total)->toBe(5)
		->and($this->db()->queriesMatching('{{hubEntryRows}}'))->toHaveCount(1)
		->and($this->db()->queriesMatching('FROM {{%relations}} {{relations}}'))->toBe([])
		->and($sections[1]->references->references)->toBe(4)
		->and($this->structure->getField(5)->references->references)->toBe(3)
		->and($this->db()->queriesMatching('FROM {{%relations}} {{relations}}'))->toHaveCount(1)
		->and($this->structure->getEntryType(12)->kinds)->toBe([UsageKind::Nested, UsageKind::HasUrls])
		->and($this->db()->queriesMatching('{{hubEntryRows}}'))->toHaveCount(2);
});

it('keeps statistics set before they are read', function () {
	$section = $this->structure->getSection(1);
	$section->stats = new SomehowDigital\Craft\Hub\models\EntryStatistics();

	expect($section->stats->total)->toBe(0)
		->and(isset($section->references))->toBeTrue()
		->and($this->db()->queriesMatching('FROM'))->toBe([]);
});

it('leaves out fields that are not among all fields', function () {
	$fields = Craft::$app->getFields();
	$stray = new craft\fields\Entries(['id' => 40, 'uid' => 'stray', 'handle' => 'stray', 'sources' => '*']);
	$strayMatrix = new craft\fields\Matrix(['id' => 41, 'uid' => 'stray-matrix', 'handle' => 'strayMatrix']);
	$fields->shouldReceive('getFieldsByType')->andReturnUsing(fn (string $type) => match ($type) {
		craft\fields\Entries::class => [$stray],
		craft\fields\Matrix::class => [$strayMatrix],
		default => [],
	});

	expect($this->structure->getField(40))->toBeNull()
		->and($this->structure->getSection(1)->referenceFields)->toBe([]);
});

it('leaves out nested entry types that are not among all entry types', function () {
	$stray = new craft\models\EntryType(['id' => 50, 'uid' => 'stray', 'handle' => 'stray', 'name' => 'Stray']);
	$this->fields[7]->setEntryTypes([$stray]);

	expect($this->structure->getField(7)->entryTypes)->toBe([])
		->and($this->structure->getEntryType(50))->toBeNull();
});
