<?php

use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\FilesController;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

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

	// Posts the body params to the action deleting transforms as a signed-in user, asking for JSON.
	$this->deleteTransforms = function (array $body): Response {
		$this->request();
		$request = Craft::$app->getRequest();
		$request->enableCsrfValidation = false;
		$request->enableCookieValidation = false;
		$request->setBodyParams($body);
		$request->getHeaders()->set('Accept', 'application/json');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

		try {
			return Craft::createObject(FilesController::class, ['files', $this->hub()])->runAction('delete-transforms');
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}
	};
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
		->and($variables['references'])->toBe([])
		->and($variables['sites'])->toBe([$asset])
		->and($variables['transforms'])->toBe([])
		->and($variables['checks'])->not->toBeEmpty();
});

it('shows the transforms tab of images', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);

	$variables = $this->runAction(FilesController::class, 'view', ['id' => 1, 'tab' => 'transforms'])->variables;

	expect($variables['selectedTab'])->toBe('transforms')
		->and($variables['hasTransforms'])->toBeTrue()
		->and($variables['canDeleteTransforms'])->toBeTrue();
});

it('has no transforms tab for other files without transforms', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'pdf', 'filename' => 'a.pdf'])]);

	expect($this->runAction(FilesController::class, 'view', ['id' => 1])->variables['hasTransforms'])->toBeFalse();

	$this->runAction(FilesController::class, 'view', ['id' => 1, 'tab' => 'transforms']);
})->throws(NotFoundHttpException::class);

it('deletes the selected transforms of a file', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);
	$this->db()->respond('SELECT `id`, `assetId`, `transformer`', [
		['id' => '11', 'assetId' => '1', 'transformer' => 'other\\Transformer', 'filename' => 'a.jpg', 'format' => null, 'transformString' => '_100x100', 'fileExists' => '1', 'inProgress' => '0', 'error' => '0'],
	]);

	$response = ($this->deleteTransforms)(['assetId' => '1', 'transformIds' => ['11', 'bogus']]);

	expect($response->data)->toMatchArray(['count' => 1, 'message' => 'One transform deleted.'])
		->and($response->data['redirect'])->toContain('hub/data/assets/1/transforms')
		->and($this->db()->queriesMatching('DELETE FROM {{%imagetransformindex}}')[0])->toContain('`id`=11');
});

it('requires permission to save the file to delete its transforms', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);
	$this->actingAs(canSave: false);

	($this->deleteTransforms)(['assetId' => '1', 'transformIds' => ['11']]);
})->throws(ForbiddenHttpException::class);

it('fails to delete transforms of files that do not exist', function () {
	($this->deleteTransforms)(['assetId' => '99', 'transformIds' => ['11']]);
})->throws(NotFoundHttpException::class);

it('requires a post request to delete transforms', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1]));

	Craft::createObject(FilesController::class, ['files', $this->hub()])->runAction('delete-transforms');
})->throws(MethodNotAllowedHttpException::class);

it('shows files of kinds no volume has', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'video', 'filename' => 'a.mp4'])]);

	expect($this->runAction(FilesController::class, 'view', ['id' => 1])->variables['fileKind']->kind)->toBe('video');
});

it('fails for files that do not exist', function () {
	$this->runAction(FilesController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);

it('leaves out the diagnostics of the files with their column hidden', function () {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_FILES => ['order' => [], 'hidden' => ['diagnostics']]]];
	$files = Mockery::mock(FileService::class)->makePartial();
	$files->shouldNotReceive('getSiteVariantsOf', 'getTransformsOf');
	$this->hub()->set('files', $files);
	$checks = Mockery::mock(CheckService::class)->makePartial();
	$checks->shouldNotReceive('forFile');
	$this->hub()->set('checks', $checks);
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg', 'size' => 10])]);

	$variables = $this->runAction(FilesController::class, 'index')->variables;

	expect($variables['diagnostics'])->toBe([])
		->and($variables['assets'])->toHaveCount(1);
});
