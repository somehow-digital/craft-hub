<?php

use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\Tests\Support\CountableAssetQuery;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->site(2, 'de');
	$this->volume(1, 'images');
	$this->volume(2, 'documents');
	$this->files = new FileService();
});

function fileFilter(array $values): FileFilter
{
	$filter = new FileFilter();

	foreach ($values as $name => $value) {
		$filter->$name = $value;
	}

	return $filter;
}

it('queries the files on the counted sites, most recently uploaded first', function () {
	$query = $this->files->getQuery(new FileFilter());

	expect($query->siteId)->toBe([1, 2])
		->and($query->unique)->toBeTrue()
		->and($query->orderBy)->toBe(['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC])
		->and($query->where)->toBeNull()
		->and($query->volumeId)->toBeNull()
		->and($query->dateCreated)->toBeNull();
});

it('narrows the files by the filters', function () {
	$query = $this->files->getQuery(fileFilter([
		'search' => 'logo',
		'volumes' => ['images'],
		'kinds' => ['image'],
		'uploaders' => [3],
		'uploadedFrom' => new DateTime('2024-01-01'),
	]));

	expect($query->volumeId)->toBe([1])
		->and($query->kind)->toBe(['image'])
		->and($query->dateCreated)->toBe(['and', '>= 2024-01-01T00:00:00+00:00'])
		->and(conditions($query))->toBe([
			['or', ['like', 'assets.filename', 'logo'], ['like', 'elements_sites.title', 'logo']],
			['assets.uploaderId' => [3]],
		]);
});

it('narrows the files by their extensions, ignoring the case on PostgreSQL', function (string $driver, string $operator) {
	$this->createApp($driver);
	$this->volume(1, 'images');

	expect($this->files->getQuery(fileFilter(['extensions' => ['jpg', 'png']]))->where)->toBe([
		'or',
		[$operator, 'assets.filename', '%.jpg', false],
		[$operator, 'assets.filename', '%.png', false],
	]);
})->with([
	'MySQL' => ['mysql', 'like'],
	'PostgreSQL' => ['pgsql', 'ilike'],
]);

it('narrows the files by the orientation of images', function () {
	expect($this->files->getQuery(fileFilter(['orientations' => FileFilter::getOrientations()]))->where)->toBe([
		'or',
		'[[assets.width]] > [[assets.height]]',
		'[[assets.width]] < [[assets.height]]',
		'[[assets.width]] = [[assets.height]]',
	]);
});

it('narrows the files by usage', function (string $usage, string $operator) {
	$where = $this->files->getQuery(fileFilter(['usage' => $usage]))->where;

	expect($where[0])->toBe($operator)
		->and(rawSql($where[1]))->toContain('`hubRelations`.`targetId` = `elements`.`id`');
})->with([
	'used' => [FileService::USAGE_USED, 'exists'],
	'unused' => [FileService::USAGE_UNUSED, 'not exists'],
]);

it('narrows the files by ranges of relations, size and dimensions', function () {
	$conditions = conditions($this->files->getQuery(fileFilter([
		'relationsMax' => 3,
		'sizeMin' => 1.5,
		'widthMin' => 100,
		'widthMax' => 200,
		'heightMax' => 300,
	])));

	// The first range starts the conditions, the others are added to them.
	expect($conditions)->toHaveCount(5)
		->and($conditions[0])->toBe([])
		->and($conditions[1][0])->toBe('<=')
		->and($conditions[1][2])->toBe(3)
		->and($conditions[2])->toBe(['and', ['>=', 'assets.size', 1572864], []])
		->and($conditions[3])->toBe(['and', ['>=', 'assets.width', 100], ['<=', 'assets.width', 200]])
		->and($conditions[4])->toBe(['and', [], ['<=', 'assets.height', 300]]);
});

it('narrows the files to images without alternative text, large images and diagnoses', function () {
	$checks = $this->mockComponent('hub.checks', CheckService::class);
	$checks->shouldReceive('fileDiagnosisCondition')->with(['notice'])->andReturn(['elements.id' => [5]]);

	$query = $this->files->getQuery(fileFilter(['missingAlt' => true, 'oversized' => true, 'diagnoses' => ['notice']]));
	$review = $this->hub()->review;

	expect($query->where)->toEqual([
		...$review->missingAltCondition(),
		$review->largeImageCondition(),
		['elements.id' => [5]],
	]);
});

it('sorts the files', function (string $sort, array $columns) {
	$order = $this->files->getQuery(new FileFilter(), $sort, SORT_ASC)->orderBy;
	$keys = array_keys($order);

	expect(array_unique(array_values($order)))->toBe([SORT_ASC])
		->and(array_pop($keys))->toBe('elements.id')
		->and($keys)->toHaveCount(count($columns));

	foreach ($columns as $i => $column) {
		expect($keys[$i])->toContain($column);
	}
})->with([
	'file' => [FileService::SORT_FILE, ['assets.filename']],
	'volume' => [FileService::SORT_VOLUME, ['[[hubVolumes.name]]']],
	'kind' => [FileService::SORT_KIND, ['assets.kind']],
	'dimensions' => [FileService::SORT_DIMENSIONS, ['assets.width', 'assets.height']],
	'size' => [FileService::SORT_SIZE, ['assets.size']],
	'relations' => [FileService::SORT_RELATIONS, ['COUNT(*)']],
	'uploaded' => [FileService::SORT_UPLOADED, ['elements.dateCreated']],
]);

it('lists the sort columns with their default direction', function () {
	expect($this->files->getSortColumns())->toBe([
		'file' => SORT_ASC,
		'volume' => SORT_ASC,
		'kind' => SORT_ASC,
		'dimensions' => SORT_DESC,
		'size' => SORT_DESC,
		'relations' => SORT_DESC,
		'uploaded' => SORT_DESC,
	]);
});

it('counts the filename extensions of the files', function (string $driver, string $expression, string $volume) {
	$this->createApp($driver);
	$this->db()->respond('FROM {{%assets}} {{assets}}', [['extension' => 'jpg', 'count' => '3'], ['extension' => 'pdf', 'count' => '1']]);

	expect($this->files->getExtensions(1))->toBe(['jpg' => 3, 'pdf' => 1]);

	$sql = $this->db()->queriesMatching('FROM {{%assets}} {{assets}}')[0];

	expect($sql)->toContain($expression)
		->and($sql)->toContain($volume);
})->with([
	'MySQL' => ['mysql', "LOWER(SUBSTRING_INDEX(`assets`.`filename`, '.', -1))", '`assets`.`volumeId`=1'],
	'PostgreSQL' => ['pgsql', "LOWER(SUBSTRING(\"assets\".\"filename\" FROM '\\.([^.]+)$'))", '"assets"."volumeId"=1'],
]);

it('lists the uploaders of files, most uploads first', function () {
	$this->db()->respond('FROM {{%assets}} {{assets}}', [['uploaderId' => '4', 'count' => '7'], ['uploaderId' => '5', 'count' => '1']]);
	$uploader = new User(['id' => 4, 'username' => 'uploader']);
	ElementQueries::respond(User::class, fn (ElementQuery $query) => [4 => $uploader]);

	expect($this->files->getUploaders(2))->toBe([4 => ['user' => $uploader, 'count' => 7]])
		->and($this->db()->queriesMatching('FROM {{%assets}} {{assets}}')[0])->toContain('`assets`.`volumeId`=2');
});

it('lists no uploaders when no files have any', function () {
	expect($this->files->getUploaders())->toBe([])
		->and(ElementQueries::executed(User::class))->toBe([]);
});

it('counts the files matching each value of a filter', function () {
	$files = Mockery::mock(FileService::class)->makePartial();
	$files->shouldReceive('getQuery')->andReturnUsing(fn (FileFilter $filter) => CountableAssetQuery::of((new FileService())->getQuery($filter)));
	ElementQueries::respond(Asset::class, fn (ElementQuery $query) => $query->kind === ['image'] ? [new Asset(), new Asset()] : []);

	$base = fileFilter(['volumes' => ['images']]);

	expect($files->getCounts(['image', 'pdf'], fn (FileFilter $filter, string $value) => $filter->kinds = [$value], $base))->toBe(['image' => 2, 'pdf' => 0])
		->and($base->kinds)->toBe([])
		->and(ElementQueries::executed(Asset::class)[0]->volumeId)->toBe([1])
		->and($files->getCounts(['image'], fn (FileFilter $filter, string $value) => $filter->kinds = [$value]))->toBe(['image' => 2]);
});

it('finds a file on the selected sites, preferring the current site', function () {
	$asset = new Asset(['id' => 1, 'siteId' => 2]);
	ElementQueries::respond(Asset::class, [$asset]);
	$this->request([Hub::SITE_PARAM => 'de']);

	expect($this->files->getFile(1))->toBe($asset)
		->and(ElementQueries::executed(Asset::class)[0]->siteId)->toBe([2])
		->and(ElementQueries::executed(Asset::class)[0]->preferSites)->toBe([2, 1]);
});

it('finds the files on each counted site at once', function () {
	$variants = [new Asset(['id' => 1, 'siteId' => 1]), new Asset(['id' => 2, 'siteId' => 1]), new Asset(['id' => 1, 'siteId' => 2])];
	ElementQueries::respond(Asset::class, $variants);

	$grouped = $this->files->getSiteVariantsOf([new Asset(['id' => 1]), new Asset(['id' => 2])]);

	expect($grouped)->toBe([1 => [$variants[0], $variants[2]], 2 => [$variants[1]]])
		->and(ElementQueries::executed(Asset::class)[0]->id)->toBe([1, 2])
		->and($this->files->getSiteVariants(new Asset(['id' => 1])))->toBe([$variants[0], $variants[2]])
		->and($this->files->getSiteVariants(new Asset(['id' => 3])))->toBe([])
		->and($this->files->getSiteVariantsOf([]))->toBe([]);
});

it('lists the image transforms of files, newest first', function () {
	$this->db()->respond('FROM {{%imagetransformindex}}', [
		['assetId' => '1', 'transformString' => '_100x100', 'transformer' => 'craft', 'filename' => 'a.jpg', 'format' => null, 'fileExists' => '1', 'inProgress' => '0', 'error' => '0', 'dateIndexed' => '2024-01-02 00:00:00'],
		['assetId' => '1', 'transformString' => '_200x200', 'transformer' => 'craft', 'filename' => 'a.jpg', 'format' => 'webp', 'fileExists' => '0', 'inProgress' => '0', 'error' => '1', 'dateIndexed' => null],
		['assetId' => '2', 'transformString' => '_50x50', 'transformer' => 'craft', 'filename' => 'b.jpg', 'format' => null, 'fileExists' => '1', 'inProgress' => '1', 'error' => '0', 'dateIndexed' => null],
	]);

	$transforms = $this->files->getTransformsOf([new Asset(['id' => 1]), new Asset(['id' => 2])]);

	expect(array_map('count', $transforms))->toBe([1 => 2, 2 => 1])
		->and($transforms[1][0]['fileExists'])->toBeTrue()
		->and($transforms[1][0]['dateIndexed'])->toBeInstanceOf(DateTime::class)
		->and($transforms[1][0])->not->toHaveKey('assetId')
		->and($transforms[1][1]['error'])->toBeTrue()
		->and($transforms[1][1]['dateIndexed'])->toBeNull()
		->and($transforms[2][0]['inProgress'])->toBeTrue()
		->and($this->files->getTransforms(new Asset(['id' => 2])))->toHaveCount(1)
		->and($this->files->getTransforms(new Asset(['id' => 3])))->toBe([])
		->and($this->files->getTransformsOf([]))->toBe([]);
});
