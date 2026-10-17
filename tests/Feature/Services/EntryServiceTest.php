<?php

use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\services\EntryService;
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

it('queries the section and nested entries on the counted sites, most recently edited first', function () {
	$query = $this->entries->getQuery(new EntryFilter());
	[[$or, $section, [$exists, $owners]]] = conditions($query);

	expect($query->siteId)->toBe([1, 2])
		->and($query->unique)->toBeTrue()
		->and($query->status)->toBeNull()
		->and($query->orderBy)->toBe([$this->hub()->edits->lastEditedSql() => SORT_DESC, 'elements.id' => SORT_DESC])
		->and(conditions($query))->toHaveCount(1)
		// Nested entries owned by drafts or revisions are copies, so they are left out.
		->and([$or, $section, $exists])->toBe(['or', ['entries.primaryOwnerId' => null], 'exists'])
		->and(rawSql($owners))->toContain('`hubOwners`.`id` = `entries`.`primaryOwnerId`')
		->and(rawSql($owners))->toContain('`hubOwners`.`draftId` IS NULL')
		->and(rawSql($owners))->toContain('`hubOwners`.`revisionId` IS NULL')
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

it('narrows the entries to section or nested entries', function (string $origin, array $condition) {
	expect(conditions($this->entries->getQuery(entryFilter(['origin' => $origin])))[1])->toBe($condition);
})->with([
	'section' => [EntryService::ORIGIN_SECTION, ['not', ['entries.sectionId' => null]]],
	'nested' => [EntryService::ORIGIN_NESTED, ['entries.sectionId' => null]],
]);

it('ignores unknown origins', function () {
	expect(conditions($this->entries->getQuery(entryFilter(['origin' => 'any']))))->toHaveCount(1);
});

it('narrows the entries to the nested entries of fields', function () {
	$this->field(craft\fields\Matrix::class, 2, 'blocks');

	expect(conditions($this->entries->getQuery(entryFilter(['fields' => ['blocks', 'bogus']])))[1])->toBe(['entries.fieldId' => [2]]);
});

it('narrows the entries by usage and number of references, relations and reference tags alike', function (?string $usage, bool $negated) {
	$conditions = conditions($this->entries->getQuery(entryFilter(['usage' => $usage, 'referencesMin' => 2])));
	$referenced = $negated ? $conditions[1][1] : $conditions[1];

	expect($conditions)->toHaveCount(3)
		->and($conditions[1][0])->toBe($negated ? 'not' : 'or')
		->and($referenced)->toEqual($this->hub()->review->referencedCondition())
		->and($conditions[2][0])->toBe('and')
		->and($conditions[2][1][0])->toBe('>=')
		->and((string)$conditions[2][1][1])->toContain('FROM `hub_references` `hubReferences`')
		->and((string)$conditions[2][1][1])->toContain('FROM `relations` `hubRelations`')
		->and($conditions[2][1][2])->toBe(2)
		->and($conditions[2][2])->toBe([]);
})->with([
	'referenced' => [EntryService::USAGE_REFERENCED, false],
	'unreferenced' => [EntryService::USAGE_UNREFERENCED, true],
]);

it('ignores unknown usage values', function () {
	expect(conditions($this->entries->getQuery(entryFilter(['usage' => 'any']))))->toHaveCount(1);
});

it('narrows the entries by the date of the last edit and staleness', function () {
	$edited = $this->hub()->edits->lastEditedSql();
	$updated = conditions($this->entries->getQuery(entryFilter(['updatedFrom' => new DateTime('2024-01-01')])));
	$before = new DateTime('-6 months -1 second');
	$stale = conditions($this->entries->getQuery(entryFilter(['staleMonths' => 6])));
	$after = new DateTime('-6 months');
	$both = conditions($this->entries->getQuery(entryFilter(['updatedTo' => new DateTime('2024-01-01'), 'staleMonths' => 6])));

	expect($updated[1])->toBe(['>=', $edited, '2024-01-01 00:00:00'])
		->and($stale[1][0])->toBe('<')
		->and($stale[1][1])->toBe($edited)
		->and(new DateTime($stale[1][2], new DateTimeZone('UTC')))->toBeGreaterThanOrEqual((clone $before)->setTime((int)$before->format('H'), (int)$before->format('i'), (int)$before->format('s') - 1))->toBeLessThanOrEqual($after)
		->and($both[1])->toBe(['and', ['<', $edited, '2024-01-02 00:00:00'], ['<', $edited, $stale[1][2]]]);
});

it('narrows the entries to the ones with drafts', function () {
	$conditions = conditions($this->entries->getQuery(entryFilter(['drafts' => true])));

	[$and, [$in, $column, $drafted], [$exists, $drafts]] = $conditions[1];

	// Only the entries in the drafts table are looked up in the elements, which hold the revisions of all entries too.
	expect($and)->toBe('and')
		->and([$in, $column])->toBe(['in', 'elements.id'])
		->and($drafted->createCommand()->getRawSql())->toContain('FROM `drafts` `hubDrafted`')
		->and($drafted->createCommand()->getRawSql())->toContain('`hubDrafted`.`provisional`=FALSE')
		->and($exists)->toBe('exists')
		->and($drafts->createCommand()->getRawSql())->toContain('`hubDraftsInfo`.`provisional`=FALSE');
});

it('sorts the entries', function (string $sort, string $column) {
	$order = $this->entries->getQuery(new EntryFilter(), $sort, SORT_ASC)->orderBy;

	expect(array_values($order))->toBe([SORT_ASC, SORT_ASC])
		->and(array_key_first($order))->toContain($column);
})->with([
	'title' => [EntryService::SORT_TITLE, 'elements_sites.title'],
	'section' => [EntryService::SORT_SECTION, '[[hubSections.name]]'],
	'type' => [EntryService::SORT_TYPE, '[[hubEntryTypes.name]]'],
	'references' => [EntryService::SORT_REFERENCES, 'COUNT(*)'],
	'posted' => [EntryService::SORT_POSTED, 'entries.postDate'],
	'updated' => [EntryService::SORT_UPDATED, 'elements.dateUpdated'],
	'unknown' => ['unknown', 'elements.dateUpdated'],
]);

it('lists the sort columns with their default direction', function () {
	expect($this->entries->getSortColumns())->toBe([
		'title' => SORT_ASC,
		'section' => SORT_ASC,
		'type' => SORT_ASC,
		'references' => SORT_DESC,
		'posted' => SORT_DESC,
		'updated' => SORT_DESC,
	])->and($this->entries->getStatuses())->toBe(['live', 'pending', 'expired', 'disabled']);
});

it('lists the authors of entries, most entries first', function () {
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
		->and($this->db()->queriesMatching('{{%entries_authors}}')[0])->not->toContain('`entries`.`sectionId`')
		->and(ElementQueries::executed(User::class))->toBe([]);
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

it('lists the authors of the entries of a field', function () {
	$this->entries->getAuthors(fieldId: 2);

	expect($this->db()->queriesMatching('{{%entries_authors}}')[0])->toContain('`entries`.`fieldId`=2');
});

it('finds nested entries too', function () {
	$this->entries->getEntry(1);

	expect(ElementQueries::executed(Entry::class)[0]->where)->toBeNull();
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

it('queries the revisions of an entry on its site, newest first', function () {
	$query = $this->entries->getRevisionsQuery(new Entry(['id' => 1, 'siteId' => 2]));

	expect($query->revisionOf)->toBe(1)
		->and($query->siteId)->toBe(2)
		->and($query->status)->toBeNull()
		->and($query->orderBy)->toBe(['num' => SORT_DESC])
		->and($query->with)->toBe(['revisionCreator']);
});

it('finds the saved drafts of an entry on its site, last updated first', function () {
	$drafts = [new Entry(['id' => 3, 'siteId' => 2])];
	ElementQueries::respond(Entry::class, $drafts);

	expect($this->entries->getDrafts(new Entry(['id' => 1, 'siteId' => 2])))->toBe($drafts);

	$query = ElementQueries::executed(Entry::class)[0];

	expect($query->draftOf)->toBe(1)
		->and($query->provisionalDrafts)->toBeFalse()
		->and($query->siteId)->toBe(2)
		->and($query->status)->toBeNull()
		->and($query->orderBy)->toBe(['dateUpdated' => SORT_DESC])
		->and($query->with)->toBe(['draftCreator']);
});
