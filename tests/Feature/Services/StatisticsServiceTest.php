<?php

use SomehowDigital\Craft\Hub\services\StatisticsService;

beforeEach(function () {
	$this->statistics = new StatisticsService();

	$this->db()->respondToEntryRows([
		['sectionId' => '1', 'typeId' => '10', 'fieldId' => null, 'total' => '3', 'enabled' => '2', 'pending' => '1', 'expired' => '0', 'drafts' => '1', 'lastUpdated' => '2024-01-01 10:00:00'],
		['sectionId' => '1', 'typeId' => '11', 'fieldId' => null, 'total' => '2', 'enabled' => '2', 'pending' => '0', 'expired' => '0', 'drafts' => '0', 'lastUpdated' => '2024-03-01 10:00:00'],
		['sectionId' => null, 'typeId' => '10', 'fieldId' => '5', 'total' => '4', 'enabled' => '4', 'pending' => '0', 'expired' => '0', 'drafts' => '0', 'lastUpdated' => null],
	]);

	$this->db()->respond('FROM {{%relations}} {{relations}}', [
		['fieldId' => '5', 'sectionId' => '1', 'volumeId' => null, 'references' => '6', 'sources' => '3', 'targets' => '2'],
		['fieldId' => '5', 'sectionId' => '2', 'volumeId' => null, 'references' => '1', 'sources' => '1', 'targets' => '1'],
		['fieldId' => '6', 'sectionId' => null, 'volumeId' => '3', 'references' => '4', 'sources' => '2', 'targets' => '4'],
	]);

	$this->db()->respond('FROM {{%assets}} {{assets}}', [
		['volumeId' => '3', 'kind' => 'image', 'total' => '5', 'used' => '3', 'images' => '5', 'missingAlt' => '2', 'size' => '5000', 'unusedSize' => '2000', 'lastUploaded' => '2024-02-01 00:00:00'],
		['volumeId' => '3', 'kind' => 'pdf', 'total' => '1', 'used' => '0', 'images' => '0', 'missingAlt' => '0', 'size' => '100', 'unusedSize' => '100', 'lastUploaded' => null],
		['volumeId' => '4', 'kind' => 'image', 'total' => '2', 'used' => '2', 'images' => '2', 'missingAlt' => '0', 'size' => '200', 'unusedSize' => '0', 'lastUploaded' => '2024-04-01 00:00:00'],
	]);
});

it('reads entry counts grouped by section, entry type and field', function () {
	$rows = $this->statistics->getEntryRows([1]);

	expect($rows)->toHaveCount(3)
		->and($rows[0])->toMatchArray(['sectionId' => 1, 'typeId' => 10, 'fieldId' => null])
		->and($rows[2])->toMatchArray(['sectionId' => null, 'typeId' => 10, 'fieldId' => 5])
		->and([$rows[0]['stats']->total, $rows[0]['stats']->enabled, $rows[0]['stats']->drafts])->toBe([3, 2, 1])
		->and([$rows[0]['stats']->pending, $rows[0]['stats']->getLive()])->toBe([1, 1])
		->and($rows[0]['stats']->lastUpdated->format('Y-m-d H:i'))->toBe('2024-01-01 10:00')
		->and($rows[2]['stats']->lastUpdated)->toBeNull();
});

it('counts entries on the given sites, leaving out deleted ones, revisions and copies owned by drafts', function () {
	$this->statistics->getEntryRows([2, 1]);

	$sql = $this->db()->queriesMatching('FROM {{%entries}} {{entries}}')[0];

	expect($sql)->toContain('`elements_sites`.`siteId` IN (2, 1)')
		->and($sql)->toContain('`elements`.`dateDeleted` IS NULL')
		->and($sql)->toContain('`elements`.`revisionId` IS NULL')
		->and($sql)->toContain('`owners`.`draftId` IS NULL')
		->and($sql)->toContain('`entries`.`postDate` >')
		->and($sql)->toContain('`entries`.`expiryDate` <=')
		->and($sql)->toContain('GROUP BY `entries`.`sectionId`, `entries`.`typeId`, `entries`.`fieldId`');
});

it('reads the rows once per set of sites, regardless of their order', function () {
	$this->statistics->getEntryRows([1, 2]);
	$this->statistics->getEntryRows([2, 1]);
	$this->statistics->getReferenceRows([1]);
	$this->statistics->getReferenceRows([1]);
	$this->statistics->getAssetRows([1]);
	$this->statistics->getAssetRows([1]);
	$this->statistics->getEntryRows([1]);

	// Section entries and nested entries are read separately.
	expect($this->db()->queriesMatching('FROM {{%entries}} {{entries}}'))->toHaveCount(4)
		->and($this->db()->queriesMatching('FROM {{%relations}} {{relations}}'))->toHaveCount(1)
		->and($this->db()->queriesMatching('FROM {{%assets}} {{assets}}'))->toHaveCount(1);
});

it('reads section entries and nested entries separately, only those summed', function () {
	$this->statistics->sumEntries([1], sectionId: 1);
	$this->statistics->sumEntries([1], sectionId: 1, typeId: 10);

	$sql = $this->db()->queriesMatching('FROM {{%entries}} {{entries}}');

	expect($sql)->toHaveCount(1)
		->and($sql[0])->toContain('NOT (`entries`.`sectionId` IS NULL)');

	$this->statistics->sumEntries([1], fieldId: 5);
	$sql = $this->db()->queriesMatching('FROM {{%entries}} {{entries}}');

	expect($sql)->toHaveCount(2)
		->and($sql[1])->toContain('`entries`.`sectionId` IS NULL')
		->and($sql[1])->not->toContain('NOT (`entries`.`sectionId` IS NULL)');

	// Entry types hold both, which are read by now.
	$this->statistics->sumEntries([1], typeId: 10);

	expect($this->db()->queriesMatching('FROM {{%entries}} {{entries}}'))->toHaveCount(2)
		->and($this->statistics->getEntryRows([1], nested: false))->toHaveCount(2)
		->and($this->statistics->getEntryRows([1], nested: true))->toHaveCount(1);
});

it('sums entries by any combination of section, entry type and field', function () {
	expect($this->statistics->sumEntries([1])->total)->toBe(9)
		->and($this->statistics->sumEntries([1], sectionId: 1)->total)->toBe(5)
		->and($this->statistics->sumEntries([1], typeId: 10)->total)->toBe(7)
		->and($this->statistics->sumEntries([1], 1, 10)->total)->toBe(3)
		->and($this->statistics->sumEntries([1], fieldId: 5)->total)->toBe(4)
		->and($this->statistics->sumEntries([1], 1, 10, 5)->total)->toBe(0)
		->and($this->statistics->sumEntries([1], sectionId: 1)->lastUpdated->format('Y-m-d'))->toBe('2024-03-01');
});

it('returns copies of the sums', function () {
	$this->statistics->sumEntries([1], sectionId: 1)->total = 100;
	$this->statistics->sumReferences([1], 5)->references = 100;
	$this->statistics->sumAssets([1], 3)->total = 100;

	expect($this->statistics->sumEntries([1], sectionId: 1)->total)->toBe(5)
		->and($this->statistics->sumReferences([1], 5)->references)->toBe(7)
		->and($this->statistics->sumAssets([1], 3)->total)->toBe(6);
});

it('reads reference counts grouped by field and target section or volume', function () {
	$rows = $this->statistics->getReferenceRows([1, 2]);
	$sql = $this->db()->queriesMatching('FROM {{%relations}} {{relations}}')[0];

	expect($rows[0])->toMatchArray(['fieldId' => 5, 'sectionId' => 1, 'volumeId' => null])
		->and($rows[2])->toMatchArray(['fieldId' => 6, 'sectionId' => null, 'volumeId' => 3])
		->and([$rows[0]['stats']->references, $rows[0]['stats']->sources, $rows[0]['stats']->targets])->toBe([6, 3, 2])
		->and($sql)->toContain('`relations`.`sourceSiteId` IN (1, 2)')
		->and($sql)->toContain('`sources`.`draftId` IS NULL')
		->and($sql)->toContain('`targetElements`.`dateDeleted` IS NULL');
});

it('sums references by any combination of field, section and volume', function () {
	expect($this->statistics->sumReferences([1])->references)->toBe(11)
		->and($this->statistics->sumReferences([1], 5)->references)->toBe(7)
		->and($this->statistics->sumReferences([1], 5, 2)->sources)->toBe(1)
		->and($this->statistics->sumReferences([1], volumeId: 3)->targets)->toBe(4)
		->and($this->statistics->sumReferences([1], 7)->references)->toBe(0);
});

it('reads asset counts grouped by volume and file kind', function () {
	$rows = $this->statistics->getAssetRows([1]);

	expect($rows[0])->toMatchArray(['volumeId' => 3, 'kind' => 'image'])
		->and([$rows[0]['stats']->total, $rows[0]['stats']->used, $rows[0]['stats']->images, $rows[0]['stats']->missingAlt, $rows[0]['stats']->size])->toBe([5, 3, 5, 2, 5000])
		->and($rows[0]['stats']->lastUploaded->format('Y-m-d'))->toBe('2024-02-01')
		->and($rows[1]['stats']->lastUploaded)->toBeNull();
});

it('counts assets on the given sites, with their references and alternative texts on those sites', function () {
	$this->statistics->getAssetRows([1, 2]);

	$sql = $this->db()->queriesMatching('FROM {{%assets}} {{assets}}')[0];

	expect($sql)->toContain('`hubRelations`.`sourceSiteId` IN (1, 2)')
		->and($sql)->toContain('`hubAssetsSites`.`siteId` IN (1, 2)')
		->and($sql)->toContain('`elements_sites`.`siteId` IN (1, 2)')
		->and($sql)->toContain("`assets`.`kind` = 'image'")
		->and($sql)->toContain('GROUP BY `assets`.`volumeId`, `assets`.`kind`');
});

it('sums assets by volume and file kind', function () {
	expect($this->statistics->sumAssets([1])->total)->toBe(8)
		->and($this->statistics->sumAssets([1], 3)->size)->toBe(5100)
		->and($this->statistics->sumAssets([1], 3)->unusedSize)->toBe(2100)
		->and($this->statistics->sumAssets([1], kind: 'image')->missingAlt)->toBe(2)
		->and($this->statistics->sumAssets([1], kind: 'image')->lastUploaded->format('Y-m-d'))->toBe('2024-04-01')
		->and($this->statistics->sumAssets([1], 4, 'pdf')->total)->toBe(0);
});

it('collects the entries of all sites for a snapshot, by the sets of sites they are on and enabled on', function () {
	$this->db()->respond('`hubEntries`', [
		['sectionId' => '1', 'typeId' => '10', 'fieldId' => null, 'sites' => '2,1', 'enabledSites' => null, 'total' => '3', 'enabled' => '0', 'pending' => '0', 'expired' => '0', 'drafts' => '1', 'lastUpdated' => ''],
	]);

	$rows = $this->statistics->collectEntryRows();
	$sql = $this->db()->queriesMatching('`hubEntries`')[0];

	expect($rows)->toBe([
		['sectionId' => 1, 'typeId' => 10, 'fieldId' => null, 'sites' => '1,2', 'enabledSites' => null, 'total' => 3, 'enabled' => 0, 'pending' => 0, 'expired' => 0, 'drafts' => 1, 'lastUpdated' => null],
	])
		->and($sql)->toContain("GROUP_CONCAT(`hubElementsSites`.`siteId` ORDER BY `hubElementsSites`.`siteId` SEPARATOR ',')")
		->and($sql)->toContain('`hubElementsSites`.`enabled`=TRUE')
		->and($sql)->toContain('`owners`.`draftId` IS NULL')
		->and($sql)->not->toContain('`elements_sites`.`siteId` IN')
		->and($sql)->toContain('GROUP BY `sectionId`, `typeId`, `fieldId`, `sites`, `enabledSites`');
});

it('collects the assets of all sites for a snapshot, used from the sites of their relations and reference tags alike', function () {
	$this->db()->respond('`hubAssets`', [
		['volumeId' => '3', 'kind' => 'image', 'sites' => '1,2', 'usedSites' => '2', 'taggedSites' => '1', 'altSites' => null, 'total' => '2', 'size' => '200', 'lastUploaded' => '2024-01-01 00:00:00'],
		['volumeId' => '3', 'kind' => 'image', 'sites' => '1,2', 'usedSites' => '1,2', 'taggedSites' => null, 'altSites' => null, 'total' => '1', 'size' => '50', 'lastUploaded' => '2024-03-01 00:00:00'],
		['volumeId' => '3', 'kind' => 'pdf', 'sites' => '1', 'usedSites' => null, 'taggedSites' => null, 'altSites' => '0', 'total' => '1', 'size' => '10', 'lastUploaded' => null],
	]);

	$rows = $this->statistics->collectAssetRows();
	$sql = $this->db()->queriesMatching('`hubAssets`')[0];

	expect($rows)->toBe([
		['volumeId' => 3, 'kind' => 'image', 'sites' => '1,2', 'usedSites' => '1,2', 'altSites' => null, 'total' => 3, 'size' => 250, 'lastUploaded' => '2024-03-01 00:00:00'],
		// Only images are kept apart by the sites of their alternative text.
		['volumeId' => 3, 'kind' => 'pdf', 'sites' => '1', 'usedSites' => null, 'altSites' => null, 'total' => 1, 'size' => 10, 'lastUploaded' => null],
	])
		->and($sql)->toContain('GROUP_CONCAT(DISTINCT COALESCE(`hubRelations`.`sourceSiteId`, 0)')
		->and($sql)->toContain('GROUP_CONCAT(DISTINCT `hubReferences`.`sourceSiteId`')
		->and($sql)->toContain("CASE WHEN COALESCE(`assets`.`alt`, '') <> '' THEN '0'")
		->and($sql)->not->toContain('`hubRelations`.`sourceSiteId` IN')
		->and($sql)->toContain('GROUP BY `volumeId`, `kind`, `sites`, `usedSites`, `taggedSites`, `altSites`');
});

it('collects the references of all sites for a snapshot, by the site of their source', function () {
	$this->db()->respond('`relations`.`sourceSiteId`', [
		['fieldId' => '5', 'sectionId' => '1', 'volumeId' => null, 'siteId' => '2', 'references' => '6', 'sources' => '3', 'targets' => '2'],
		['fieldId' => '6', 'sectionId' => null, 'volumeId' => '3', 'siteId' => null, 'references' => '4', 'sources' => '2', 'targets' => '4'],
	]);

	$rows = $this->statistics->collectReferenceRows();
	$sql = $this->db()->queriesMatching('FROM {{%relations}} {{relations}}')[0];

	expect($rows)->toBe([
		['fieldId' => 5, 'sectionId' => 1, 'volumeId' => null, 'siteId' => 2, 'references' => 6, 'sources' => 3, 'targets' => 2],
		['fieldId' => 6, 'sectionId' => null, 'volumeId' => 3, 'siteId' => null, 'references' => 4, 'sources' => 2, 'targets' => 4],
	])
		->and($sql)->not->toContain('`relations`.`sourceSiteId` IN')
		->and($sql)->toContain('GROUP BY `relations`.`fieldId`, `targets`.`sectionId`, `targetAssets`.`volumeId`, `relations`.`sourceSiteId`');
});

it('aggregates the sets of sites for snapshots on PostgreSQL', function () {
	$this->createApp('pgsql');
	(new StatisticsService())->collectEntryRows();
	(new StatisticsService())->collectAssetRows();

	$sql = implode("\n", $this->db()->queries);

	expect($sql)->toContain('STRING_AGG(CAST("hubElementsSites"."siteId" AS VARCHAR), \',\' ORDER BY CAST("hubElementsSites"."siteId" AS VARCHAR))')
		->and($sql)->toContain('STRING_AGG(DISTINCT CAST(COALESCE("hubRelations"."sourceSiteId", 0) AS VARCHAR)');
});

it('counts the rows of a snapshot for any sites, like the live ones', function () {
	$statistics = StatisticsService::fromSnapshot(fn (string $kind) => array_combine(['entries', 'assets', 'references'], [
		[
			['sectionId' => '1', 'typeId' => '10', 'fieldId' => null, 'sites' => '1,2', 'enabledSites' => '2', 'total' => '3', 'enabled' => '3', 'pending' => '1', 'expired' => '1', 'drafts' => '1', 'lastUpdated' => '2024-01-01 10:00:00'],
			['sectionId' => '1', 'typeId' => '10', 'fieldId' => null, 'sites' => '1', 'enabledSites' => '1', 'total' => '2', 'enabled' => '2', 'pending' => '0', 'expired' => '0', 'drafts' => '0', 'lastUpdated' => null],
			['sectionId' => null, 'typeId' => '10', 'fieldId' => '5', 'sites' => '2', 'enabledSites' => '2', 'total' => '4', 'enabled' => '4', 'pending' => '0', 'expired' => '0', 'drafts' => '0', 'lastUpdated' => null],
		],
		[
			['volumeId' => '3', 'kind' => 'image', 'sites' => '1,2', 'usedSites' => '2', 'altSites' => '1', 'total' => '2', 'size' => '200', 'lastUploaded' => '2024-02-01 00:00:00'],
			['volumeId' => '3', 'kind' => 'image', 'sites' => '1', 'usedSites' => '0', 'altSites' => null, 'total' => '1', 'size' => '50', 'lastUploaded' => null],
			['volumeId' => '4', 'kind' => 'pdf', 'sites' => '2', 'usedSites' => null, 'altSites' => null, 'total' => '1', 'size' => '10', 'lastUploaded' => null],
		],
		[
			['fieldId' => '5', 'sectionId' => '1', 'volumeId' => null, 'siteId' => '2', 'references' => '6', 'sources' => '3', 'targets' => '2'],
			['fieldId' => '5', 'sectionId' => '1', 'volumeId' => null, 'siteId' => null, 'references' => '1', 'sources' => '1', 'targets' => '1'],
			['fieldId' => '6', 'sectionId' => null, 'volumeId' => '3', 'siteId' => '1', 'references' => '4', 'sources' => '2', 'targets' => '4'],
		],
	])[$kind]);

	$site1 = $statistics->sumEntries([1], sectionId: 1);
	$site2 = $statistics->sumEntries([2]);
	$images1 = $statistics->sumAssets([1], kind: 'image');
	$images2 = $statistics->sumAssets([2], 3);

	expect([$site1->total, $site1->enabled, $site1->pending, $site1->expired, $site1->drafts])->toBe([5, 2, 0, 0, 1])
		->and($site1->lastUpdated->format('Y-m-d'))->toBe('2024-01-01')
		->and([$site2->total, $site2->enabled, $site2->getLive()])->toBe([7, 7, 5])
		->and($statistics->sumEntries([1, 2])->total)->toBe(9)
		->and($statistics->sumEntries([1], fieldId: 5)->total)->toBe(0)
		->and([$images1->total, $images1->used, $images1->images, $images1->missingAlt, $images1->unusedSize])->toBe([3, 1, 3, 1, 200])
		->and($images1->lastUploaded->format('Y-m-d'))->toBe('2024-02-01')
		->and([$images2->total, $images2->used, $images2->missingAlt, $images2->unusedSize])->toBe([2, 2, 2, 0])
		->and($statistics->sumAssets([2], 4)->images)->toBe(0)
		->and($statistics->sumAssets([3])->total)->toBe(0)
		->and($statistics->sumReferences([1], 5)->references)->toBe(1)
		->and($statistics->sumReferences([2], 5, 1)->references)->toBe(7)
		->and($statistics->sumReferences([1], volumeId: 3)->targets)->toBe(4)
		->and($statistics->sumReferences([2], 6)->references)->toBe(0)
		->and($this->db()->queries)->toBe([]);
});
