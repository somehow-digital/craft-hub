<?php

declare(strict_types=1);

use ArrayObject;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\fields\Matrix;
use craft\models\Section;
use SomehowDigital\Craft\Hub\elements\db\LastEditedEntryQuery;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\FilterParams;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function (): void {
	$this->site(2, 'de');
	$article = $this->entryType(10, 'article');
	$this->section(1, 'news', Section::TYPE_CHANNEL, [$article]);
	$this->section(2, 'pages', Section::TYPE_CHANNEL, [$article]);
});

/**
 * @param array<string,mixed> $values
 */
function entryFilter(array $values): EntryFilter
{
	$filter = new EntryFilter();

	Craft::configure($filter, $values);

	return $filter;
}

it('queries the section and nested entries on the counted sites, most recently edited first', function (): void {
	$query = $this->hub()->entries->getQuery(new EntryFilter());
	[$or, $section, $nested] = parts(conditions($query)[0]);
	[$exists, $owners] = parts($nested);

	expect($query->siteId)->toBe([1, 2])
		->and($query->unique)->toBeTrue()
		->and($query->status)->toBeNull()
		->and($query)->toBeInstanceOf(LastEditedEntryQuery::class)
		->and($query->orderBy)->toBe([LastEditedEntryQuery::LAST_EDITED => SORT_DESC, 'elements.id' => SORT_DESC])
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

it('narrows the entries by the filters', function (): void {
	$query = $this->hub()->entries->getQuery(entryFilter([
		'search' => 'news',
		'sections' => ['news'],
		'types' => ['article'],
		'statuses' => ['live', 'disabled'],
		'authors' => [3],
		'postedFrom' => new DateTime('2024-01-01 12:00'),
		'postedTo' => new DateTime('2024-01-31'),
	]));

	expect($query->status)->toBe(['live', 'disabled'])
		->and(conditions($query)[2])->toBe(['or', ['entries.sectionId' => [1]]])
		->and($query->typeId)->toBe([10])
		->and(at($query, 'authorId'))->toBe([3])
		->and($query->postDate)->toBe(['and', '>= 2024-01-01T00:00:00+00:00', '< 2024-02-01T00:00:00+00:00'])
		->and(conditions($query)[1])->toBe(['or', ['like', 'elements_sites.title', 'news'], ['like', 'elements_sites.slug', 'news']]);
});

it('narrows the entries to section or nested entries', function (string $origin, array $condition): void {
	expect(conditions($this->hub()->entries->getQuery(entryFilter(['origin' => $origin])))[1])->toBe($condition);
})->with([
	'section' => [EntryService::ORIGIN_SECTION, ['not', ['entries.sectionId' => null]]],
	'nested' => [EntryService::ORIGIN_NESTED, ['entries.sectionId' => null]],
]);

it('ignores unknown origins', function (): void {
	expect(conditions($this->hub()->entries->getQuery(entryFilter(['origin' => 'any']))))->toHaveCount(1);
});

it('narrows the entries to the nested entries of fields', function (): void {
	$this->field(Matrix::class, 2, 'blocks');

	expect(conditions($this->hub()->entries->getQuery(entryFilter(['fields' => ['blocks', 'bogus']])))[1])->toBe(['or', ['entries.fieldId' => [2]]]);
});

it('matches entries of either the sections or the fields', function (): void {
	$this->field(Matrix::class, 2, 'blocks');

	expect(conditions($this->hub()->entries->getQuery(entryFilter(['sections' => ['news'], 'fields' => ['blocks']])))[1])
		->toBe(['or', ['entries.sectionId' => [1]], ['entries.fieldId' => [2]]]);
});

it('narrows the entries by usage and number of references, relations and reference tags alike', function (?string $usage, bool $negated): void {
	$conditions = conditions($this->hub()->entries->getQuery(entryFilter(['usage' => $usage, 'referencesMin' => 2])));
	$review = $this->hub()->review;

	expect($conditions)->toHaveCount(3)
		->and($conditions[1])->toEqual($negated ? $review->unreferencedCondition(false) : $review->referencedCondition(false))
		->and(at($conditions, 2, 0))->toBe('and')
		->and(at($conditions, 2, 1, 0))->toBe('>=')
		->and(asString(at($conditions, 2, 1, 1)))->toContain('FROM `hub_references` `hubReferences`')
		->and(asString(at($conditions, 2, 1, 1)))->toContain('FROM `relations` `hubRelations`')
		->and(at($conditions, 2, 1, 2))->toBe(2)
		->and(at($conditions, 2, 2))->toBe([]);
})->with([
	'referenced' => [EntryService::USAGE_REFERENCED, false],
	'unreferenced' => [EntryService::USAGE_UNREFERENCED, true],
]);

it('ignores unknown usage values', function (): void {
	expect(conditions($this->hub()->entries->getQuery(entryFilter(['usage' => 'any']))))->toHaveCount(1);
});

it('narrows the entries by the date of the last edit and staleness', function (): void {
	$edited = $this->hub()->edits->lastEditedSql();
	$updated = conditions($this->hub()->entries->getQuery(entryFilter(['updatedFrom' => new DateTime('2024-01-01')])));
	$before = new DateTime('-6 months -1 second');
	$stale = conditions($this->hub()->entries->getQuery(entryFilter(['staleMonths' => 6])));
	$after = new DateTime('-6 months');
	$both = conditions($this->hub()->entries->getQuery(entryFilter(['updatedTo' => new DateTime('2024-01-01'), 'staleMonths' => 6])));

	expect($updated[1])->toBe(['>=', $edited, '2024-01-01 00:00:00'])
		->and(at($stale, 1, 0))->toBe('<')
		->and(at($stale, 1, 1))->toBe($edited)
		->and(new DateTime(asString(at($stale, 1, 2)), new DateTimeZone('UTC')))->toBeGreaterThanOrEqual((clone $before)->setTime((int) $before->format('H'), (int) $before->format('i'), (int) $before->format('s') - 1))->toBeLessThanOrEqual($after)
		->and(at($both, 1))->toBe(['and', ['<', $edited, '2024-01-02 00:00:00'], ['<', $edited, at($stale, 1, 2)]]);
});

it('narrows the entries to the ones with drafts', function (): void {
	$conditions = conditions($this->hub()->entries->getQuery(entryFilter(['drafts' => true])));

	[$and, $inPart, $existsPart] = parts($conditions[1]);
	[$in, $column, $drafted] = parts($inPart);
	[$exists, $drafts] = parts($existsPart);

	// Only the entries in the drafts table are looked up in the elements, which hold the revisions of all entries too.
	expect($and)->toBe('and')
		->and([$in, $column])->toBe(['in', 'elements.id'])
		->and(rawSql($drafted))->toContain('FROM `drafts` `hubDrafted`')
		->and(rawSql($drafted))->toContain('`hubDrafted`.`provisional`=FALSE')
		->and($exists)->toBe('exists')
		->and(rawSql($drafts))->toContain('`hubDraftsInfo`.`provisional`=FALSE');
});

it('narrows the entries to the ones of the user viewing them', function (): void {
	mocked(Craft::$app->getUser())->shouldReceive('getId')->andReturn(7);

	$query = $this->hub()->entries->getQuery(entryFilter(['authors' => ['me', 3], 'editors' => ['me'], 'myDrafts' => true]));
	[$edited, $drafts] = array_slice(conditions($query), 1);

	expect(at($query, 'authorId'))->toBe([7, 3])
		->and(rawSql(at($edited, 2)))->toContain('`hubEditors`.`userId`=7')
		->and(rawSql(at($drafts, 2)))->toContain('`hubUserDrafts`.`creatorId`=7');
});

it('narrows the entries of `me` to none without a user', function (): void {
	expect($this->hub()->entries->getQuery(entryFilter(['authors' => ['me']]))->authorId)->toBe([0]);
});

it('sorts the entries', function (string $sort, string $column): void {
	$order = $this->hub()->entries->getQuery(new EntryFilter(), $sort, SORT_ASC)->orderBy;

	expect(array_values(listOf($order)))->toBe([SORT_ASC, SORT_ASC])
		->and(array_key_first(listOf($order)))->toContain($column);
})->with([
	'title' => [EntryService::SORT_TITLE, 'elements_sites.title'],
	'origin section' => [EntryService::SORT_ORIGIN, '[[hubSections.name]]'],
	'origin field' => [EntryService::SORT_ORIGIN, '[[hubFields.name]]'],
	'type' => [EntryService::SORT_TYPE, '[[hubEntryTypes.name]]'],
	'references' => [EntryService::SORT_REFERENCES, 'COUNT(*)'],
	'posted' => [EntryService::SORT_POSTED, 'entries.postDate'],
	'updated' => [EntryService::SORT_UPDATED, 'elements.dateUpdated'],
	'unknown' => ['unknown', 'elements.dateUpdated'],
]);

it('lists the sort columns with their default direction', function (): void {
	expect($this->hub()->entries->getSortColumns())->toBe([
		'title' => SORT_ASC,
		'origin' => SORT_ASC,
		'type' => SORT_ASC,
		'references' => SORT_DESC,
		'posted' => SORT_DESC,
		'updated' => SORT_DESC,
	])->and($this->hub()->entries->getStatuses())->toBe(['live', 'pending', 'expired', 'disabled']);
});

it('lists the authors of entries, most entries first', function (): void {
	$this->db()->respond('FROM {{%entries_authors}} {{entries_authors}}', [
		['authorId' => '3', 'count' => '5'],
		['authorId' => '4', 'count' => '2'],
		// Users that no longer exist are left out.
		['authorId' => '5', 'count' => '1'],
	]);

	$author = new User(['id' => 3, 'username' => 'author']);
	$editor = new User(['id' => 4, 'username' => 'editor']);
	ElementQueries::respond(User::class, fn(): array => [3 => $author, 4 => $editor]);

	expect($this->hub()->entries->getAuthors(1, 10))->toBe([3 => ['user' => $author, 'count' => 5], 4 => ['user' => $editor, 'count' => 2]])
		->and($this->db()->queriesMatching('{{%entries_authors}}')[0])->toContain('`entries`.`sectionId`=1')
		->and($this->db()->queriesMatching('{{%entries_authors}}')[0])->toContain('`entries`.`typeId`=10')
		->and(ElementQueries::executed(User::class)[0]->id)->toBe([3, 4, 5]);
});

it('lists no authors when no entries have any', function (): void {
	expect($this->hub()->entries->getAuthors())->toBe([])
		->and($this->db()->queriesMatching('{{%entries_authors}}')[0])->not->toContain('`entries`.`sectionId`')
		->and(ElementQueries::executed(User::class))->toBe([]);
});

it('finds a section entry on the selected sites, preferring the current site', function (): void {
	$entry = new Entry(['id' => 1, 'siteId' => 2]);
	ElementQueries::respond(Entry::class, [$entry]);
	$this->request([FilterParams::SITE_PARAM => 'de']);

	expect($this->hub()->entries->getEntry(1))->toBe($entry);

	$query = ElementQueries::executed(Entry::class)[0];

	expect($query->id)->toBe(1)
		->and($query->siteId)->toBe([2])
		->and($query->preferSites)->toBe([2, 1])
		->and($query->status)->toBeNull()
		->and($this->hub()->entries->getEntry(2))->toBe($entry);
});

it('lists the authors of the entries of a field', function (): void {
	$this->hub()->entries->getAuthors(fieldId: 2);

	expect($this->db()->queriesMatching('{{%entries_authors}}')[0])->toContain('`entries`.`fieldId`=2');
});

it('finds nested entries too', function (): void {
	$this->hub()->entries->getEntry(1);

	expect(ElementQueries::executed(Entry::class)[0]->where)->toBeNull();
});

it('finds no entry when none matches', function (): void {
	expect($this->hub()->entries->getEntry(1))->toBeNull();
});

it('finds the entry on each counted site', function (): void {
	$variants = [new Entry(['id' => 1, 'siteId' => 1]), new Entry(['id' => 1, 'siteId' => 2])];
	ElementQueries::respond(Entry::class, $variants);

	expect($this->hub()->entries->getSiteVariantsQuery(new Entry(['id' => 1]))->all())->toBe($variants)
		->and(ElementQueries::executed(Entry::class)[0]->siteId)->toBe([1, 2])
		->and(ElementQueries::executed(Entry::class)[0]->orderBy)->toBe(['elements_sites.siteId' => SORT_ASC]);
});

it('queries the revisions of an entry on its site, newest first', function (): void {
	$query = $this->hub()->entries->getRevisionsQuery(new Entry(['id' => 1, 'siteId' => 2]));

	expect($query->revisionOf)->toBe(1)
		->and($query->siteId)->toBe(2)
		->and($query->status)->toBeNull()
		->and($query->orderBy)->toBe(['num' => SORT_DESC])
		->and($query->with)->toBe(['revisionCreator']);
});

it('finds the saved drafts of an entry on its site, last updated first', function (): void {
	$drafts = [new Entry(['id' => 3, 'siteId' => 2])];
	ElementQueries::respond(Entry::class, $drafts);

	expect($this->hub()->entries->getDraftsQuery(new Entry(['id' => 1, 'siteId' => 2]))->all())->toBe($drafts);

	$query = ElementQueries::executed(Entry::class)[0];

	expect($query->draftOf)->toBe(1)
		->and($query->provisionalDrafts)->toBeFalse()
		->and($query->siteId)->toBe(2)
		->and($query->status)->toBeNull()
		->and($query->orderBy)->toBe(['dateUpdated' => SORT_DESC])
		->and($query->with)->toBe(['draftCreator']);
});

it('lets users change the authors of entries without authors, of their own, or in sections they may change peer authors in', function (): void {
	Craft::$app->edition = CmsEdition::Pro;
	$this->section(5, 'blog', entryTypes: [$this->entryType(50, 'post')]);
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 5, 'username' => 'editor']));
	$peers = new ArrayObject(['on' => false]);
	mocked(Craft::$app->getUserPermissions())->shouldReceive('doesUserHavePermission')->andReturnUsing(fn(int $id, string $permission): bool => $permission === 'changeAuthorForPeerEntries:section-blog' && ($peers['on'] ?? false) === true);
	$entry = fn(array $authorIds): Entry => new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 5, 'typeId' => 50, 'authorIds' => $authorIds]);
	$service = $this->hub()->entries;

	expect($service->canChangeAuthors($entry([])))->toBeTrue()
		->and($service->canChangeAuthors($entry([5])))->toBeTrue()
		->and($service->canChangeAuthors($entry([6])))->toBeFalse();

	$peers['on'] = true;
	expect($service->canChangeAuthors($entry([6])))->toBeTrue();
});

it('offers the active users who may author the entries of their sections as their authors, by name', function (): void {
	$this->section(5, 'blog', entryTypes: [$this->entryType(50, 'post')]);
	ElementQueries::respond(User::class, [new User(['id' => 3, 'username' => 'zed']), new User(['id' => 4, 'username' => 'amy'])]);

	$authors = $this->hub()->entries->getAuthorCandidates([new Entry(['id' => 1, 'sectionId' => 5, 'typeId' => 50])]);

	expect(array_map(fn(User $user): ?int => $user->id, $authors))->toBe([4, 3])
		->and(at(ElementQueries::executed(User::class)[0], 'can'))->toBe('viewEntries:section-blog')
		->and($this->hub()->entries->getAuthorCandidates([new Entry(['id' => 1])]))->toBe([]);
});
