<?php

use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Entries as EntriesField;
use craft\fields\Matrix;
use craft\fields\PlainText;
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

it('finds the live elements referencing an element', function () {
	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows([[2, Entry::class, 3]]));
	ElementQueries::respond(Entry::class, [new Entry(['id' => 2, 'siteId' => 1, 'title' => 'Source', 'typeId' => 10])]);

	$references = $this->service->getReferences($this->target);
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

	$this->service->getReferences(new Entry(['id' => 1, 'siteId' => 2]));
	$query = ElementQueries::executed(Entry::class)[0];

	expect($query->id)->toBe([2])
		->and($query->siteId)->toBe([1, 2])
		->and($query->preferSites)->toBe([2])
		->and($query->unique)->toBeTrue()
		->and($query->status)->toBeNull();
});

it('finds the elements referencing any of several elements, leaving out the excluded ones', function () {
	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows([[2, Entry::class, 3], [4, Entry::class, 3]]));
	ElementQueries::respond(Entry::class, [
		new Entry(['id' => 2, 'siteId' => 1, 'title' => 'Deleted too', 'typeId' => 10]),
		new Entry(['id' => 4, 'siteId' => 1, 'title' => 'Source', 'typeId' => 10]),
	]);

	$result = $this->service->getReferencesTo([1, 2], fn ($root) => $root->id === 2);
	$sql = $this->db()->queriesMatching('FROM {{%relations}} {{relations}}')[0];

	expect(array_map(fn (EntryReference $reference) => $reference->element->id, $result['references']))->toBe([4])
		->and($result['capped'])->toBeFalse()
		->and($sql)->toContain('`relations`.`targetId` IN (1, 2)')
		->and($sql)->toContain('LIMIT ' . (ReferenceService::SOURCE_LIMIT + 1))
		->and($this->service->getReferencesTo([], fn () => false))->toBe(['references' => [], 'capped' => false]);
});

it('tells whether more elements reference the elements than were read', function () {
	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows(array_map(fn (int $id) => [$id, Entry::class, 3], range(2, ReferenceService::SOURCE_LIMIT + 2))));

	expect($this->service->getReferencesTo([1], fn () => false)['capped'])->toBeTrue();
});

it('attributes references from nested entries to their root owner', function () {
	$owner = new Entry(['id' => 9, 'siteId' => 1, 'title' => 'Owner', 'typeId' => 10]);
	$nested = new Entry(['id' => 8, 'siteId' => 1, 'title' => 'Block', 'typeId' => 12, 'fieldId' => 2, 'primaryOwnerId' => 9, 'ownerId' => 9]);
	$nested->setOwner($owner);

	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows([[8, Entry::class, 3], [9, Entry::class, 3]]));
	ElementQueries::respond(Entry::class, [$nested, $owner]);

	$references = $this->service->getReferences($this->target);

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

	expect($this->service->getReferences($this->target))->toBe([]);
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

	$references = $this->service->getReferences($this->target);

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

	expect($this->service->getReferences($this->target))->toBe([]);
});

it('sorts the references by their elements', function () {
	$this->db()->respond('FROM {{%relations}} {{relations}}', referenceRows([[2, Entry::class, 3], [3, User::class, 3], [4, Entry::class, 3]]));
	ElementQueries::respond(Entry::class, [
		new Entry(['id' => 2, 'siteId' => 1, 'title' => 'Entry 10', 'typeId' => 10]),
		new Entry(['id' => 4, 'siteId' => 1, 'title' => 'entry 9', 'typeId' => 10]),
	]);
	ElementQueries::respond(User::class, [new User(['id' => 3, 'username' => 'author', 'fullName' => 'Author'])]);

	$references = $this->service->getReferences($this->target);

	expect(array_map(fn (EntryReference $reference) => (string)$reference->element, $references))->toBe(['Author', 'entry 9', 'Entry 10']);
});

it('counts the live references of elements', function () {
	$this->db()->respond('FROM {{%relations}} {{relations}}', [
		['elementId' => '1', 'references' => '3'],
		['elementId' => '4', 'references' => '1'],
	]);

	expect($this->service->getReferenceCounts([1, 4, 5]))->toBe([1 => 3, 4 => 1])
		->and($this->db()->queriesMatching('FROM {{%relations}} {{relations}}')[0])->toContain('`relations`.`targetId` IN (1, 4, 5)');
});

it('counts the references of no elements without a query', function () {
	expect($this->service->getReferenceCounts([]))->toBe([])
		->and($this->db()->queries)->toBe([]);
});

it('finds the elements referencing an element through reference tags, by the field holding them', function () {
	$text = $this->field(PlainText::class, 4, 'body', ['name' => 'Body']);
	$this->db()->respond('FROM {{%hub_references}} {{hubReferences}}', referenceRows([[2, Entry::class, 4]]));
	ElementQueries::respond(Entry::class, [new Entry(['id' => 2, 'siteId' => 1, 'title' => 'Source', 'typeId' => 10])]);

	$references = $this->service->getReferences($this->target);
	$sql = $this->db()->queriesMatching('FROM {{%hub_references}} {{hubReferences}}')[0];

	expect($references)->toHaveCount(1)
		->and($references[0]->paths)->toBe(['Body' => ['label' => 'Body', 'field' => $text]])
		->and($sql)->toContain('`hubReferences`.`targetId`=1')
		->and($sql)->toContain('`sources`.`dateDeleted` IS NULL');
});

it('counts the reference tags of elements along with their relations', function () {
	$this->db()->respond('FROM {{%relations}} {{relations}}', [['elementId' => '1', 'references' => '3']]);
	$this->db()->respond('FROM {{%hub_references}} {{hubReferences}}', [
		['elementId' => '1', 'references' => '2'],
		['elementId' => '5', 'references' => '1'],
	]);

	expect($this->service->getReferenceCounts([1, 4, 5]))->toBe([1 => 5, 5 => 1]);
});

describe('reference tags', function () {
	beforeEach(function () {
		$this->text = $this->field(PlainText::class, 4, 'body', ['name' => 'Body']);
		$this->layout = $this->layout(Entry::class, 'tags', [$this->text, $this->related]);
		[$this->textUid, $this->relatedUid] = ['uid-body', 'uid-related'];
		[$body, $related] = $this->layout->getCustomFieldElements();
		$body->uid = $this->textUid;
		$related->uid = $this->relatedUid;
		Craft::$app->getFields()->shouldReceive('getLayoutById')->with(7)->andReturn($this->layout);
	});

	it('finds reference tags by ID in the content of fields', function () {
		$content = json_encode([
			$this->textUid => '<a href="{entry:12@1:url||https://example.com}">a</a> <img src="{asset:34:url}"> {entry:56@1:url||} {entry:78}',
			$this->relatedUid => [99],
		]);

		expect($this->service->findTags($content, 7))->toBe([[4, 12], [4, 34], [4, 56], [4, 78]]);
	});

	it('finds reference tags in values other than text, like those of tables', function () {
		expect($this->service->findTags([$this->textUid => [['cell' => '{entry:12:url}']]], 7))->toBe([[4, 12]]);
	});

	it('leaves out relation fields, tags by anything but an ID, unknown element types and layout elements', function () {
		$content = [
			$this->relatedUid => '{entry:12}',
			$this->textUid => '{entry:about/us:url} {nothing:12} {entry:} entry:34',
			'unknown-uid' => '{entry:56}',
		];

		expect($this->service->findTags($content, 7))->toBe([])
			->and($this->service->findTags('not json', 7))->toBe([])
			->and($this->service->findTags([$this->textUid => '{entry:12}'], null))->toBe([]);
	});

	it('indexes the reference tags of elements, replacing what was indexed for them', function () {
		$this->db()->respond('FROM {{%elements_sites}} {{elements_sites}}', [
			['elementId' => 5, 'siteId' => 1, 'content' => json_encode([$this->textUid => '{entry:12} {entry:12} {asset:34} {entry:5} {entry:404}']), 'fieldLayoutId' => 7],
			['elementId' => 5, 'siteId' => 2, 'content' => json_encode([$this->textUid => '{entry:12}']), 'fieldLayoutId' => 7],
		]);
		$this->db()->respond('SELECT `id`', [['id' => 12], ['id' => 34]]);

		$this->service->index([5, 6]);

		$read = $this->db()->queriesMatching('FROM {{%elements_sites}} {{elements_sites}}')[0];
		$deleted = $this->db()->queriesMatching('DELETE FROM {{%hub_references}}');
		$inserted = $this->db()->queriesMatching('INSERT INTO {{%hub_references}}');

		expect($read)->toContain('`elements_sites`.`elementId` IN (5, 6)')
			->and($read)->toContain('`elements`.`draftId` IS NULL')
			->and($read)->toContain('`elements`.`revisionId` IS NULL')
			->and($deleted)->toHaveCount(1)
			->and($deleted[0])->toContain('`sourceId` IN (5, 6)')
			->and($inserted)->toHaveCount(1)
			// The element's own tag and the tag of a missing element are left out, and each tag is indexed once per site.
			->and($inserted[0])->toContain('(5, 1, 4, 12), (5, 1, 4, 34), (5, 2, 4, 12)')
			->and($inserted[0])->not->toContain('404');
	});

	it('clears the index of elements without reference tags', function () {
		$this->service->index([5]);

		expect($this->db()->queriesMatching('DELETE FROM {{%hub_references}}'))->toHaveCount(1)
			->and($this->db()->queriesMatching('INSERT INTO {{%hub_references}}'))->toBe([]);
	});

	it('indexes nothing without elements', function () {
		$this->service->index([]);

		expect($this->db()->queries)->toBe([]);
	});

	it('indexes the elements that aren\'t drafts or revisions', function () {
		$sql = rawSql($this->service->indexableQuery());

		expect($sql)->toContain('`draftId` IS NULL')
			->and($sql)->toContain('`revisionId` IS NULL')
			->and($sql)->toContain('ORDER BY `id`');
	});
});
