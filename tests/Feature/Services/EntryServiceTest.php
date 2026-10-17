<?php

use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\Tests\Support\CountableEntryQuery;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->site(2, 'de');
	$article = $this->entryType(10, 'article');
	$this->section(1, 'news', craft\models\Section::TYPE_CHANNEL, [$article]);
	$this->section(2, 'pages', craft\models\Section::TYPE_CHANNEL, [$article]);
	$this->entries = new EntryService();
});


function entryFilter(array $values): EntryFilter
{
	$filter = new EntryFilter();

	foreach ($values as $name => $value) {
		$filter->$name = $value;
	}

	return $filter;
}

it('queries the section entries on the counted sites, most recently updated first', function () {
	$query = $this->entries->getQuery(new EntryFilter());

	expect($query->siteId)->toBe([1, 2])
		->and($query->unique)->toBeTrue()
		->and($query->status)->toBeNull()
		->and($query->orderBy)->toBe(['elements.dateUpdated' => SORT_DESC, 'elements.id' => SORT_DESC])
		->and(conditions($query))->toBe([['not', ['entries.sectionId' => null]]])
		->and($query->sectionId)->toBeNull()
		->and($query->dateUpdated)->toBeNull()
		->and($query->postDate)->toBeNull();
});

it('narrows the entries by the filters', function () {
	$query = $this->entries->getQuery(entryFilter([
		'search' => 'news',
		'sections' => ['news'],
		'types' => ['article'],
		'statuses' => ['live', 'disabled'],
		'authors' => [3],
		'postedFrom' => new DateTime('2024-01-01 12:00'),
		'postedTo' => new DateTime('2024-01-31'),
	]));

	expect($query->status)->toBe(['live', 'disabled'])
		->and($query->sectionId)->toBe([1])
		->and($query->typeId)->toBe([10])
		->and($query->authorId)->toBe([3])
		->and($query->postDate)->toBe(['and', '>= 2024-01-01T00:00:00+00:00', '< 2024-02-01T00:00:00+00:00'])
		->and(conditions($query)[1])->toBe(['or', ['like', 'elements_sites.title', 'news'], ['like', 'elements_sites.slug', 'news']]);
});

it('narrows the entries by references and relations', function (?string $usage, string $operator) {
	$conditions = conditions($this->entries->getQuery(entryFilter(['usage' => $usage, 'relationsMin' => 2])));

	expect($conditions)->toHaveCount(3)
		->and($conditions[1][0])->toBe($operator)
		->and($conditions[2][0])->toBe('and')
		->and($conditions[2][1][0])->toBe('>=')
		->and($conditions[2][1][2])->toBe(2)
		->and($conditions[2][2])->toBe([]);
})->with([
	'referenced' => [EntryService::USAGE_REFERENCED, 'exists'],
	'unreferenced' => [EntryService::USAGE_UNREFERENCED, 'not exists'],
]);

it('ignores unknown usage values', function () {
	expect(conditions($this->entries->getQuery(entryFilter(['usage' => 'any']))))->toHaveCount(1);
});

it('narrows the entries by the date of the last update and staleness', function () {
	$updated = $this->entries->getQuery(entryFilter(['updatedFrom' => new DateTime('2024-01-01')]));
	$before = new DateTime('-6 months -1 second');
	$stale = $this->entries->getQuery(entryFilter(['staleMonths' => 6]));
	$after = new DateTime('-6 months');
	$both = $this->entries->getQuery(entryFilter(['updatedTo' => new DateTime('2024-01-01'), 'staleMonths' => 6]));

	expect($updated->dateUpdated)->toBe(['and', '>= 2024-01-01T00:00:00+00:00'])
		->and($stale->dateUpdated)->toHaveCount(2)
		->and($stale->dateUpdated[1])->toStartWith('< ')
		->and(new DateTime(substr($stale->dateUpdated[1], 2)))->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual($after)
		->and($both->dateUpdated)->toHaveCount(3)
		->and($both->dateUpdated[1])->toBe('< 2024-01-02T00:00:00+00:00');
});

it('narrows the entries to the ones with drafts', function () {
	$conditions = conditions($this->entries->getQuery(entryFilter(['drafts' => true])));

	expect($conditions[1][0])->toBe('exists')
		->and($conditions[1][1]->createCommand()->getRawSql())->toContain('`hubDraftsInfo`.`provisional`=FALSE');
});

it('narrows the entries by their diagnoses', function () {
	$checks = $this->mockComponent('hub.checks', CheckService::class);
	$checks->shouldReceive('entryDiagnosisCondition')->with(['warning'])->andReturn(['elements.id' => [1]]);

	expect(conditions($this->entries->getQuery(entryFilter(['diagnoses' => ['warning']])))[1])->toBe(['elements.id' => [1]]);
});

it('sorts the entries', function (string $sort, string $column) {
	$order = $this->entries->getQuery(new EntryFilter(), $sort, SORT_ASC)->orderBy;

	expect(array_values($order))->toBe([SORT_ASC, SORT_ASC])
		->and(array_key_first($order))->toContain($column);
})->with([
	'title' => [EntryService::SORT_TITLE, 'elements_sites.title'],
	'section' => [EntryService::SORT_SECTION, '[[hubSections.name]]'],
	'type' => [EntryService::SORT_TYPE, '[[hubEntryTypes.name]]'],
	'relations' => [EntryService::SORT_RELATIONS, 'COUNT(*)'],
	'posted' => [EntryService::SORT_POSTED, 'entries.postDate'],
	'updated' => [EntryService::SORT_UPDATED, 'elements.dateUpdated'],
	'unknown' => ['unknown', 'elements.dateUpdated'],
]);

it('lists the sort columns with their default direction', function () {
	expect($this->entries->getSortColumns())->toBe([
		'title' => SORT_ASC,
		'section' => SORT_ASC,
		'type' => SORT_ASC,
		'relations' => SORT_DESC,
		'posted' => SORT_DESC,
		'updated' => SORT_DESC,
	])->and($this->entries->getStatuses())->toBe(['live', 'pending', 'expired', 'disabled']);
});

it('lists the authors of section entries, most entries first', function () {
	$this->db()->respond('FROM {{%entries_authors}} {{entries_authors}}', [
		['authorId' => '3', 'count' => '5'],
		['authorId' => '4', 'count' => '2'],
		// Users that no longer exist are left out.
		['authorId' => '5', 'count' => '1'],
	]);

	$author = new User(['id' => 3, 'username' => 'author']);
	$editor = new User(['id' => 4, 'username' => 'editor']);
	ElementQueries::respond(User::class, fn (ElementQuery $query) => [3 => $author, 4 => $editor]);

	expect($this->entries->getAuthors(1, 10))->toBe([3 => ['user' => $author, 'count' => 5], 4 => ['user' => $editor, 'count' => 2]])
		->and($this->db()->queriesMatching('{{%entries_authors}}')[0])->toContain('`entries`.`sectionId`=1')
		->and($this->db()->queriesMatching('{{%entries_authors}}')[0])->toContain('`entries`.`typeId`=10')
		->and(ElementQueries::executed(User::class)[0]->id)->toBe([3, 4, 5]);
});

it('lists no authors when no entries have any', function () {
	expect($this->entries->getAuthors())->toBe([])
		->and($this->db()->queriesMatching('{{%entries_authors}}')[0])->not->toContain('`entries`.`sectionId`=')
		->and(ElementQueries::executed(User::class))->toBe([]);
});

it('counts the entries matching each value of a filter', function () {
	$entries = Mockery::mock(EntryService::class)->makePartial();
	$entries->shouldReceive('getQuery')->andReturnUsing(fn (EntryFilter $filter) => CountableEntryQuery::of((new EntryService())->getQuery($filter)));

	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => match ($query->sectionId) {
		[1] => [new Entry(), new Entry()],
		default => [new Entry()],
	});

	$base = entryFilter(['search' => 'term']);
	$counts = $entries->getCounts(['news', 'pages'], fn (EntryFilter $filter, string $value) => $filter->sections = [$value], $base);

	expect($counts)->toBe(['news' => 2, 'pages' => 1])
		->and($base->sections)->toBe([])
		->and(ElementQueries::executed(Entry::class)[0]->where[2][0])->toBe('or')
		->and($entries->getCounts(['live'], fn (EntryFilter $filter, string $value) => $filter->statuses = [$value]))->toBe(['live' => 1]);
});

it('finds a section entry on the selected sites, preferring the current site', function () {
	$entry = new Entry(['id' => 1, 'siteId' => 2]);
	ElementQueries::respond(Entry::class, [$entry]);
	$this->request([Hub::SITE_PARAM => 'de']);

	expect($this->entries->getEntry(1))->toBe($entry);

	$query = ElementQueries::executed(Entry::class)[0];

	expect($query->id)->toBe(1)
		->and($query->siteId)->toBe([2])
		->and($query->preferSites)->toBe([2, 1])
		->and($query->status)->toBeNull()
		->and($this->entries->getEntry(2))->toBe($entry);
});

it('finds no entry when none matches', function () {
	expect($this->entries->getEntry(1))->toBeNull();
});

it('finds the entry on each counted site', function () {
	$variants = [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 1, 'siteId' => 2])];
	ElementQueries::respond(Entry::class, $variants);

	expect($this->entries->getSiteVariants(new Entry(['id' => 1])))->toBe($variants)
		->and(ElementQueries::executed(Entry::class)[0]->siteId)->toBe([1, 2])
		->and(ElementQueries::executed(Entry::class)[0]->orderBy)->toBe(['elements_sites.siteId' => SORT_ASC]);
});
