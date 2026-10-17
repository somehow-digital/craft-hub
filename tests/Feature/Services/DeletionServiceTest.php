<?php

use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Entries as EntriesField;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\models\DeletionItem;
use SomehowDigital\Craft\Hub\models\DeletionPreview;
use SomehowDigital\Craft\Hub\services\DeletionService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->service = new DeletionService();
	$this->related = $this->field(EntriesField::class, 3, 'related', ['name' => 'Related']);
	$this->section(1, 'news', entryTypes: [$this->entryType(10, 'article', [$this->related])]);

	// Values and labels of the totals of a preview, by their tone.
	$this->effects = fn (DeletionPreview $preview, string $tone) => array_values(array_map(
		fn (array $effect) => $effect['value'] . ' ' . $effect['label'],
		array_filter($preview->effects, fn (array $effect) => $effect['tone'] === $tone),
	));

	// Counts and labels of the groups of an item with anything in them, and the number of what each lists.
	$this->groups = fn (DeletionItem $item) => array_map(fn (array $group) => $group['count'] . ' ' . $group['label'] . ' (' . count($group['items']) . ')', $item->getAffected());

	$this->entry = fn (int $id, array $config = []) => new Entry(['id' => $id, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'title' => "Entry $id", ...$config]);
});

it('previews the entries the user may delete, with the nested entries and drafts of each', function () {
	$one = ($this->entry)(1);
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => match (true) {
		$query->primaryOwnerId !== null => [($this->entry)(7, ['sectionId' => null, 'fieldId' => 2, 'primaryOwnerId' => 1, 'ownerId' => 1])],
		$query->drafts === true => [($this->entry)(8, ['draftId' => 3, 'canonicalId' => 1])],
		default => [$one, ($this->entry)(2)],
	});
	Craft::$app->getElements()->shouldReceive('canDelete')->andReturnUsing(fn (Entry $entry) => $entry->id === 1);

	// One level of nested entries, then none.
	$this->db()->respond('[[entries.primaryOwnerId]]', fn (string $sql) => substr_count($sql, 'primaryOwnerId') > 1 ? 0 : 3);
	$this->db()->respond('INNER JOIN {{%drafts}}', 2);
	$this->db()->respond('FROM {{%structureelements}}', 4);

	$preview = $this->service->getPreview('entries', [1, 2, 3]);

	expect($preview->total)->toBe(1)
		->and($preview->skipped)->toBe(2)
		->and(array_map(fn (DeletionItem $item) => $item->model, $preview->items))->toBe([$one])
		->and(($this->effects)($preview, DeletionPreview::TONE_DELETED))->toBe(['3 Nested entries', '2 Drafts'])
		->and(($this->effects)($preview, DeletionPreview::TONE_CHANGED))->toBe(['4 Child entries moved up'])
		->and(($this->groups)($preview->items[0]))->toBe(['1 Nested entry (1)', '1 Draft (1)'])
		->and($this->db()->queriesMatching('FROM {{%structureelements}}')[0])->toContain('NOT (`children`.`elementId`=1)');
});

it('lists the elements referencing each entry, apart from those deleted along with them', function () {
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => match (true) {
		$query->primaryOwnerId !== null, $query->drafts === true => [],
		$query->id === [1, 2] => [($this->entry)(1), ($this->entry)(2)],
		default => [($this->entry)(2), ($this->entry)(5, ['title' => 'Source'])],
	});
	Craft::$app->getElements()->shouldReceive('canDelete')->andReturn(true);
	$this->db()->respond('FROM {{%relations}} {{relations}}', fn (string $sql) => str_contains($sql, 'GROUP BY') ? [] : [
		['id' => 2, 'type' => Entry::class, 'fieldId' => 3],
		['id' => 5, 'type' => Entry::class, 'fieldId' => 3],
	]);

	$preview = $this->service->getPreview('entries', [1, 2]);
	$group = $preview->items[0]->getAffected()[0];

	expect(($this->effects)($preview, DeletionPreview::TONE_CHANGED))->toBe(['1 Referencing element'])
		->and($group['count'])->toBe(1)
		->and($group['items'][0]->element->id)->toBe(5)
		->and($this->db()->queriesMatching('SELECT DISTINCT `relations`.`sourceId`')[0])->toContain('`relations`.`targetId` IN (1, 2)');
});

it('previews files with their size, and the transforms and users whose photo each is', function () {
	ElementQueries::respond(Asset::class, [
		new Asset(['id' => 1, 'siteId' => 1, 'filename' => 'a.jpg', 'title' => 'A', 'size' => 1000 * 1000]),
		new Asset(['id' => 2, 'siteId' => 1, 'filename' => 'b.jpg', 'title' => 'B', 'size' => 1000 * 1000]),
	]);
	$user = new User(['id' => 9, 'username' => 'editor']);
	ElementQueries::respond(User::class, [$user]);
	Craft::$app->getElements()->shouldReceive('canDelete')->andReturn(true);
	$row = ['id' => '11', 'assetId' => '1', 'transformer' => null, 'filename' => 'a.jpg', 'format' => null, 'transformString' => '_100x100', 'fileExists' => '1', 'inProgress' => '0', 'error' => '0', 'dateIndexed' => null];
	$this->db()->respond('SELECT `id`, `assetId`', [$row, ['id' => '12'] + $row]);
	$this->db()->respond('FROM {{%users}}', [['id' => 9, 'photoId' => 2]]);

	$preview = $this->service->getPreview('files', [1, 2]);

	expect(($this->effects)($preview, DeletionPreview::TONE_DELETED))->toBe(['2.0 MB File size', '2 Transforms'])
		->and(($this->effects)($preview, DeletionPreview::TONE_CHANGED))->toBe(['1 User photo'])
		->and($preview->items[0]->getAffected()[0])->toMatchArray(['count' => 2, 'items' => ['_100x100', '_100x100']])
		->and($preview->items[1]->getAffected()[0])->toMatchArray(['count' => 1, 'items' => [$user]]);
});

it('previews nothing of lists that are not deleted, like the structure', function (string $list) {
	$preview = $this->service->getPreview($list, [1, 2]);

	expect($preview->total)->toBe(0)
		->and($preview->skipped)->toBe(2)
		->and($preview->effects)->toBe([]);
})->with(['users', 'sections', 'entryTypes', 'fields', 'volumes', 'userGroups']);

it('details the items of the page asked for, kept within the pages there are', function () {
	$types = array_map(fn (int $i) => $this->entryType($i, "type$i"), range(1, Paginator::PAGE_SIZE + 2));

	$second = new DeletionPreview();
	$second->page = 2;
	$second->setItems($types, '# entry types', 'shapes');

	$beyond = new DeletionPreview();
	$beyond->page = 9;
	$beyond->setItems($types, '# entry types', 'shapes');

	expect($second->pageInfo->totalPages)->toBe(2)
		->and($second->pageInfo->first)->toBe(Paginator::PAGE_SIZE + 1)
		->and($second->getItemIds())->toBe([Paginator::PAGE_SIZE + 1, Paginator::PAGE_SIZE + 2])
		->and($beyond->page)->toBe(2)
		->and($beyond->getItemIds())->toBe($second->getItemIds());
});

it('details the items of the first page, and lists the first of what each group is about', function () {
	$preview = new DeletionPreview();
	$preview->setItems(array_map(fn (int $i) => $this->entryType($i, "type$i"), range(1, Paginator::PAGE_SIZE + 2)), '# entry types', 'shapes');
	$item = $preview->items[0];
	$item->addGroup(DeletionPreview::TONE_KEPT, 'shapes', 'Kept', 13, array_map(fn (int $i) => "Type $i", range(1, 13)));
	$item->addGroup(DeletionPreview::TONE_KEPT, 'shapes', 'None', 0);
	$preview->addEffect(DeletionPreview::TONE_KEPT, 'shapes', 0, '0', 'None');

	expect($preview->total)->toBe(Paginator::PAGE_SIZE + 2)
		->and($preview->items)->toHaveCount(Paginator::PAGE_SIZE)
		->and($item->groups)->toHaveCount(2)
		->and($item->getAffected())->toHaveCount(1)
		->and($item->groups[1])->toMatchArray(['count' => 0, 'items' => []])
		->and($item->groups[0]['items'])->toHaveCount(DeletionItem::ELEMENT_LIMIT)
		->and($item->groups[0]['more'])->toBe(3)
		->and($preview->effects)->toBe([]);
});
