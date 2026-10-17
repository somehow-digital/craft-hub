<?php

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fs\Local;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\models\TrashedEntryFilter;
use SomehowDigital\Craft\Hub\models\TrashedFileFilter;
use SomehowDigital\Craft\Hub\services\TrashService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->createContentModel();
	$this->volume(1, 'images');
	$this->request();
});

it('queries the trashed entries, leaving out those deleted with their owner', function () {
	$query = $this->hub()->trash->getEntryQuery(new TrashedEntryFilter());

	expect($query->trashed)->toBeTrue()
		->and($query->status)->toBeNull()
		->and(json_encode($query->where))->toContain('elements.deletedWithOwner')
		->and(json_encode($query->where))->toContain('hubOwners.dateDeleted')
		->and($query->orderBy)->toBe(['elements.dateDeleted' => SORT_DESC, 'elements.id' => SORT_DESC]);
});

it('narrows the trashed entries by their filters', function () {
	$filter = new TrashedEntryFilter();
	$filter->search = 'hello';
	$filter->sections = ['news'];
	$filter->types = ['article'];
	$filter->deletedFrom = new DateTime('2024-01-01');
	$filter->deletedTo = new DateTime('2024-01-31');

	$query = $this->hub()->trash->getEntryQuery($filter, TrashService::SORT_TITLE, SORT_ASC);
	$where = json_encode($query->where);

	expect($where)->toContain('"entries.sectionId":[1]')
		->and($query->typeId)->toBe([10])
		->and($where)->toContain('hello')
		->and($where)->toContain('elements.dateDeleted')
		->and($query->orderBy)->toBe(['elements_sites.title' => SORT_ASC, 'elements.id' => SORT_ASC]);
});

it('queries the trashed files, leaving out those deleted with their volume', function () {
	$query = $this->hub()->trash->getFileQuery(new TrashedFileFilter(), TrashService::SORT_SIZE);

	expect($query->trashed)->toBeTrue()
		->and(json_encode($query->where))->toContain('assets.deletedWithVolume')
		->and($query->orderBy)->toBe(['assets.size' => SORT_DESC, 'elements.id' => SORT_DESC]);
});

it('narrows the trashed files by their filters', function (string $file, string $condition) {
	$filter = new TrashedFileFilter();
	$filter->search = 'logo';
	$filter->volumes = ['images'];
	$filter->kinds = ['image'];
	$filter->file = $file;
	$filter->deletedFrom = new DateTime('2024-01-01');

	$query = $this->hub()->trash->getFileQuery($filter, TrashService::SORT_FILE, SORT_ASC);
	$where = json_encode($query->where);

	expect($query->volumeId)->toBe([1])
		->and($query->kind)->toBe(['image'])
		->and($where)->toContain('logo')
		->and($where)->toContain($condition)
		->and($where)->toContain('elements.dateDeleted')
		->and($query->orderBy)->toBe(['assets.filename' => SORT_ASC, 'elements.id' => SORT_ASC]);
})->with([
	'kept' => [TrashService::FILE_KEPT, '{"assets.keptFile":true}'],
	'deleted' => [TrashService::FILE_DELETED, '{"assets.keptFile":null}'],
]);

it('sorts the trashed items by their own columns', function () {
	$trash = $this->hub()->trash;

	expect($trash->getEntrySortColumns())->toBe(['title' => SORT_ASC, 'deleted' => SORT_DESC])
		->and($trash->getFileSortColumns())->toBe(['file' => SORT_ASC, 'size' => SORT_DESC, 'deleted' => SORT_DESC]);
});

it('finds who last deleted the items in the activity log', function () {
	$editor = new User(['id' => 3, 'username' => 'editor']);
	ElementQueries::respond(User::class, [$editor]);
	$this->db()->respond('FROM {{%hub_activity}}', [
		['subjectId' => '1', 'userId' => '3', 'userName' => 'Editor'],
		['subjectId' => '2', 'userId' => '9', 'userName' => 'Gone'],
		['subjectId' => '5', 'userId' => null, 'userName' => null],
	]);

	$deleters = $this->hub()->trash->getDeleters(ActivitySubject::Entry, [1, 2, 5]);

	expect($deleters[1])->toBe(['user' => $editor, 'name' => 'Editor'])
		->and($deleters[2])->toBe(['user' => null, 'name' => 'Gone'])
		->and($deleters[5])->toBe(['user' => null, 'name' => null])
		->and($this->db()->queriesMatching('FROM {{%hub_activity}}')[0])->toContain("`action`='deleted'")
		->and($this->db()->queriesMatching('FROM {{%hub_activity}}')[0])->toContain('SELECT MAX(`id`)')
		->and($this->hub()->trash->getDeleters(ActivitySubject::Asset, []))->toBe([]);
});

describe('files kept of trashed assets', function () {
	beforeEach(function () {
		$this->trash = Mockery::mock(TrashService::class)->makePartial()->shouldAllowMockingProtectedMethods();
		$this->trash->shouldReceive('keptFilesTableExists')->andReturn(true)->byDefault();
		$this->fs = Mockery::mock(Local::class)->makePartial();
		$this->fs->handle = 'fs-images';
		$this->volumes[1]->setFs($this->fs);
	});

	it('records and forgets the files kept', function () {
		$asset = Mockery::mock(Asset::class)->makePartial();
		$asset->id = 5;
		$asset->volumeId = 1;
		$asset->shouldReceive('getPath')->andReturn('photos/a.jpg');

		$this->trash->recordKeptFile($asset);
		$this->trash->forgetKeptFile(5);

		expect($this->db()->queriesMatching('INSERT INTO {{%hub_kept_files}}')[0])->toContain("(5, 1, 'photos/a.jpg'")
			->and($this->db()->queriesMatching('DELETE FROM {{%hub_kept_files}}')[0])->toContain('`assetId`=5');
	});

	it('deletes the files of assets deleted permanently since, unless another asset has their place', function () {
		$this->db()->respond('FROM {{%hub_kept_files}} `kept`', [
			['assetId' => '5', 'volumeId' => '1', 'path' => 'photos/a.jpg'],
			['assetId' => '6', 'volumeId' => '1', 'path' => 'photos/b.jpg'],
			['assetId' => '7', 'volumeId' => '9', 'path' => 'c.jpg'],
		]);
		$this->db()->respond('INNER JOIN {{%volumefolders}}', fn (string $sql) => str_contains($sql, "'b.jpg'") ? [['path' => 'photos/']] : [['path' => 'other/']]);
		$this->fs->shouldReceive('deleteFile')->once()->with('photos/a.jpg');

		expect($this->trash->deletePurgedFiles())->toBe(1)
			->and($this->db()->queriesMatching('DELETE FROM {{%hub_kept_files}}'))->toHaveCount(3);
	});

	it('forgets files that can no longer be deleted', function () {
		$this->db()->respond('FROM {{%hub_kept_files}} `kept`', [['assetId' => '5', 'volumeId' => '1', 'path' => 'a.jpg']]);
		$this->fs->shouldReceive('deleteFile')->andThrow(new RuntimeException('Gone'));

		expect($this->trash->deletePurgedFiles())->toBe(0)
			->and($this->db()->queriesMatching('DELETE FROM {{%hub_kept_files}}'))->toHaveCount(1);
	});

	it('does nothing without its table', function () {
		$this->trash->shouldReceive('keptFilesTableExists')->andReturn(false);
		$asset = new Asset(['id' => 5]);

		$this->trash->recordKeptFile($asset);
		$this->trash->forgetKeptFile(5);

		expect($this->trash->deletePurgedFiles())->toBe(0)
			->and($this->db()->queriesMatching('hub_kept_files'))->toBe([]);
	});
});

it('finds the trashed elements related to trashed items, through the nested entries of either', function () {
	// Entry 40 is nested in the trashed entry 30, and relates to the item.
	$this->db()->respond('FROM {{%elements_owners}}', fn (string $sql) => str_contains($sql, 'WHERE `elementId`') ? [['elementId' => 40, 'ownerId' => 30]] : []);
	$this->db()->respond('FROM {{%relations}}', [['sourceId' => 40, 'targetId' => 1]]);
	ElementQueries::respond(Entry::class, fn ($query) => in_array(30, (array)$query->id, true) ? [new Entry(['id' => 30, 'siteId' => 1])] : []);

	$related = $this->hub()->trash->getRelatedTrashed([1]);

	expect(array_keys($related))->toBe([1])
		->and(array_keys($related[1]))->toBe([30])
		->and($this->hub()->trash->getRelatedTrashed([]))->toBe([]);
});

it('finds no trashed elements for trashed items without relations', function () {
	expect($this->hub()->trash->getRelatedTrashed([1]))->toBe([]);
});
