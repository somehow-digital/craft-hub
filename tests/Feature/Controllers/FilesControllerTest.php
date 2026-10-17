<?php

use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use SomehowDigital\Craft\Hub\controllers\FilesController;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->volume(1, 'images', name: 'Images');
	$this->volume(2, 'documents', name: 'Documents');

	$this->db()->respond('FROM {{%assets}} {{assets}}', fn (string $sql) => match (true) {
		str_contains($sql, 'GROUP BY `assets`.`volumeId`, `assets`.`kind`') => [
			['volumeId' => 1, 'kind' => 'image', 'total' => 4, 'used' => 1, 'images' => 4, 'missingAlt' => 2, 'size' => 4000, 'lastUploaded' => null],
			['volumeId' => 2, 'kind' => 'pdf', 'total' => 1, 'used' => 1, 'images' => 0, 'missingAlt' => 0, 'size' => 1000, 'lastUploaded' => null],
		],
		str_contains($sql, 'AS `extension`') => [['extension' => 'jpg', 'count' => 4]],
		default => [],
	});

	$this->countableQueries();
});

it('lists the files', function () {
	$asset = new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg', 'size' => 10]);
	ElementQueries::respond(Asset::class, [$asset]);

	$response = $this->runAction(FilesController::class, 'index');
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/files/index')
		->and($variables['assets'])->toBe([$asset])
		->and(array_keys($variables['diagnostics']))->toBe([1])
		->and(array_keys($variables['fileKinds']))->toBe(['image', 'pdf'])
		->and(array_keys($variables['volumes']))->toBe([2, 1])
		->and($variables['extensions'])->toBe(['jpg' => 4])
		->and($variables['uploaders'])->toBe([])
		->and($variables['counts']['kinds'])->toBe(['image' => 1, 'pdf' => 1])
		->and(array_keys($variables['counts']['orientations']))->toBe(['landscape', 'portrait', 'square'])
		->and(array_keys($variables['counts']['usage']))->toBe(['used', 'unused'])
		->and($variables['listFixed'])->toBeNull();
});

it('reads the filters of the files list', function () {
	$filter = $this->runAction(FilesController::class, 'index', query: [
		'q' => 'logo',
		'volume' => 'images,bogus',
		'kind' => 'image,bogus',
		'extension' => 'JPG,we!rd',
		'orientation' => 'portrait',
		'uploader' => '4',
		'usage' => 'used',
		'sizeMin' => '0.5',
		'sizeMax' => 'large',
		'widthMin' => '100',
		'heightMax' => '200.9',
		'uploadedFrom' => '2024-01-01',
		'alt' => '1',
		'oversized' => '1',
		'diagnosis' => 'notice',
	])->variables['filter'];

	expect($filter->search)->toBe('logo')
		->and($filter->volumes)->toBe(['images'])
		->and($filter->kinds)->toBe(['image'])
		->and($filter->extensions)->toBe(['jpg'])
		->and($filter->orientations)->toBe(['portrait'])
		->and($filter->uploaders)->toBe([4])
		->and($filter->usage)->toBe('used')
		->and($filter->sizeMin)->toBe(0.5)
		->and($filter->sizeMax)->toBeNull()
		->and($filter->widthMin)->toBe(100)
		->and($filter->heightMax)->toBe(200)
		->and($filter->uploadedFrom->format('Y-m-d'))->toBe('2024-01-01')
		->and($filter->missingAlt)->toBeTrue()
		->and($filter->oversized)->toBeTrue()
		->and($filter->diagnoses)->toBe(['notice']);
});

it('summarizes the files', function () {
	[$assets, $volumes, $kinds, $alt] = $this->runAction(FilesController::class, 'index')->variables['summary'];

	expect($assets['total'])->toBe(5)
		->and($assets['segments'][0]['url'])->toContain('usage=used')
		->and($assets['segments'][1]['url'])->toContain('usage=unused')
		->and(array_column($volumes['segments'], 'value', 'label'))->toBe(['Images' => 4, 'Documents' => 1])
		->and($volumes['segments'][0]['url'])->toContain('volume=images')
		->and($kinds['segments'][1]['url'])->toContain('kind=pdf')
		->and($alt['segments'][1]['value'])->toBe(2)
		->and($alt['segments'][1]['url'])->toContain('alt=1');
});

it('shows a file', function () {
	$asset = new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg', 'size' => 10]);
	ElementQueries::respond(Asset::class, fn (AssetQuery $query) => [$asset]);

	$response = $this->runAction(FilesController::class, 'view', ['id' => 1]);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/files/view')
		->and($variables['asset'])->toBe($asset)
		->and($variables['fileKind']->kind)->toBe('image')
		->and($variables['relations'])->toBe([])
		->and($variables['sites'])->toBe([$asset])
		->and($variables['transforms'])->toBe([])
		->and($variables['checks'])->not->toBeEmpty();
});

it('shows files of kinds no volume has', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'video', 'filename' => 'a.mp4'])]);

	expect($this->runAction(FilesController::class, 'view', ['id' => 1])->variables['fileKind']->kind)->toBe('video');
});

it('fails for files that do not exist', function () {
	$this->runAction(FilesController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);
