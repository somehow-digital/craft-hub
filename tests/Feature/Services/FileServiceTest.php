<?php

use craft\base\FsInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use craft\errors\FsException;
use craft\imagetransforms\ImageTransformer;
use craft\models\ImageTransformIndex;
use craft\models\Volume;
use craft\services\ImageTransforms;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\FileService;
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

it('narrows the files to the ones the user viewing them uploaded', function () {
	Craft::$app->getUser()->shouldReceive('getId')->andReturn(7);

	expect(conditions($this->files->getQuery(fileFilter(['uploaders' => ['me']]))))->toBe([['assets.uploaderId' => [7]]]);
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

it('narrows the files by usage, through relations or reference tags', function (string $usage, bool $negated) {
	$where = $this->files->getQuery(fileFilter(['usage' => $usage]))->where;
	$sql = conditionSql($where);

	expect($where[0])->toBe($negated ? 'and' : 'or')
		->and($sql)->toContain('`hubRelations`.`targetId` = `elements`.`id`')
		->and($sql)->toContain('`hubReferences`.`targetId` = `elements`.`id`');
})->with([
	'used' => [FileService::USAGE_USED, false],
	'unused' => [FileService::USAGE_UNUSED, true],
]);

it('narrows the files by ranges of references, size and dimensions', function () {
	$conditions = conditions($this->files->getQuery(fileFilter([
		'referencesMax' => 3,
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

it('narrows the files to images without alternative text and large images', function () {
	$query = $this->files->getQuery(fileFilter(['missingAlt' => true, 'oversized' => true]));
	$review = $this->hub()->review;

	expect($query->where)->toEqual([
		...$review->missingAltCondition(),
		$review->largeImageCondition(),
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
	'references' => [FileService::SORT_REFERENCES, ['COUNT(*)']],
	'uploaded' => [FileService::SORT_UPLOADED, ['elements.dateCreated']],
]);

it('lists the sort columns with their default direction', function () {
	expect($this->files->getSortColumns())->toBe([
		'file' => SORT_ASC,
		'volume' => SORT_ASC,
		'kind' => SORT_ASC,
		'dimensions' => SORT_DESC,
		'size' => SORT_DESC,
		'references' => SORT_DESC,
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
		['id' => '11', 'assetId' => '1', 'transformString' => '_100x100', 'transformer' => 'craft', 'filename' => 'a.jpg', 'format' => null, 'fileExists' => '1', 'inProgress' => '0', 'error' => '0', 'dateIndexed' => '2024-01-02 00:00:00'],
		['id' => '12', 'assetId' => '1', 'transformString' => '_200x200', 'transformer' => 'craft', 'filename' => 'a.webp', 'format' => 'webp', 'fileExists' => '0', 'inProgress' => '0', 'error' => '1', 'dateIndexed' => null],
		['id' => '13', 'assetId' => '2', 'transformString' => '_50x50', 'transformer' => 'craft', 'filename' => 'b.jpg', 'format' => null, 'fileExists' => '1', 'inProgress' => '1', 'error' => '0', 'dateIndexed' => null],
	]);

	$transforms = $this->files->getTransformsOf([new Asset(['id' => 1]), new Asset(['id' => 2])]);

	expect(array_map('count', $transforms))->toBe([1 => 2, 2 => 1])
		->and($transforms[1][0]['id'])->toBe(11)
		->and($transforms[1][0]['fileExists'])->toBeTrue()
		->and($transforms[1][0]['dateIndexed'])->toBeInstanceOf(DateTime::class)
		->and($transforms[1][0]['outputFormat'])->toBe('jpg')
		->and($transforms[1][1]['outputFormat'])->toBe('webp')
		->and($transforms[1][0])->not->toHaveKey('assetId')
		->and($transforms[1][1]['error'])->toBeTrue()
		->and($transforms[1][1]['dateIndexed'])->toBeNull()
		->and($transforms[2][0]['inProgress'])->toBeTrue()
		->and($this->files->getTransforms(new Asset(['id' => 2])))->toHaveCount(1)
		->and($this->files->getTransforms(new Asset(['id' => 3])))->toBe([])
		->and($this->files->getTransformsOf([]))->toBe([]);
});

it('deletes transforms of a file along with the files Craft generated', function () {
	$this->db()->respond('SELECT `id`, `assetId`, `transformer`', [
		['id' => '11', 'assetId' => '1', 'transformer' => ImageTransformer::class, 'filename' => 'a.jpg', 'format' => null, 'transformString' => '_100x100', 'fileExists' => '1', 'inProgress' => '0', 'error' => '0'],
		['id' => '12', 'assetId' => '1', 'transformer' => ImageTransformer::class, 'filename' => 'a.webp', 'format' => 'webp', 'transformString' => '_200x200', 'fileExists' => '0', 'inProgress' => '0', 'error' => '1'],
		['id' => '13', 'assetId' => '1', 'transformer' => 'other\\Transformer', 'filename' => 'a.png', 'format' => null, 'transformString' => '_50x50', 'fileExists' => '1', 'inProgress' => '0', 'error' => '0'],
	]);

	$asset = new Asset(['id' => 1]);
	$transformer = Mockery::mock(ImageTransformer::class);
	$transformer->shouldReceive('deleteImageTransformFile')->once()
		->with($asset, Mockery::on(fn (ImageTransformIndex $index) => $index->id === 11 && $index->transformString === '_100x100'));
	$transforms = Mockery::mock(ImageTransforms::class)->makePartial();
	$transforms->shouldReceive('getImageTransformer')->with(ImageTransformer::class)->andReturn($transformer);
	Craft::$app->set('imageTransforms', $transforms);
	$activity = $this->mockComponent('hub.activity', ActivityService::class);
	$activity->shouldReceive('isEnabled')->andReturnTrue();
	$activity->shouldReceive('record')->once()
		->with(ActivityAction::TransformsDeleted, ActivitySubject::Asset, 1, Mockery::any(), ['transforms' => ['_100x100', '_200x200', '_50x50']], Mockery::any());

	expect($this->files->deleteTransforms($asset, [11, 12, 13, 99]))->toBe(3)
		->and($this->db()->queriesMatching('FROM {{%imagetransformindex}}')[0])->toContain('`assetId`=1')
		->and($this->db()->queriesMatching('DELETE FROM {{%imagetransformindex}}'))->toBe(['DELETE FROM `imagetransformindex` WHERE `id` IN (11, 12, 13)']);
});

it('deletes no transforms without IDs or matching ones', function () {
	expect($this->files->deleteTransforms(new Asset(['id' => 1]), []))->toBe(0)
		->and($this->files->deleteTransforms(new Asset(['id' => 1]), [5]))->toBe(0)
		->and($this->db()->queriesMatching('DELETE FROM'))->toBe([]);
});

it('links generated transforms to their file in the requested format', function () {
	$asset = new class(['id' => 1]) extends Asset {
		public mixed $requested = null;

		public function getUrl(mixed $transform = null, ?bool $immediately = null): ?string
		{
			$this->requested = $transform;

			return 'https://example.com/_transforms/a.webp';
		}
	};

	$transform = ['transformString' => '_100x50_crop_center-center_none', 'format' => 'webp', 'fileExists' => true, 'error' => false];

	expect($this->files->getTransformUrl($asset, $transform))->toBe('https://example.com/_transforms/a.webp')
		->and($asset->requested->width)->toBe(100)
		->and($asset->requested->height)->toBe(50)
		->and($asset->requested->format)->toBe('webp')
		->and($this->files->getTransformUrl($asset, [...$transform, 'fileExists' => false]))->toBeNull()
		->and($this->files->getTransformUrl($asset, [...$transform, 'error' => true]))->toBeNull();
});

it('works out the dimensions a transform resizes the file to', function () {
	$asset = new Asset(['id' => 1, 'kind' => Asset::KIND_IMAGE, 'filename' => 'a.jpg', 'width' => 400, 'height' => 200]);

	expect($this->files->getTransformDimensions($asset, ['transformString' => '_100x100_fit_center-center_none']))->toBe(['width' => 100, 'height' => 50])
		->and($this->files->getTransformDimensions($asset, ['transformString' => '_100x50_crop_center-center_none']))->toBe(['width' => 100, 'height' => 50])
		->and($this->files->getTransformDimensions($asset, ['transformString' => '_unknown']))->toBe(['width' => null, 'height' => null]);
});

it('measures transformed images Craft generated in the transform filesystem', function () {
	$fs = Mockery::mock(FsInterface::class);
	$fs->shouldReceive('getFileSize')->with('_transforms/photos/_100x100/1/a.webp')->andReturn(1234);
	$fs->shouldReceive('getFileSize')->with('_transforms/photos/_200x200/a.jpg')->andThrow(new FsException('Missing'));

	$volume = Mockery::mock(Volume::class)->makePartial();
	$volume->shouldReceive('getTransformFs')->andReturn($fs);
	$volume->shouldReceive('getTransformSubpath')->andReturn('_transforms/');

	$asset = Mockery::mock(Asset::class)->makePartial();
	$asset->id = 1;
	$asset->folderPath = 'photos/';
	$asset->shouldReceive('getVolume')->andReturn($volume);
	$asset->shouldReceive('getFilename')->andReturn('a.jpg');

	$transform = ['transformString' => '_100x100', 'transformer' => ImageTransformer::class, 'filename' => 'a.webp', 'fileExists' => true, 'error' => false];

	expect($this->files->getTransformSize($asset, $transform))->toBe(1234)
		->and($this->files->getTransformSize($asset, [...$transform, 'transformString' => '_200x200', 'filename' => 'a.jpg']))->toBeNull()
		->and($this->files->getTransformSize($asset, [...$transform, 'fileExists' => false]))->toBeNull()
		->and($this->files->getTransformSize($asset, [...$transform, 'error' => true]))->toBeNull()
		->and($this->files->getTransformSize($asset, [...$transform, 'transformer' => 'other\\Transformer']))->toBeNull();
});
