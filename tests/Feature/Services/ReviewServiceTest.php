<?php

use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ReviewService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->site(2, 'de');
	$this->review = new ReviewService();
});


/**
 * Answers the queries for nested entries owned by the given elements.
 *
 * @param array<int,int[]> $owned owner ID => IDs of the elements it owns
 */
function ownedEntries(array $owned): Closure
{
	return function (string $sql) use ($owned) {
		$rows = [];

		foreach ($owned as $ownerId => $ids) {
			if (preg_match('/`owners`\.`ownerId`(=| IN \([^)]*\b)' . $ownerId . '\b/', $sql)) {
				foreach ($ids as $id) {
					$rows[] = ['ownerId' => $ownerId, 'elementId' => $id];
				}
			}
		}

		return $rows;
	};
}

it('counts unreferenced entries for all sections and entry types at once', function () {
	$this->db()->respondToCounts(fn (EntryQuery $query) => [
		['sectionId' => 1, 'typeId' => 10, 'total' => 3],
		['sectionId' => 1, 'typeId' => 11, 'total' => 1],
		['sectionId' => 2, 'typeId' => 10, 'total' => 2],
	]);

	expect($this->review->countUnreferenced())->toBe(6)
		->and($this->review->countUnreferenced(1))->toBe(4)
		->and($this->review->countUnreferenced(typeId: 10))->toBe(5)
		->and($this->review->countUnreferenced(2, 11))->toBe(0)
		->and($this->db()->counts())->toHaveCount(1)
		->and($this->db()->counts()[0])->toContain('GROUP BY `sectionId`, `typeId`')
		->and($this->db()->elementQueryIn($this->db()->counts()[0])->where)->toEqual($this->review->getUnreferencedQuery()->where);
});

it('counts stale entries by the number of months', function () {
	$this->db()->respondToCounts(fn (EntryQuery $query) => [['sectionId' => 1, 'typeId' => 10, 'total' => str_contains($query->dateUpdated, (new DateTime('-6 months'))->format('Y-m-d')) ? 4 : 1]]);

	expect($this->review->countStale(6))->toBe(4)
		->and($this->review->countStale(12, 1, 10))->toBe(1)
		->and($this->review->countStale(6, 1))->toBe(4)
		->and($this->db()->counts())->toHaveCount(2);
});

it('counts large files and images by volume', function () {
	$this->db()->respondToCounts(fn (AssetQuery $query) => $query->size !== null
		? [['volumeId' => 1, 'total' => 2], ['volumeId' => 2, 'total' => 1]]
		: [['volumeId' => 2, 'total' => 5]]);

	expect($this->review->countLargeFiles())->toBe(3)
		->and($this->review->countLargeFiles(1))->toBe(2)
		->and($this->review->countLargeImages())->toBe(5)
		->and($this->review->countLargeImages(1))->toBe(0);
});

it('counts once per site scope', function () {
	$this->db()->respondToCounts(fn (AssetQuery $query) => [['volumeId' => 1, 'total' => 2]]);

	$this->review->countLargeFiles();
	$this->request([Hub::SITE_PARAM => 'de']);
	$this->createPlugin();
	$this->review->countLargeFiles();
	$this->review->countLargeFiles(1);

	expect($this->db()->counts())->toHaveCount(2)
		->and($this->db()->elementQueryIn($this->db()->counts()[1])->siteId)->toBe([2]);
});

it('queries section entries on the counted sites, least recently updated first', function () {
	$query = $this->review->getUnreferencedQuery();

	expect($query)->toBeInstanceOf(EntryQuery::class)
		->and($query->siteId)->toBe([1, 2])
		->and($query->unique)->toBeTrue()
		->and($query->status)->toBeNull()
		->and($query->orderBy)->toBe(['elements.dateUpdated' => SORT_ASC])
		->and($query->sectionId)->toBeNull()
		->and($query->where[1][0])->toBe('or')
		->and($query->where[1][1])->toBe(['entries.primaryOwnerId' => null])
		// Nested entries are reached through their owner, so they are never unreferenced.
		->and($query->where[2])->toBe(['not', ['entries.sectionId' => null]]);
});

it('queries unreferenced entries of a section and entry type without URLs', function () {
	$query = $this->review->getUnreferencedQuery(1, 10);
	[, , , $references, $withUrls] = $query->where;

	expect($query->sectionId)->toBe(1)
		->and($query->typeId)->toBe(10)
		->and($references[0])->toBe('not exists')
		->and(rawSql($references[1]))->toContain('`hubRelations`.`targetId` = `elements`.`id`')
		->and($withUrls[0])->toBe('not exists')
		->and(rawSql($withUrls[1]))->toContain('`hubSectionsSites`.`hasUrls`=TRUE');
});

it('queries stale entries', function () {
	$query = $this->review->getStaleQuery(3, typeId: 11);

	expect($query->dateUpdated)->toBe('< ' . (new DateTime('-3 months'))->format(DateTime::ATOM))
		->and($query->typeId)->toBe(11)
		// Nested entries can be stale too, apart from the copies owned by drafts and revisions.
		->and($query->where[0])->toBe('or')
		->and($query->where[1])->toBe(['entries.primaryOwnerId' => null]);
});

it('queries assets to review', function () {
	$unused = $this->review->getUnusedAssetsQuery(1);
	$missingAlt = $this->review->getMissingAltQuery();
	$large = $this->review->getLargeFilesQuery(2);
	$images = $this->review->getLargeImagesQuery();

	expect($unused->volumeId)->toBe(1)
		->and($unused->siteId)->toBe([1, 2])
		->and($unused->orderBy)->toBe(['assets.size' => SORT_DESC])
		->and($unused->where[0])->toBe('not exists')
		->and($missingAlt->volumeId)->toBeNull()
		->and($missingAlt->orderBy)->toBe(['elements.dateCreated' => SORT_DESC])
		->and($missingAlt->where[1])->toBe(['assets.kind' => 'image'])
		->and($large->size)->toBe('> ' . 5 * 1024 * 1024)
		->and($large->volumeId)->toBe(2)
		->and($images->where)->toBe($this->review->largeImageCondition());
});

it('matches images without alternative text on the counted sites', function () {
	$this->request([Hub::SITE_PARAM => 'de']);
	$condition = $this->review->missingAltCondition();

	expect(array_slice($condition, 0, 3))->toBe(['and', ['assets.kind' => 'image'], ['or', ['assets.alt' => null], ['assets.alt' => '']]])
		->and($condition[3][0])->toBe('not exists')
		->and(rawSql($condition[3][1]))->toContain('`hubAssetsSites`.`siteId`=2');
});

it('matches images larger than the threshold', function () {
	$this->hub()->getSettings()->largeImageThresholdPx = 2000;

	expect($this->review->largeImageCondition())->toBe([
		'and',
		['assets.kind' => 'image'],
		['or', ['>', 'assets.width', 2000], ['>', 'assets.height', 2000]],
	]);
});

it('builds subqueries for the queried elements', function () {
	expect(rawSql($this->review->referencesQuery()))->toContain('`hubSources`.`draftId` IS NULL')
		->and(rawSql($this->review->draftsQuery()))->toContain('`hubDraftsInfo`.`provisional`=FALSE')
		->and(rawSql($this->review->draftsQuery()))->toContain('`hubDraftsSites`.`siteId` = `elements_sites`.`siteId`')
		->and(rawSql($this->review->failedTransformsQuery()))->toContain('`hubTransforms`.`error`=TRUE');
});

it('counts the counted sites of each image with and without alternative text in a single pass', function () {
	$sql = rawSql($this->review->altSitesQuery());

	expect($sql)->toContain("SUM(CASE WHEN TRIM(COALESCE(`hubAssetsSites`.`alt`, '')) <> '' THEN 1 ELSE 0 END) AS `withAlt`")
		->and($sql)->toContain("SUM(CASE WHEN TRIM(COALESCE(`hubAssetsSites`.`alt`, '')) = '' THEN 1 ELSE 0 END) AS `withoutAlt`")
		->and($sql)->toContain("`hubImages`.`kind`='image'")
		->and($sql)->toContain('`hubElementsSites`.`siteId` IN (1, 2)')
		->and($sql)->toContain('GROUP BY `hubElementsSites`.`elementId`');
});

it('matches images without alternative text on all or some of their sites by the counts joined', function () {
	expect($this->review->noAltCondition())->toBe(['and', ['assets.kind' => 'image'], 'COALESCE([[hubAlt.withAlt]], 0) = 0'])
		->and($this->review->partialAltCondition())->toBe(['and', ['assets.kind' => 'image'], ['>', 'hubAlt.withAlt', 0], ['>', 'hubAlt.withoutAlt', 0]]);
});

it('finds images without alternative text used by elements and the entries nested in them', function () {
	$entry = new Entry(['id' => 1, 'siteId' => 1]);
	$other = new Entry(['id' => 2, 'siteId' => 1]);
	$draft = new Entry(['id' => 3, 'siteId' => 1, 'canonicalId' => 1]);

	// Entry 1 owns block 5, which owns block 6.
	$this->db()->respond('FROM {{%elements_owners}} {{owners}}', ownedEntries([1 => [5], 5 => [6]]));
	$this->db()->respond('FROM {{%relations}}', [
		['sourceId' => 6, 'targetId' => 100],
		['sourceId' => 1, 'targetId' => 101],
		['sourceId' => 2, 'targetId' => 102],
	]);

	$images = [new Asset(['id' => 100, 'siteId' => 1]), new Asset(['id' => 102, 'siteId' => 1])];
	ElementQueries::respond(Asset::class, $images);

	$related = $this->review->getReferencedMissingAlt([$entry, $other, $draft]);
	$query = ElementQueries::executed(Asset::class)[0];

	expect(array_map(fn (array $assets) => array_map(fn (Asset $asset) => $asset->id, $assets), $related))->toBe([1 => [100], 2 => [102], 3 => [100]])
		->and($query->id)->toBe([100, 101, 102])
		->and($query->where)->toEqual($this->review->missingAltCondition());
});

it('finds no images for elements relating to nothing', function () {
	expect($this->review->getReferencedMissingAlt([new Entry(['id' => 1, 'siteId' => 1])]))->toBe([1 => []])
		->and(ElementQueries::executed(Asset::class))->toBe([])
		->and($this->review->getReferencedMissingAlt([]))->toBe([]);
});

it('finds unavailable entries related to by elements, on their own sites', function () {
	$en = new Entry(['id' => 1, 'siteId' => 1]);
	$de = new Entry(['id' => 2, 'siteId' => 2]);

	$this->db()->respond('FROM {{%relations}}', fn (string $sql) => str_contains($sql, '`sourceSiteId`=2')
		? [['sourceId' => 2, 'targetId' => 20]]
		: [['sourceId' => 1, 'targetId' => 10], ['sourceId' => 1, 'targetId' => 11]]);

	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->siteId === 2
		? [new Entry(['id' => 20, 'siteId' => 2])]
		: [new Entry(['id' => 11, 'siteId' => 1])]);

	$related = $this->review->getReferencedUnavailable([$en, $de]);

	expect(array_map(fn (array $entries) => array_map(fn (Entry $entry) => $entry->id, $entries), $related))->toBe([1 => [11], 2 => [20]])
		->and(ElementQueries::executed(Entry::class)[0]->status)->toBe(['disabled', 'pending', 'expired']);
});

it('finds the elements relating to images without alternative text, along with their owners, in a query of their own', function () {
	$sql = preg_replace('/\s+/', ' ', rawSql($this->review->relatingToMissingAltQuery()));

	// The images are matched like an asset query matches them, without sorting them or a row for each site.
	expect($sql)->toStartWith('WITH RECURSIVE `hubWithOwners` AS (SELECT `hubRelations`.`sourceId` AS `id`')
		->and($sql)->toContain('`hubRelations`.`targetId` IN (SELECT `assets`.`id`')
		->and($sql)->toContain('`hubRelations`.`sourceSiteId` IN (1, 2)')
		->and($sql)->toContain('`elements`.`archived`=FALSE')
		->and($sql)->toContain('`hubSites`.`siteId` IN (1, 2)')
		->and($sql)->toContain("(`assets`.`kind`='image') AND ((`assets`.`alt` IS NULL) OR (`assets`.`alt`=''))")
		->and($sql)->not->toContain('ORDER BY')
		->and($sql)->not->toContain('INNER JOIN `elements_sites`')
		// Each level of owners is joined to the elements found so far, as long as the elements are live.
		->and($sql)->toContain(' UNION SELECT `owners`.`ownerId` AS `id` FROM `hubWithOwners` `hubOwned` INNER JOIN `elements_owners` `owners` ON `owners`.`elementId` = `hubOwned`.`id`')
		->and($sql)->toContain('`elements`.`revisionId` IS NULL)) SELECT `id` FROM `hubWithOwners`')
		->and($sql)->not->toContain('UNION (');

	expect($this->db()->queries)->toBe([]);
});

it('finds the elements relating to unavailable entries on each site, along with their owners, in a query of their own', function () {
	$sql = preg_replace('/\s+/', ' ', rawSql($this->review->relatingToUnavailableQuery()));

	// The entries are unavailable on the site the relation is made on, or relations aren't translatable.
	expect($sql)->toStartWith('WITH RECURSIVE `hubWithOwners` AS (SELECT `hubRelations`.`sourceId` AS `id`, `hubTargets`.`siteId` AS `siteId`')
		->and($sql)->toContain('`hubRelations`.`sourceSiteId` IS NULL) OR (`hubRelations`.`sourceSiteId` = `hubTargets`.`siteId`')
		->and($sql)->toContain('`elements_sites`.`siteId` IN (1, 2)')
		->and($sql)->toContain('`elements`.`revisionId` IS NULL')
		// Disabled, pending or expired, as Craft matches the statuses.
		->and($sql)->toContain('(`elements`.`enabled`=FALSE) OR (`elements_sites`.`enabled`=FALSE)')
		->and($sql)->toContain('`entries`.`postDate` >')
		->and($sql)->toContain('`entries`.`expiryDate` <=')
		->and($sql)->not->toContain('structureelements')
		// Owners relate to the entries on the sites the elements they own do.
		->and($sql)->toContain(' UNION SELECT `owners`.`ownerId` AS `id`, `hubOwned`.`siteId` FROM `hubWithOwners` `hubOwned`')
		->and($sql)->toContain('SELECT `id`, `siteId` FROM `hubWithOwners`');
});

it('finds the entries with drafts in the drafts alone', function () {
	expect(preg_replace('/\s+/', ' ', rawSql($this->review->draftedQuery())))->toBe('SELECT DISTINCT `hubDrafted`.`canonicalId` AS `canonicalId` FROM `drafts` `hubDrafted` WHERE (`hubDrafted`.`provisional`=FALSE) AND (NOT (`hubDrafted`.`canonicalId` IS NULL))');
});

it('finds the files with generic filenames', function () {
	$this->db()->respond('FROM {{%assets}} {{assets}}', [
		['id' => 1, 'filename' => 'IMG_1234.jpg'],
		['id' => 2, 'filename' => 'team-photo.jpg'],
		['id' => 3, 'filename' => 'Screenshot 2024-01-01 at 10.00.00.png'],
	]);

	expect($this->review->getGenericFilenameIds())->toBe([1, 3])
		->and($this->review->getGenericFilenameIds())->toBe([1, 3])
		->and($this->db()->queriesMatching('FROM {{%assets}} {{assets}}'))->toHaveCount(1)
		// Trashed files are classified too, so restoring one needn't classify the files again.
		->and($this->db()->queriesMatching('FROM {{%assets}} {{assets}}')[0])->not->toContain('dateDeleted');
});

it('classifies the filenames again only once the files change', function () {
	$state = ['count' => 3, 'lastId' => 3, 'lastUpdate' => '2026-10-01 10:00:00'];
	$this->db()->respond('FROM {{%assets}} {{hubFiles}}', function () use (&$state) {
		return [$state];
	});
	$this->db()->respond('FROM {{%assets}} {{assets}}', [['id' => 1, 'filename' => 'IMG_1234.jpg']]);
	$fresh = fn () => (new ReviewService())->getGenericFilenameIds();

	$fresh();
	$fresh();
	$state['lastUpdate'] = '2026-10-02 10:00:00';
	$fresh();

	expect($fresh())->toBe([1])
		->and($this->db()->queriesMatching('FROM {{%assets}} {{assets}}'))->toHaveCount(2)
		->and($this->db()->queriesMatching('MAX(`hubFiles`.`dateUpdated`)'))->toHaveCount(4);
});

it('reads the filenames a thousand at a time', function () {
	// A full batch of descriptive filenames, followed by a generic one.
	$this->db()->respond('FROM {{%assets}} {{assets}}', fn (string $sql) => str_contains($sql, '`assets`.`id` > 0')
		? array_map(fn (int $id) => ['id' => $id, 'filename' => "team-$id.jpg"], range(1, 1000))
		: [['id' => 1001, 'filename' => 'IMG_1001.jpg']]);

	expect($this->review->getGenericFilenameIds())->toBe([1001])
		->and($this->db()->queriesMatching('FROM {{%assets}} {{assets}}'))->toHaveCount(2)
		->and($this->db()->queriesMatching('FROM {{%assets}} {{assets}}')[1])->toContain('`assets`.`id` > 1000')
		->and($this->db()->queriesMatching('FROM {{%assets}} {{assets}}')[1])->toContain("ORDER BY `assets`.`id`\nLIMIT 1000");
});

it('counts the drafts of entries on their own sites', function () {
	$en = new Entry(['id' => 1, 'siteId' => 1]);
	$draft = new Entry(['id' => 3, 'siteId' => 1, 'canonicalId' => 2]);
	$de = new Entry(['id' => 4, 'siteId' => 2]);

	ElementQueries::respond(Entry::class, fn (EntryQuery $query) => $query->siteId === 1
		? [['canonicalId' => 1, 'total' => 2], ['canonicalId' => 2, 'total' => 1]]
		: []);

	$counts = $this->review->getDraftCounts([$en, $draft, $de]);
	$query = ElementQueries::executed(Entry::class)[0];

	expect($counts)->toBe([1 => 2, 3 => 1, 4 => 0])
		->and($query->draftOf)->toBe([1, 2])
		->and($query->groupBy)->toBe(['elements.canonicalId']);
});
