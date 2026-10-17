<?php

use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ListService;

beforeEach(function () {
	$this->createContentModel();

	// The checks of an item are its handle, so each item can be given a diagnosis and rank.
	$this->diagnoses = [];
	$this->checks = $this->mockComponent('hub.checks', CheckService::class);

	foreach (['forSection' => 'section', 'forEntryType' => 'entryType', 'forField' => 'field', 'forVolume' => 'volume'] as $method => $property) {
		$this->checks->shouldReceive($method)->andReturnUsing(fn (object $info) => [$info->$property->handle]);
	}

	$this->checks->shouldReceive('diagnosis')->andReturnUsing(fn (array $checks) => $this->diagnoses[$checks[0]] ?? 'passed');
	$this->checks->shouldReceive('rank')->andReturnUsing(fn (array $checks) => array_search($checks[0], array_keys($this->diagnoses), true) === false ? 0 : 10 - array_search($checks[0], array_keys($this->diagnoses), true));

	$this->lists = new ListService();
});

/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
function listFilter(string $class, array $values): object
{
	$filter = new $class();

	foreach ($values as $name => $value) {
		$filter->$name = $value;
	}

	return $filter;
}

/**
 * The handles of the listed items, in order.
 *
 * @return string[]
 */
function listed(array $items, string $property): array
{
	return array_values(array_map(fn (object $info) => $info->$property->handle, $items));
}

function withVolumes(): void
{
	test()->volume(2, 'documents', hasUrls: false, name: 'Documents');
	test()->volume(3, 'videos', name: 'Videos');

	test()->db()->respond('FROM {{%assets}} {{assets}}', [
		['volumeId' => 1, 'kind' => 'image', 'total' => 4, 'used' => 2, 'images' => 4, 'missingAlt' => 3, 'size' => 3 * 1024 * 1024, 'lastUploaded' => '2024-02-10 12:00:00'],
		['volumeId' => 2, 'kind' => 'pdf', 'total' => 2, 'used' => 0, 'images' => 0, 'missingAlt' => 0, 'size' => 1024 * 1024, 'lastUploaded' => '2024-01-01 00:00:00'],
	]);
}

it('lists the sections by name', function () {
	expect(listed($this->lists->getSections(new SectionFilter()), 'section'))->toBe(['archive', 'news', 'pages', 'settings'])
		->and(listed($this->lists->getSections(new SectionFilter(), direction: SORT_DESC), 'section'))->toBe(['settings', 'pages', 'news', 'archive']);
});

it('filters the sections', function (array $values, array $handles) {
	expect(listed($this->lists->getSections(listFilter(SectionFilter::class, $values)), 'section'))->toBe($handles);
})->with([
	'by name' => [['search' => 'NEW'], ['news']],
	'by handle' => [['search' => 'sett'], ['settings']],
	'by type' => [['types' => ['channel']], ['archive', 'news']],
	'with URLs' => [['urls' => ListService::URLS_YES], ['news', 'pages']],
	'without URLs' => [['urls' => ListService::URLS_NO], ['archive', 'settings']],
	'by entries' => [['entriesMin' => 2, 'entriesMax' => 4], ['pages']],
	'by references' => [['referencesMin' => 1], ['news', 'pages']],
	'by activity' => [['activities' => [EntryStatistics::ACTIVITY_STALE, EntryStatistics::ACTIVITY_NONE]], ['archive', 'settings']],
	'empty' => [['empty' => true], ['archive']],
]);

it('filters and sorts the sections by their diagnoses', function () {
	$this->diagnoses = ['pages' => 'warning', 'news' => 'notice'];

	expect(listed($this->lists->getSections(listFilter(SectionFilter::class, ['diagnoses' => ['warning']])), 'section'))->toBe(['pages'])
		->and(listed($this->lists->getSections(new SectionFilter(), ListService::SORT_DIAGNOSTICS, SORT_DESC), 'section'))->toBe(['pages', 'news', 'archive', 'settings']);
});

it('sorts the sections', function (string $sort, int $direction, array $handles) {
	expect(listed($this->lists->getSections(new SectionFilter(), $sort, $direction), 'section'))->toBe($handles);
})->with([
	'by type' => [ListService::SORT_TYPE, SORT_ASC, ['archive', 'news', 'settings', 'pages']],
	'by entries' => [ListService::SORT_ENTRIES, SORT_DESC, ['news', 'pages', 'settings', 'archive']],
	'by references' => [ListService::SORT_REFERENCES, SORT_DESC, ['news', 'pages', 'archive', 'settings']],
	'by last update' => [ListService::SORT_UPDATED, SORT_DESC, ['news', 'pages', 'settings', 'archive']],
]);

it('lists the entry types by name', function () {
	expect(listed($this->lists->getEntryTypes(new EntryTypeFilter()), 'entryType'))->toBe(['article', 'block', 'empty', 'orphan', 'page']);
});

it('filters the entry types', function (array $values, array $handles) {
	expect(listed($this->lists->getEntryTypes(listFilter(EntryTypeFilter::class, $values)), 'entryType'))->toBe($handles);
})->with([
	'by search' => [['search' => 'art'], ['article']],
	'by kind' => [['kinds' => ['nested', 'unused']], ['block', 'orphan']],
	'by entries' => [['entriesMin' => 5], ['article', 'block']],
	'by sections and relations' => [['relationsMin' => 3], ['article', 'page']],
	'by activity' => [['activities' => [EntryStatistics::ACTIVITY_NONE]], ['empty', 'orphan']],
]);

it('sorts the entry types', function (string $sort, array $handles) {
	expect(listed($this->lists->getEntryTypes(new EntryTypeFilter(), $sort, SORT_DESC), 'entryType'))->toBe($handles);
})->with([
	'by entries' => [ListService::SORT_ENTRIES, ['block', 'article', 'page', 'empty', 'orphan']],
	'by relations' => [ListService::SORT_RELATIONS, ['article', 'page', 'empty', 'block', 'orphan']],
	'by last update' => [ListService::SORT_UPDATED, ['article', 'block', 'page', 'empty', 'orphan']],
	'by diagnostics' => [ListService::SORT_DIAGNOSTICS, ['article', 'block', 'empty', 'orphan', 'page']],
]);

it('filters the entry types by their diagnoses', function () {
	$this->diagnoses = ['orphan' => 'warning'];

	expect(listed($this->lists->getEntryTypes(listFilter(EntryTypeFilter::class, ['diagnoses' => ['warning']])), 'entryType'))->toBe(['orphan'])
		->and(listed($this->lists->getEntryTypes(listFilter(EntryTypeFilter::class, ['diagnoses' => ['passed']])), 'entryType'))->toBe(['article', 'block', 'empty', 'page']);
});

it('lists the fields by name', function () {
	expect(listed($this->lists->getFields(new FieldFilter()), 'field'))->toBe(['blocks', 'cta', 'image', 'legacy', 'related', 'summary', 'teaserImage', 'teaserImg', 'unusedText']);
});

it('filters the fields', function (array $values, array $handles) {
	expect(listed($this->lists->getFields(listFilter(FieldFilter::class, $values)), 'field'))->toBe($handles);
})->with([
	'by search' => [['search' => 'teaser'], ['teaserImage', 'teaserImg']],
	'by type' => [['types' => ['matrix']], ['blocks', 'legacy']],
	'by data kind' => [['kinds' => ['relational']], ['cta', 'image', 'related', 'teaserImage', 'teaserImg']],
	'by owner' => [['owners' => ['volume', 'users']], ['summary']],
	'by layouts' => [['layoutsMin' => 2], ['summary']],
	'unused' => [['unused' => true], ['legacy', 'teaserImg', 'unusedText']],
	'empty' => [['empty' => true, 'kinds' => ['nested']], []],
]);

it('sorts the fields', function (string $sort, int $direction, array $handles) {
	expect(listed($this->lists->getFields(new FieldFilter(), $sort, $direction), 'field'))->toBe($handles);
})->with([
	'by type' => [ListService::SORT_TYPE, SORT_ASC, ['image', 'teaserImage', 'teaserImg', 'related', 'cta', 'blocks', 'legacy', 'summary', 'unusedText']],
	'by layouts' => [ListService::SORT_LAYOUTS, SORT_DESC, ['summary', 'blocks', 'cta', 'image', 'related', 'teaserImage', 'legacy', 'teaserImg', 'unusedText']],
	'by data' => [ListService::SORT_DATA, SORT_DESC, ['blocks', 'related', 'image', 'cta', 'teaserImage', 'teaserImg', 'legacy', 'summary', 'unusedText']],
]);

it('filters and sorts the fields by their diagnoses', function () {
	$this->diagnoses = ['legacy' => 'warning'];

	expect(listed($this->lists->getFields(listFilter(FieldFilter::class, ['diagnoses' => ['warning']])), 'field'))->toBe(['legacy'])
		->and(listed($this->lists->getFields(new FieldFilter(), ListService::SORT_DIAGNOSTICS, SORT_DESC), 'field')[0])->toBe('legacy');
});

it('tells how much data fields hold', function () {
	$structure = $this->hub()->structure;

	expect($this->lists->getFieldData($structure->getField(2)))->toBe(7)
		->and($this->lists->getFieldData($structure->getField(3)))->toBe(4)
		->and($this->lists->getFieldData($structure->getField(1)))->toBeNull();
});

it('lists the volumes by name', function () {
	withVolumes();

	expect(listed($this->lists->getVolumes(new VolumeFilter()), 'volume'))->toBe(['documents', 'images', 'videos']);
});

it('filters the volumes', function (array $values, array $handles) {
	withVolumes();

	expect(listed($this->lists->getVolumes(listFilter(VolumeFilter::class, $values)), 'volume'))->toBe($handles);
})->with([
	'by search' => [['search' => 'doc'], ['documents']],
	'by kind' => [['kinds' => ['image']], ['images']],
	'with URLs' => [['urls' => ListService::URLS_YES], ['images', 'videos']],
	'by assets' => [['assetsMin' => 1, 'assetsMax' => 3], ['documents']],
	'by size' => [['sizeMin' => 2.5], ['images']],
	'by missing alternative text' => [['altMin' => 1], ['images']],
	'uploaded from' => [['uploadedFrom' => new DateTime('2024-02-10 18:00')], ['images']],
	'uploaded until' => [['uploadedTo' => new DateTime('2024-01-01')], ['documents']],
	'uploaded between' => [['uploadedFrom' => new DateTime('2024-01-02'), 'uploadedTo' => new DateTime('2024-02-09')], []],
	'empty' => [['empty' => true], ['videos']],
]);

it('sorts the volumes', function (string $sort, array $handles) {
	withVolumes();
	$this->diagnoses = ['videos' => 'critical'];

	expect(listed($this->lists->getVolumes(new VolumeFilter(), $sort, SORT_DESC), 'volume'))->toBe($handles);
})->with([
	'by filesystem' => [ListService::SORT_FILESYSTEM, ['videos', 'images', 'documents']],
	'by assets' => [ListService::SORT_ASSETS, ['images', 'documents', 'videos']],
	'by size' => [ListService::SORT_SIZE, ['images', 'documents', 'videos']],
	'by missing alternative text' => [ListService::SORT_ALT, ['images', 'documents', 'videos']],
	'by references' => [ListService::SORT_REFERENCES, ['images', 'documents', 'videos']],
	'by last upload' => [ListService::SORT_UPLOADED, ['images', 'documents', 'videos']],
	'by diagnostics' => [ListService::SORT_DIAGNOSTICS, ['videos', 'documents', 'images']],
]);

it('filters the volumes by their diagnoses', function () {
	withVolumes();
	$this->diagnoses = ['videos' => 'critical'];

	expect(listed($this->lists->getVolumes(listFilter(VolumeFilter::class, ['diagnoses' => ['critical']])), 'volume'))->toBe(['videos'])
		->and(listed($this->lists->getVolumes(listFilter(VolumeFilter::class, ['diagnoses' => ['passed']])), 'volume'))->toBe(['documents', 'images']);
});

it('lists the sort columns with their default direction', function () {
	expect($this->lists->getSectionSortColumns())->toBe(['name' => SORT_ASC, 'type' => SORT_ASC, 'entries' => SORT_DESC, 'references' => SORT_DESC, 'updated' => SORT_DESC, 'diagnostics' => SORT_DESC])
		->and($this->lists->getEntryTypeSortColumns())->toBe(['name' => SORT_ASC, 'relations' => SORT_DESC, 'entries' => SORT_DESC, 'updated' => SORT_DESC, 'diagnostics' => SORT_DESC])
		->and($this->lists->getFieldSortColumns())->toBe(['name' => SORT_ASC, 'type' => SORT_ASC, 'layouts' => SORT_DESC, 'data' => SORT_DESC, 'diagnostics' => SORT_DESC])
		->and(array_keys($this->lists->getVolumeSortColumns()))->toBe(['name', 'fs', 'assets', 'size', 'alt', 'references', 'uploaded', 'diagnostics']);
});
