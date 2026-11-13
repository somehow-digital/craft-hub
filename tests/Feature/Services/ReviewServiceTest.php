<?php

declare(strict_types=1);

use craft\elements\db\EntryQuery;
use SomehowDigital\Craft\Hub\models\FilterParams;

beforeEach(function (): void {
	$this->site(2, 'de');
});

it('matches unreferenced elements with a condition for each way to reference them', function (): void {
	$unreferenced = $this->hub()->review->unreferencedCondition();

	expect(array_column(array_slice($unreferenced, 1), 0))->toBe(['not exists', 'not exists', 'not in'])
		->and(array_column(array_slice($this->hub()->review->unreferencedCondition(false), 1), 0))->toBe(['not exists', 'not exists'])
		->and(array_column(array_slice($this->hub()->review->referencedCondition(), 1), 0))->toBe(['exists', 'exists', 'in'])
		->and(conditionSql($unreferenced))->toContain('NOT IN (SELECT `hubUsers`.`photoId`');
});

it('leaves user photos out of the references of elements that cannot be photos', function (): void {
	expect($this->hub()->review->referenceCountSql())->toContain('`hubUsers`')
		->and($this->hub()->review->referenceCountSql(false))->not->toContain('`hubUsers`');
});

it('counts stale entries by the number of months', function (): void {
	$this->db()->respondToCounts(fn(EntryQuery $query): array => [['sectionId' => 1, 'typeId' => 10, 'total' => str_contains(conditionSql($query->where), (new DateTime('-6 months', new DateTimeZone('UTC')))->format('Y-m-d')) ? 4 : 1]]);

	expect($this->hub()->review->countStale(6))->toBe(4)
		->and($this->hub()->review->countStale(12, 1, 10))->toBe(1)
		->and($this->hub()->review->countStale(6, 1))->toBe(4)
		->and($this->db()->counts())->toHaveCount(2);
});

it('queries stale entries by when a person last edited them, least recently edited first', function (): void {
	$query = $this->hub()->review->getStaleQuery(3, typeId: 11);
	$edited = $this->hub()->edits->lastEditedSql();

	expect($query->dateUpdated)->toBeNull()
		->and(lastOf($query->where))->toBe(['<', $edited, (new DateTime('-3 months', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')])
		->and($query->orderBy)->toBe([$edited => SORT_ASC, 'elements.id' => SORT_ASC])
		->and($edited)->toContain('FROM `hub_edits` `hubEdits`')
		->and($edited)->toEndWith(', [[elements.dateUpdated]])')
		->and($query->typeId)->toBe(11)
		// Nested entries can be stale too, apart from the copies owned by drafts and revisions.
		->and(at(conditions($query), 0, 0))->toBe('or')
		->and(at(conditions($query), 0, 1))->toBe(['entries.primaryOwnerId' => null]);
});

it('queries stale entries of a nested entry field', function (): void {
	$query = $this->hub()->review->getStaleQuery(3, fieldId: 5);

	expect($query->fieldId)->toBe(5)
		->and($query->sectionId)->toBeNull()
		->and($query->typeId)->toBeNull();
});

it('matches images without alternative text on the counted sites', function (): void {
	$this->request([FilterParams::SITE_PARAM => 'de']);
	$condition = $this->hub()->review->missingAltCondition();

	expect(array_slice($condition, 0, 3))->toBe(['and', ['assets.kind' => 'image'], ['or', ['assets.alt' => null], ['assets.alt' => '']]])
		->and(at($condition, 3, 0))->toBe('not exists')
		->and(rawSql(at($condition, 3, 1)))->toContain('`hubAssetsSites`.`siteId`=2');
});

it('matches images larger than the threshold', function (): void {
	$this->db()->respond('FROM {{%hub_filters}}', [thresholdRow('assets', 'oversized', 2000)]);

	expect($this->hub()->review->largeImageCondition())->toBe([
		'and',
		['assets.kind' => 'image'],
		['or', ['>', 'assets.width', 2000], ['>', 'assets.height', 2000]],
	]);
});

it('builds subqueries for the queried elements', function (): void {
	expect(rawSql($this->hub()->review->referencesQuery()))->toContain('`hubSources`.`draftId` IS NULL')
		->and(rawSql($this->hub()->review->draftsQuery()))->toContain('`hubDraftsInfo`.`provisional`=FALSE')
		->and(rawSql($this->hub()->review->draftsQuery()))->toContain('`hubDraftsSites`.`siteId` = `elements_sites`.`siteId`');
});

it('finds the entries with drafts of a user, including their unsaved changes', function (): void {
	[$in, $column, $drafts] = $this->hub()->review->userDraftsCondition(7);
	$sql = rawSql($drafts);

	expect([$in, $column])->toBe(['in', 'elements.id'])
		->and($sql)->toContain('`hubUserDrafts`.`creatorId`=7')
		->and($sql)->toContain('`hubUserDraftsElements`.`dateDeleted` IS NULL')
		->and($sql)->not->toContain('provisional');
});

it('finds the entries with drafts in the drafts alone', function (): void {
	expect(preg_replace('/\s+/', ' ', rawSql($this->hub()->review->draftedQuery())))->toBe('SELECT DISTINCT `hubDrafted`.`canonicalId` AS `canonicalId` FROM `drafts` `hubDrafted` WHERE (`hubDrafted`.`provisional`=FALSE) AND (NOT (`hubDrafted`.`canonicalId` IS NULL))');
});
