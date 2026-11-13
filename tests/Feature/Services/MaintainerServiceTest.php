<?php

declare(strict_types=1);

use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;
use craft\services\Entries;
use craft\services\Fields;
use craft\services\UserGroups;
use craft\services\Volumes;
use Mockery\MockInterface;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function (): void {
	$this->hub()->settings->enableMaintainers = true;

	$this->jane = new User(['id' => 3, 'username' => 'jane']);
	$this->john = new User(['id' => 4, 'username' => 'john']);
	$this->editors = new UserGroup(['id' => 7, 'name' => 'Editors']);

	ElementQueries::respond(User::class, fn(ElementQuery $query): array => array_filter([$this->jane, $this->john], fn(User $user): bool => in_array($user->id, listOf($query->id), true)));

	$groups = $this->mockComponent('userGroups', UserGroups::class);
	$groups->shouldReceive('getGroupById')->andReturnUsing(fn(int $id): ?UserGroup => $id === 7 ? $this->editors : null);
});

afterEach(function (): void {
	$this->hub()->settings->enableMaintainers = false;
});

/**
 * Sets the rows of the maintainers table, as `[list, itemId, userId, groupId]`.
 *
 * @param array<int,array{0:string,1:int,2:int|null,3:int|null}> $rows
 */
function maintained(array $rows): void
{
	currentTest()->db()->respond(sqlFrom(Tables::MAINTAINERS), array_map(fn(array $row): array => [
		'list' => $row[0],
		'itemId' => $row[1],
		'userId' => $row[2],
		'groupId' => $row[3],
	], $rows));
}

it('reads the maintainers of an item in the order they were set, leaving out those deleted since', function (): void {
	maintained([
		[ColumnService::LIST_SECTIONS, 1, 4, null],
		[ColumnService::LIST_SECTIONS, 1, 9, null],
		[ColumnService::LIST_SECTIONS, 1, 3, null],
		[ColumnService::LIST_SECTIONS, 1, null, 7],
		[ColumnService::LIST_SECTIONS, 1, null, 8],
		[ColumnService::LIST_VOLUMES, 1, 3, null],
	]);

	expect($this->hub()->maintainers->getMaintainers(ColumnService::LIST_SECTIONS, 1))->toBe([
		'users' => [$this->john, $this->jane],
		'groups' => [$this->editors],
	]);
});

it('has no maintainers for items without any, and reads them once', function (): void {
	maintained([[ColumnService::LIST_SECTIONS, 1, null, 7]]);

	expect($this->hub()->maintainers->getMaintainers(ColumnService::LIST_FIELDS, 1))->toBe(['users' => [], 'groups' => []])
		->and($this->hub()->maintainers->getMaintainers(ColumnService::LIST_SECTIONS, 2))->toBe(['users' => [], 'groups' => []])
		->and($this->db()->queriesMatching(sqlFrom(Tables::MAINTAINERS)))->toHaveCount(1);
});

it('finds what an entry in a section is stored in', function (): void {
	$section = new Section(['id' => 1, 'name' => 'News']);
	$type = new EntryType(['id' => 2, 'name' => 'Article']);
	/** @var Entry&MockInterface $entry */
	$entry = Mockery::mock(Entry::class)->makePartial();
	$entry->shouldReceive('getSection')->andReturn($section);
	$entry->shouldReceive('getType')->andReturn($type);
	maintained([
		[ColumnService::LIST_SECTIONS, 1, 3, null],
		[ColumnService::LIST_ENTRY_TYPES, 2, null, 7],
	]);

	$sources = $this->hub()->maintainers->getSources($entry);

	expect($sources)->toHaveCount(2)
		->and($sources[0]->item)->toBe($section)
		->and($sources[0]->users)->toBe([$this->jane])
		->and($sources[0]->getKind())->toBe(ActivitySubject::Section->label())
		->and($sources[1]->item)->toBe($type)
		->and($sources[1]->groups)->toBe([$this->editors]);
});

it('finds what a nested entry is stored in, up to the section of its owner, each once', function (): void {
	$section = new Section(['id' => 1, 'name' => 'News']);
	$type = new EntryType(['id' => 2, 'name' => 'Block']);
	$field = new Matrix(['id' => 5, 'name' => 'Blocks']);

	/** @var Entry&MockInterface $owner */
	$owner = Mockery::mock(Entry::class)->makePartial();
	$owner->shouldReceive('getSection')->andReturn($section);
	$owner->shouldReceive('getType')->andReturn($type);

	/** @var Entry&MockInterface $nested */
	$nested = Mockery::mock(Entry::class)->makePartial();
	$nested->shouldReceive('getSection')->andReturnNull();
	$nested->shouldReceive('getField')->andReturn($field);
	$nested->shouldReceive('getType')->andReturn($type);
	$nested->shouldReceive('getOwner')->andReturn($owner);

	maintained([
		[ColumnService::LIST_SECTIONS, 1, 3, null],
		[ColumnService::LIST_ENTRY_TYPES, 2, 4, null],
		[ColumnService::LIST_FIELDS, 5, 4, null],
	]);

	expect(array_map(fn(mixed $source): mixed => at($source, 'item'), $this->hub()->maintainers->getSources($nested)))->toBe([$field, $type, $section])
		->and($this->hub()->maintainers->getMaintainersOf($nested))->toBe(['users' => [$this->john, $this->jane], 'groups' => []]);
});

it('finds the volume of an asset, and the fields of its layout on request', function (): void {
	$volume = new Volume(['id' => 6, 'name' => 'Images']);
	$field = new PlainText(['id' => 5, 'name' => 'Credit']);
	$layout = Mockery::mock(FieldLayout::class);
	$layout->shouldReceive('getCustomFields')->andReturn([$field]);

	/** @var Asset&MockInterface $asset */
	$asset = Mockery::mock(Asset::class)->makePartial();
	$asset->shouldReceive('getVolume')->andReturn($volume);
	$asset->shouldReceive('getFieldLayout')->andReturn($layout);

	maintained([
		[ColumnService::LIST_VOLUMES, 6, 3, null],
		[ColumnService::LIST_FIELDS, 5, null, 7],
	]);

	expect(array_map(fn(mixed $source): mixed => at($source, 'item'), $this->hub()->maintainers->getSources($asset)))->toBe([$volume])
		->and(array_map(fn(mixed $source): mixed => at($source, 'item'), $this->hub()->maintainers->getSources($asset, true)))->toBe([$volume, $field]);
});

it('finds no maintainers of other elements, nor of anything while disabled', function (): void {
	$entry = new Entry(['id' => 5]);

	$this->hub()->settings->enableMaintainers = false;

	expect($this->hub()->maintainers->getSources($entry))->toBe([])
		->and($this->hub()->maintainers->getMaintainersOf($entry))->toBe(['users' => [], 'groups' => []])
		->and($this->db()->queriesMatching(Tables::MAINTAINERS))->toBe([]);

	$this->hub()->settings->enableMaintainers = true;

	expect($this->hub()->maintainers->getSources(new User(['id' => 3])))->toBe([]);
});

it('replaces the maintainers of an item, leaving out unknown ones, and records the changes', function (): void {
	$section = new Section(['id' => 1, 'name' => 'News']);
	$entries = $this->mockComponent('entries', Entries::class);
	$entries->shouldReceive('getSectionById')->with(1)->andReturn($section);

	$rows = [[ColumnService::LIST_SECTIONS, 1, 3, null]];
	$this->db()->respond(sqlFrom(Tables::MAINTAINERS), function () use (&$rows): array {
		return array_map(fn(array $row): array => ['list' => $row[0], 'itemId' => $row[1], 'userId' => $row[2], 'groupId' => $row[3]], $rows);
	});
	$this->db()->respond(sqlDeleteFrom(Tables::MAINTAINERS), function () use (&$rows): int {
		$rows = [
			[ColumnService::LIST_SECTIONS, 1, 4, null],
			[ColumnService::LIST_SECTIONS, 1, null, 7],
		];

		return 1;
	});

	$this->db()->tables = [Tables::ACTIVITY];

	$this->hub()->maintainers->setMaintainers(ColumnService::LIST_SECTIONS, 1, [4, 9, 4], [7, 8]);

	$insert = $this->db()->queriesMatching(sqlInsertInto(Tables::MAINTAINERS))[0];

	expect($insert)->toContain("('sections', 1, 4, NULL)")
		->and($insert)->toContain("('sections', 1, NULL, 7)")
		->and($insert)->not->toContain('9')
		->and($insert)->not->toContain(' 8')
		->and(array_map(fn(array $row): array => array_slice($row, 0, 5), activities()))->toBe([[ActivityAction::MaintainersChanged, ActivitySubject::Section, 1, 'News', ['added' => ['john', 'Editors'], 'removed' => ['jane']]]]);
});

it('removes all maintainers of an item, recording nothing if it had none', function (): void {
	$this->db()->tables = [Tables::ACTIVITY];

	$this->hub()->maintainers->setMaintainers(ColumnService::LIST_SECTIONS, 1, [], []);

	expect($this->db()->queriesMatching(sqlDeleteFrom(Tables::MAINTAINERS)))->toHaveCount(1)
		->and($this->db()->queriesMatching(sqlInsertInto(Tables::MAINTAINERS)))->toBe([])
		->and(activities())->toBe([]);
});

it('finds the items of the lists', function (): void {
	$section = new Section(['id' => 1]);
	$type = new EntryType(['id' => 2]);
	$field = new PlainText(['id' => 3]);
	$volume = new Volume(['id' => 4]);

	$entries = $this->mockComponent('entries', Entries::class);
	$entries->shouldReceive('getSectionById')->with(1)->andReturn($section);
	$entries->shouldReceive('getEntryTypeById')->with(2)->andReturn($type);
	$this->mockComponent('fields', Fields::class)->shouldReceive('getFieldById')->with(3)->andReturn($field);
	$this->mockComponent('volumes', Volumes::class)->shouldReceive('getVolumeById')->with(4)->andReturn($volume);

	expect($this->hub()->maintainers->getItem(ColumnService::LIST_SECTIONS, 1))->toBe($section)
		->and($this->hub()->maintainers->getItem(ColumnService::LIST_ENTRY_TYPES, 2))->toBe($type)
		->and($this->hub()->maintainers->getItem(ColumnService::LIST_FIELDS, 3))->toBe($field)
		->and($this->hub()->maintainers->getItem(ColumnService::LIST_VOLUMES, 4))->toBe($volume)
		->and($this->hub()->maintainers->getItem(ColumnService::LIST_ENTRIES, 5))->toBeNull();
});

it('lets users manage the maintainers of the lists whose pages they may view, while enabled', function (): void {
	$permissions = [Permission::AccessStructure->value, Page::Sections->viewPermission(), Permission::ManageMaintainers->value];
	$this->actingAs(permissions: strings($permissions));

	expect($this->hub()->maintainers->canManage(ColumnService::LIST_SECTIONS))->toBeTrue()
		->and($this->hub()->maintainers->canManage(ColumnService::LIST_VOLUMES))->toBeFalse()
		->and($this->hub()->maintainers->canManage(ColumnService::LIST_ENTRIES))->toBeFalse();

	$this->actingAs(permissions: [Permission::AccessStructure->value, Page::Sections->viewPermission()]);

	expect($this->hub()->maintainers->canManage(ColumnService::LIST_SECTIONS))->toBeFalse();

	$this->actingAs(permissions: strings($permissions));
	$this->hub()->settings->enableMaintainers = false;

	expect($this->hub()->maintainers->canManage(ColumnService::LIST_SECTIONS))->toBeFalse();
});

it('removes the maintainers of items no longer there, per list', function (): void {
	$this->hub()->maintainers->deleteOrphans();

	$deleted = $this->db()->queriesMatching(sqlDeleteFrom(Tables::MAINTAINERS));

	expect($deleted)->toHaveCount(4)
		->and($deleted[0])->toContain("'sections'")
		->and($deleted[0])->toContain('NOT EXISTS');
});

it('tells what a user maintains, themselves or through their groups', function (): void {
	$group = new UserGroup(['id' => 7, 'name' => 'Editors']);
	$section = new Section(['id' => 1, 'name' => 'News']);
	$field = new PlainText(['id' => 2, 'name' => 'Body']);
	$this->db()->respond(sqlFrom(Tables::MAINTAINERS), [
		['list' => ColumnService::LIST_SECTIONS, 'itemId' => 1, 'userId' => 3, 'groupId' => null],
		['list' => ColumnService::LIST_FIELDS, 'itemId' => 2, 'userId' => null, 'groupId' => 7],
		['list' => ColumnService::LIST_FIELDS, 'itemId' => 9, 'userId' => 3, 'groupId' => null],
		['list' => ColumnService::LIST_VOLUMES, 'itemId' => 4, 'userId' => 8, 'groupId' => null],
	]);
	$this->mockComponent('entries', Entries::class)->shouldReceive('getSectionById')->with(1)->andReturn($section);
	$fields = $this->mockComponent('fields', Fields::class);
	$fields->shouldReceive('getFieldById')->with(2)->andReturn($field);
	$fields->shouldReceive('getFieldById')->with(9)->andReturnNull();
	$this->mockComponent('userGroups', UserGroups::class)->shouldReceive('getGroupById')->with(7)->andReturn($group);
	/** @var User&MockInterface $user */
	$user = Mockery::mock(User::class)->makePartial();
	$user->id = 3;
	$user->shouldReceive('getGroups')->andReturn([$group]);

	$maintained = $this->hub()->maintainers->getMaintainedBy($user);

	expect($maintained)->toHaveCount(2)
		->and($maintained[0]->item)->toBe($section)
		->and($maintained[0]->users)->toBe([$user])
		->and($maintained[0]->groups)->toBe([])
		->and($maintained[1]->item)->toBe($field)
		->and($maintained[1]->users)->toBe([])
		->and($maintained[1]->groups)->toBe([$group]);
});

it('tells what a user group maintains', function (): void {
	$group = new UserGroup(['id' => 7, 'name' => 'Editors']);
	$field = new PlainText(['id' => 2, 'name' => 'Body']);
	$this->db()->respond(sqlFrom(Tables::MAINTAINERS), [
		['list' => ColumnService::LIST_SECTIONS, 'itemId' => 1, 'userId' => 3, 'groupId' => null],
		['list' => ColumnService::LIST_FIELDS, 'itemId' => 2, 'userId' => null, 'groupId' => 7],
		['list' => ColumnService::LIST_FIELDS, 'itemId' => 9, 'userId' => null, 'groupId' => 7],
		['list' => ColumnService::LIST_VOLUMES, 'itemId' => 4, 'userId' => null, 'groupId' => 8],
	]);
	$fields = $this->mockComponent('fields', Fields::class);
	$fields->shouldReceive('getFieldById')->with(2)->andReturn($field);
	$fields->shouldReceive('getFieldById')->with(9)->andReturnNull();

	$maintained = $this->hub()->maintainers->getMaintainedByGroup($group);

	expect($maintained)->toHaveCount(1)
		->and($maintained[0]->item)->toBe($field)
		->and($maintained[0]->users)->toBe([])
		->and($maintained[0]->groups)->toBe([$group]);
});

it('tells of nothing maintained while maintainers are disabled', function (): void {
	$this->hub()->settings->enableMaintainers = false;

	expect($this->hub()->maintainers->getMaintainedBy(new User(['id' => 3])))->toBe([])
		->and($this->hub()->maintainers->getMaintainedByGroup(new UserGroup(['id' => 3])))->toBe([]);
});

it('does not look up what elements are stored in while nothing is maintained', function (): void {
	maintained([]);
	/** @var Entry&MockInterface $entry */
	$entry = Mockery::mock(Entry::class)->makePartial();
	$entry->shouldNotReceive('getSection');

	expect($this->hub()->maintainers->getSources($entry))->toBe([]);
});
