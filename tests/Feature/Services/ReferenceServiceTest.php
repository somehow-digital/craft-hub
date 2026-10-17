<?php

use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Entries as EntriesField;
use craft\fields\Matrix;
use SomehowDigital\Craft\Hub\models\EntryReference;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->related = $this->field(EntriesField::class, 3, 'related', ['name' => 'Related']);
	$block = $this->entryType(12, 'block', [$this->related]);
	$blocks = $this->field(Matrix::class, 2, 'blocks', ['name' => 'Blocks', 'entryTypes' => [$block]]);
	$this->entryType(10, 'article', [$blocks, $this->related]);

	$this->target = new Entry(['id' => 1, 'siteId' => 1, 'title' => 'Target', 'typeId' => 10]);
	$this->service = new ReferenceService();
});

function referenceRows(array $sources): array
{
	return array_map(fn (array $source) => ['id' => $source[0], 'type' => $source[1], 'fieldId' => $source[2]], $sources);
}

it('finds the live elements relating to an element', function () {
	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows([[2, Entry::class, 3]]));
	ElementQueries::respond(Entry::class, [new Entry(['id' => 2, 'siteId' => 1, 'title' => 'Source', 'typeId' => 10])]);

	$references = $this->service->getRelations($this->target);
	$sql = $this->db()->queriesMatching('FROM {{%relations}} {{relations}}')[0];

	expect($references)->toHaveCount(1)
		->and($references[0])->toBeInstanceOf(EntryReference::class)
		->and($references[0]->element->id)->toBe(2)
		->and($references[0]->paths)->toBe(['Related' => ['label' => 'Related', 'field' => $this->related]])
		->and($sql)->toContain('`relations`.`targetId`=1')
		->and($sql)->toContain('`sources`.`revisionId` IS NULL');
});

it('looks up the referencing elements on any site, preferring the site of the element', function () {
	$this->site(2, 'de');
	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows([[2, Entry::class, 3], [2, Entry::class, 3]]));
	ElementQueries::respond(Entry::class, [new Entry(['id' => 2, 'siteId' => 1, 'title' => 'Source', 'typeId' => 10])]);

	$this->service->getRelations(new Entry(['id' => 1, 'siteId' => 2]));
	$query = ElementQueries::executed(Entry::class)[0];

	expect($query->id)->toBe([2])
		->and($query->siteId)->toBe([1, 2])
		->and($query->preferSites)->toBe([2])
		->and($query->unique)->toBeTrue()
		->and($query->status)->toBeNull();
});

it('attributes references from nested entries to their root owner', function () {
	$owner = new Entry(['id' => 9, 'siteId' => 1, 'title' => 'Owner', 'typeId' => 10]);
	$nested = new Entry(['id' => 8, 'siteId' => 1, 'title' => 'Block', 'typeId' => 12, 'fieldId' => 2, 'primaryOwnerId' => 9, 'ownerId' => 9]);
	$nested->setOwner($owner);

	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows([[8, Entry::class, 3], [9, Entry::class, 3]]));
	ElementQueries::respond(Entry::class, [$nested, $owner]);

	$references = $this->service->getRelations($this->target);

	expect($references)->toHaveCount(1)
		->and($references[0]->element)->toBe($owner)
		->and(array_keys($references[0]->paths))->toBe(['Blocks → Related', 'Related']);
});

it('leaves out references from copies owned by drafts, from the element itself and from missing elements', function () {
	$draft = new Entry(['id' => 9, 'siteId' => 1, 'typeId' => 10, 'draftId' => 4]);
	$copy = new Entry(['id' => 8, 'siteId' => 1, 'typeId' => 12, 'fieldId' => 2, 'primaryOwnerId' => 9, 'ownerId' => 9]);
	$copy->setOwner($draft);

	$own = new Entry(['id' => 7, 'siteId' => 1, 'typeId' => 12, 'fieldId' => 2, 'primaryOwnerId' => 1, 'ownerId' => 1]);
	$own->setOwner($this->target);

	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows([
		[8, Entry::class, 3],
		[7, Entry::class, 3],
		[6, Entry::class, 3],
		[5, 'Missing\\Element', 3],
	]));
	ElementQueries::respond(Entry::class, [$copy, $own]);

	expect($this->service->getRelations($this->target))->toBe([]);
});

it('names fields that no longer exist', function () {
	$owner = new Entry(['id' => 9, 'siteId' => 1, 'title' => 'Owner', 'typeId' => 10]);
	$nested = Mockery::mock(Entry::class)->makePartial();
	$nested->id = 8;
	$nested->siteId = 1;
	$nested->primaryOwnerId = 9;
	$nested->shouldReceive('getOwner')->andReturn($owner);
	$nested->shouldReceive('getField')->andReturnNull();
	$nested->shouldReceive('getFieldLayout')->andReturnNull();

	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows([[8, Entry::class, 99]]));
	ElementQueries::respond(Entry::class, [$nested]);

	$references = $this->service->getRelations($this->target);

	expect(array_keys($references[0]->paths))->toBe(['Unknown field → Unknown field'])
		->and($references[0]->paths['Unknown field → Unknown field']['field'])->toBeNull();
});

it('leaves out nested entries whose owner no longer exists', function () {
	$nested = Mockery::mock(Entry::class)->makePartial();
	$nested->id = 8;
	$nested->siteId = 1;
	$nested->primaryOwnerId = 9;
	$nested->shouldReceive('getOwner')->andReturnNull();

	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows([[8, Entry::class, 3]]));
	ElementQueries::respond(Entry::class, [$nested]);

	expect($this->service->getRelations($this->target))->toBe([]);
});

it('sorts the references by their elements', function () {
	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows([[2, Entry::class, 3], [3, User::class, 3], [4, Entry::class, 3]]));
	ElementQueries::respond(Entry::class, [
		new Entry(['id' => 2, 'siteId' => 1, 'title' => 'Entry 10', 'typeId' => 10]),
		new Entry(['id' => 4, 'siteId' => 1, 'title' => 'entry 9', 'typeId' => 10]),
	]);
	ElementQueries::respond(User::class, [new User(['id' => 3, 'username' => 'author', 'fullName' => 'Author'])]);

	$references = $this->service->getRelations($this->target);

	expect(array_map(fn (EntryReference $reference) => (string)$reference->element, $references))->toBe(['Author', 'entry 9', 'Entry 10']);
});

it('counts the live relations of elements', function () {
	$this->db()->respond('FROM {{%relations}} {{relations}}', [
		['elementId' => '1', 'relations' => '3'],
		['elementId' => '4', 'relations' => '1'],
	]);

	expect($this->service->getRelationCounts([1, 4, 5]))->toBe([1 => 3, 4 => 1])
		->and($this->db()->queriesMatching('FROM {{%relations}} {{relations}}')[0])->toContain('`relations`.`targetId` IN (1, 4, 5)');
});

it('counts the relations of no elements without a query', function () {
	expect($this->service->getRelationCounts([]))->toBe([])
		->and($this->db()->queries)->toBe([]);
});
