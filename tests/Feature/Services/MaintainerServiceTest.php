<?php

use craft\elements\Asset;
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
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\MaintainerService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->hub()->getSettings()->enableMaintainers = true;
	$this->maintainers = new MaintainerService();

	$this->jane = new User(['id' => 3, 'username' => 'jane']);
	$this->john = new User(['id' => 4, 'username' => 'john']);
	$this->editors = new UserGroup(['id' => 7, 'name' => 'Editors']);

	ElementQueries::respond(User::class, fn ($query) => array_filter([$this->jane, $this->john], fn (User $user) => in_array($user->id, (array)$query->id, true)));

	$groups = $this->mockComponent('userGroups', UserGroups::class);
	$groups->shouldReceive('getGroupById')->andReturnUsing(fn (int $id) => $id === 7 ? $this->editors : null);
});

afterEach(function () {
	$this->hub()->getSettings()->enableMaintainers = false;
});

/**
 * Sets the rows of the maintainers table, as `[list, itemId, userId, groupId]`.
 *
 * @param array<int,array{0:string,1:int,2:int|null,3:int|null}> $rows
 */
function maintained(array $rows): void
{
	test()->db()->respond('FROM {{%hub_maintainers}}', array_map(fn (array $row) => [
		'list' => $row[0],
		'itemId' => $row[1],
		'userId' => $row[2],
		'groupId' => $row[3],
	], $rows));
}

it('reads the maintainers of an item in the order they were set, leaving out those deleted since', function () {
	maintained([
		[ColumnService::LIST_SECTIONS, 1, 4, null],
		[ColumnService::LIST_SECTIONS, 1, 9, null],
		[ColumnService::LIST_SECTIONS, 1, 3, null],
		[ColumnService::LIST_SECTIONS, 1, null, 7],
		[ColumnService::LIST_SECTIONS, 1, null, 8],
		[ColumnService::LIST_VOLUMES, 1, 3, null],
	]);

	expect($this->maintainers->getMaintainers(ColumnService::LIST_SECTIONS, 1))->toBe([
		'users' => [$this->john, $this->jane],
		'groups' => [$this->editors],
	]);
});

it('has no maintainers for items without any, and reads them once', function () {
	maintained([[ColumnService::LIST_SECTIONS, 1, null, 7]]);

	expect($this->maintainers->getMaintainers(ColumnService::LIST_FIELDS, 1))->toBe(['users' => [], 'groups' => []])
		->and($this->maintainers->getMaintainers(ColumnService::LIST_SECTIONS, 2))->toBe(['users' => [], 'groups' => []])
		->and($this->db()->queriesMatching('FROM {{%hub_maintainers}}'))->toHaveCount(1);
});

it('finds what an entry in a section is stored in', function () {
	$section = new Section(['id' => 1, 'name' => 'News']);
	$type = new EntryType(['id' => 2, 'name' => 'Article']);
	$entry = Mockery::mock(Entry::class)->makePartial();
	$entry->shouldReceive('getSection')->andReturn($section);
	$entry->shouldReceive('getType')->andReturn($type);
	maintained([
		[ColumnService::LIST_SECTIONS, 1, 3, null],
		[ColumnService::LIST_ENTRY_TYPES, 2, null, 7],
	]);

	$sources = $this->maintainers->getSources($entry);

	expect($sources)->toHaveCount(2)
		->and($sources[0]->item)->toBe($section)
		->and($sources[0]->users)->toBe([$this->jane])
		->and($sources[0]->getKind())->toBe(ActivitySubject::Section->label())
		->and($sources[1]->item)->toBe($type)
		->and($sources[1]->groups)->toBe([$this->editors]);
});

it('finds what a nested entry is stored in, up to the section of its owner, each once', function () {
	$section = new Section(['id' => 1, 'name' => 'News']);
	$type = new EntryType(['id' => 2, 'name' => 'Block']);
	$field = new Matrix(['id' => 5, 'name' => 'Blocks']);

	$owner = Mockery::mock(Entry::class)->makePartial();
	$owner->shouldReceive('getSection')->andReturn($section);
	$owner->shouldReceive('getType')->andReturn($type);

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

	expect(array_map(fn ($source) => $source->item, $this->maintainers->getSources($nested)))->toBe([$field, $type, $section])
		->and($this->maintainers->getMaintainersOf($nested))->toBe(['users' => [$this->john, $this->jane], 'groups' => []]);
});

it('finds the volume of an asset, and the fields of its layout on request', function () {
	$volume = new Volume(['id' => 6, 'name' => 'Images']);
	$field = new PlainText(['id' => 5, 'name' => 'Credit']);
	$layout = Mockery::mock(FieldLayout::class);
	$layout->shouldReceive('getCustomFields')->andReturn([$field]);

	$asset = Mockery::mock(Asset::class)->makePartial();
	$asset->shouldReceive('getVolume')->andReturn($volume);
	$asset->shouldReceive('getFieldLayout')->andReturn($layout);

	maintained([
		[ColumnService::LIST_VOLUMES, 6, 3, null],
		[ColumnService::LIST_FIELDS, 5, null, 7],
	]);

	expect(array_map(fn ($source) => $source->item, $this->maintainers->getSources($asset)))->toBe([$volume])
		->and(array_map(fn ($source) => $source->item, $this->maintainers->getSources($asset, true)))->toBe([$volume, $field]);
});

it('finds no maintainers of other elements, nor of anything while disabled', function () {
	$entry = new Entry(['id' => 5]);

	expect($this->maintainers->getSources(new User(['id' => 3])))->toBe([]);

	$this->hub()->getSettings()->enableMaintainers = false;

	expect($this->maintainers->getSources($entry))->toBe([])
		->and($this->maintainers->getMaintainersOf($entry))->toBe(['users' => [], 'groups' => []])
		->and($this->db()->queriesMatching('{{%hub_maintainers}}'))->toBe([]);
});

it('replaces the maintainers of an item, leaving out unknown ones, and records the changes', function () {
	$section = new Section(['id' => 1, 'name' => 'News']);
	$entries = $this->mockComponent('entries', Entries::class);
	$entries->shouldReceive('getSectionById')->with(1)->andReturn($section);

	$rows = [[ColumnService::LIST_SECTIONS, 1, 3, null]];
	$this->db()->respond('FROM {{%hub_maintainers}}', function () use (&$rows) {
		return array_map(fn (array $row) => ['list' => $row[0], 'itemId' => $row[1], 'userId' => $row[2], 'groupId' => $row[3]], $rows);
	});
	$this->db()->respond('DELETE FROM {{%hub_maintainers}}', function () use (&$rows) {
		$rows = [
			[ColumnService::LIST_SECTIONS, 1, 4, null],
			[ColumnService::LIST_SECTIONS, 1, null, 7],
		];

		return 1;
	});

	$recorded = [];
	$this->mockComponent('hub.activity', ActivityService::class)
		->shouldReceive('record')->andReturnUsing(function (...$args) use (&$recorded) {
			$recorded[] = $args;
		});

	$this->maintainers->setMaintainers(ColumnService::LIST_SECTIONS, 1, [4, 9, 4], [7, 8]);

	$insert = $this->db()->queriesMatching('INSERT INTO {{%hub_maintainers}}')[0];

	expect($insert)->toContain("('sections', 1, 4, NULL)")
		->and($insert)->toContain("('sections', 1, NULL, 7)")
		->and($insert)->not->toContain('9')
		->and($insert)->not->toContain(' 8')
		->and($recorded)->toBe([[ActivityAction::MaintainersChanged, ActivitySubject::Section, 1, 'News', ['added' => ['john', 'Editors'], 'removed' => ['jane']]]]);
});

it('removes all maintainers of an item, recording nothing if it had none', function () {
	$this->mockComponent('hub.activity', ActivityService::class)->shouldNotReceive('record');

	$this->maintainers->setMaintainers(ColumnService::LIST_SECTIONS, 1, [], []);

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_maintainers}}'))->toHaveCount(1)
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_maintainers}}'))->toBe([]);
});

it('finds the items of the lists', function () {
	$section = new Section(['id' => 1]);
	$type = new EntryType(['id' => 2]);
	$field = new PlainText(['id' => 3]);
	$volume = new Volume(['id' => 4]);

	$entries = $this->mockComponent('entries', Entries::class);
	$entries->shouldReceive('getSectionById')->with(1)->andReturn($section);
	$entries->shouldReceive('getEntryTypeById')->with(2)->andReturn($type);
	$this->mockComponent('fields', Fields::class)->shouldReceive('getFieldById')->with(3)->andReturn($field);
	$this->mockComponent('volumes', Volumes::class)->shouldReceive('getVolumeById')->with(4)->andReturn($volume);

	expect($this->maintainers->getItem(ColumnService::LIST_SECTIONS, 1))->toBe($section)
		->and($this->maintainers->getItem(ColumnService::LIST_ENTRY_TYPES, 2))->toBe($type)
		->and($this->maintainers->getItem(ColumnService::LIST_FIELDS, 3))->toBe($field)
		->and($this->maintainers->getItem(ColumnService::LIST_VOLUMES, 4))->toBe($volume)
		->and($this->maintainers->getItem(ColumnService::LIST_ENTRIES, 5))->toBeNull();
});

it('lets users manage the maintainers of the lists whose pages they may view, while enabled', function () {
	$permissions = [Hub::PERMISSION_ACCESS_STRUCTURE, Page::Sections->viewPermission(), Hub::PERMISSION_MANAGE_MAINTAINERS];
	$this->actingAs(permissions: $permissions);

	expect($this->maintainers->canManage(ColumnService::LIST_SECTIONS))->toBeTrue()
		->and($this->maintainers->canManage(ColumnService::LIST_VOLUMES))->toBeFalse()
		->and($this->maintainers->canManage(ColumnService::LIST_ENTRIES))->toBeFalse();

	$this->actingAs(permissions: [Hub::PERMISSION_ACCESS_STRUCTURE, Page::Sections->viewPermission()]);

	expect($this->maintainers->canManage(ColumnService::LIST_SECTIONS))->toBeFalse();

	$this->actingAs(permissions: $permissions);
	$this->hub()->getSettings()->enableMaintainers = false;

	expect($this->maintainers->canManage(ColumnService::LIST_SECTIONS))->toBeFalse();
});

it('removes the maintainers of items no longer there, per list', function () {
	$this->maintainers->deleteOrphans();

	$deleted = $this->db()->queriesMatching('DELETE FROM {{%hub_maintainers}}');

	expect($deleted)->toHaveCount(4)
		->and($deleted[0])->toContain("'sections'")
		->and($deleted[0])->toContain('NOT EXISTS');
});
