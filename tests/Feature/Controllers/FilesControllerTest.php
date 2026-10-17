<?php

use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\BaseController;
use SomehowDigital\Craft\Hub\controllers\FilesController;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

beforeEach(function () {
	$this->volume(1, 'images', name: 'Images');
	$this->volume(2, 'documents', name: 'Documents');

	$this->db()->respond('FROM {{%assets}} {{assets}}', fn (string $sql) => match (true) {
		str_contains($sql, 'GROUP BY `assets`.`kind`') => [['kind' => 'image'], ['kind' => 'pdf']],
		str_contains($sql, 'GROUP BY `volumeId`, `kind`') => [
			['volumeId' => 1, 'kind' => 'image', 'total' => 4, 'used' => 1, 'images' => 4, 'missingAlt' => 2, 'size' => 4000, 'unusedSize' => 3000, 'lastUploaded' => null],
			['volumeId' => 2, 'kind' => 'pdf', 'total' => 1, 'used' => 1, 'images' => 0, 'missingAlt' => 0, 'size' => 1000, 'unusedSize' => 0, 'lastUploaded' => null],
		],
		str_contains($sql, 'AS `extension`') => [['extension' => 'jpg', 'count' => 4]],
		default => [],
	});

	// Posts the body params to the action deleting transforms, or another one, as a signed-in user, asking for JSON.
	$this->deleteTransforms = function (array $body, string $action = 'delete-transforms'): Response {
		$this->request();
		$request = Craft::$app->getRequest();
		$request->enableCsrfValidation = false;
		$request->enableCookieValidation = false;
		$request->setBodyParams($body);
		$request->getHeaders()->set('Accept', 'application/json');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

		try {
			return Craft::createObject(FilesController::class, ['files', $this->hub()])->runAction($action);
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
		->and($variables['fileKinds']['image']->getIcon())->toBe('image')
		->and(array_map(fn ($volume) => $volume->id, $variables['volumes']))->toBe([2, 1])
		// The options read from the database are loaded as their dropdowns open.
		->and($variables['kindOptions'])->toBe([])
		->and($variables['extensionOptions'])->toBe([])
		->and($variables['uploaderOptions'])->toBe([])
		->and($variables['optionsUrls']['extension'])->toContain('filter=extension')
		->and($this->db()->queriesMatching('GROUP BY `assets`.`kind`'))->toBe([])
		->and($this->db()->queriesMatching('AS `extension`'))->toBe([])
		->and($variables['listFixed'])->toBeNull();
});

it('shows the options picked in its filters', function () {
	ElementQueries::respond(Asset::class, []);

	$variables = $this->runAction(FilesController::class, 'index', query: ['kind' => 'pdf,bogus', 'extension' => 'jpg', 'uploader' => 'me'])->variables;

	expect($variables['filter']->kinds)->toBe(['pdf'])
		->and(array_keys($variables['kindOptions']))->toBe(['pdf'])
		->and($variables['extensionOptions'])->toBe(['jpg' => 'jpg'])
		->and(array_keys($variables['uploaderOptions']))->toBe(['me']);
});

it('reads the filters of the files list', function () {
	$filter = $this->runAction(FilesController::class, 'index', query: [
		'q' => 'logo',
		'volume' => 'images,bogus',
		'kind' => 'image,bogus',
		'extension' => 'JPG,we!rd',
		'orientation' => 'portrait',
		'uploader' => '4,me',
		'usage' => 'used',
		'sizeMin' => '0.5',
		'sizeMax' => 'large',
		'widthMin' => '100',
		'heightMax' => '200.9',
		'uploadedFrom' => '2024-01-01',
		'alt' => '1',
		'oversized' => '1',
	])->variables['filter'];

	expect($filter->search)->toBe('logo')
		->and($filter->volumes)->toBe(['images'])
		->and($filter->kinds)->toBe(['image'])
		->and($filter->extensions)->toBe(['jpg'])
		->and($filter->orientations)->toBe(['portrait'])
		->and($filter->uploaders)->toBe(['me', 4])
		->and($filter->usage)->toBe('used')
		->and($filter->sizeMin)->toBe(0.5)
		->and($filter->sizeMax)->toBeNull()
		->and($filter->widthMin)->toBe(100)
		->and($filter->heightMax)->toBe(200)
		->and($filter->uploadedFrom->format('Y-m-d'))->toBe('2024-01-01')
		->and($filter->missingAlt)->toBeTrue()
		->and($filter->oversized)->toBeTrue();
});

it('summarizes the files', function () {
	[$assets, $volumes, $kinds, $alt, $extensions, $uploaders] = loadCards($this->runAction(FilesController::class, 'statistics')->variables['cards']);

	expect($assets['total'])->toBe(5)
		->and(array_column($assets['details'], 'value', 'label')['Size without references'])->toBe(3000)
		->and(array_column($extensions['segments'], 'value', 'label'))->toBe(['JPG' => 4])
		->and($extensions['segments'][0]['url'])->toContain('extension=jpg')
		->and($uploaders['total'])->toBe(0)
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
		->and($variables['isPlayable'])->toBeFalse()
		->and($variables['checks'])->not->toBeEmpty();
});

it('previews videos browsers play natively', function (string $filename, bool $playable) {
	$asset = new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'video', 'filename' => $filename, 'size' => 10]);
	ElementQueries::respond(Asset::class, fn (AssetQuery $query) => [$asset]);

	expect($this->runAction(FilesController::class, 'view', ['id' => 1])->variables['isPlayable'])->toBe($playable);
})->with([
	['a.mp4', true],
	['a.webm', true],
	['a.avi', false],
]);

it('shows the transforms tab of images', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);

	$variables = $this->runAction(FilesController::class, 'view', ['id' => 1, 'tab' => 'transforms'])->variables;

	expect($variables['selectedTab'])->toBe('transforms')
		->and($variables['hasTransforms'])->toBeTrue()
		->and($variables['canDeleteTransforms'])->toBeTrue()
		->and($variables)->not->toHaveKeys(['references', 'referenced', 'sites']);
});

it('only reads the transforms of images on their tab', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);

	$variables = $this->runAction(FilesController::class, 'view', ['id' => 1])->variables;

	expect($variables['transforms'])->toBe([])
		->and($variables['hasTransforms'])->toBeTrue()
		->and($variables)->toHaveKeys(['references', 'referenced', 'sites']);
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

it('previews deleting the selected transforms of a file', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'volumeId' => 1, 'kind' => 'image', 'filename' => 'a.jpg', 'title' => 'A'])]);
	$transform = ['assetId' => '1', 'transformer' => 'other\\Transformer', 'filename' => 'a.jpg', 'format' => null, 'fileExists' => '1', 'inProgress' => '0', 'error' => '0', 'dateIndexed' => null];
	$this->db()->respond('SELECT `id`, `assetId`', [
		['id' => '11', 'transformString' => '_100x100', ...$transform],
		['id' => '12', 'transformString' => '_200x200', ...$transform],
	]);

	$plugins = Mockery::mock(Craft::$app->getPlugins())->makePartial();
	$plugins->shouldReceive('getPlugin')->with('hub')->andReturn($this->hub());
	Craft::$app->set('plugins', $plugins);

	$data = ($this->deleteTransforms)(['assetId' => '1', 'transformIds' => ['12']], 'preview-transforms')->data;

	expect($data['total'])->toBe(1)
		->and($data['html'])->toContain('One transform')
		->and($data['html'])->toContain('_200x200')
		->and($data['html'])->not->toContain('_100x100');
});

it('requires the permission to delete transforms to preview deleting them', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Assets->viewPermission()]);

	($this->deleteTransforms)(['assetId' => '1', 'transformIds' => ['11']], 'preview-transforms');
})->throws(ForbiddenHttpException::class);

it('requires permission to save the file to delete its transforms', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);
	$this->actingAs(canSave: false);

	($this->deleteTransforms)(['assetId' => '1', 'transformIds' => ['11']]);
})->throws(ForbiddenHttpException::class);

it('requires the permission to delete transforms', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Assets->viewPermission(), Hub::PERMISSION_DELETE_ASSETS]);

	($this->deleteTransforms)(['assetId' => '1', 'transformIds' => ['11']]);
})->throws(ForbiddenHttpException::class);

it('requires the permission to download files', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Assets->viewPermission()]);

	$this->runAction(FilesController::class, 'download', ['id' => 1]);
})->throws(ForbiddenHttpException::class);

it('leaves out the download for users who may not download files', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Assets->viewPermission()]);

	expect($this->runAction(FilesController::class, 'index')->variables['canDownload'])->toBeFalse();
});

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

it('fails to download files that do not exist', function () {
	$this->runAction(FilesController::class, 'download', ['id' => 99]);
})->throws(NotFoundHttpException::class);

it('downloads no files the user may not view', function () {
	ElementQueries::respond(Asset::class, [new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg'])]);
	Craft::$app->getElements()->shouldReceive('canView')->andReturn(false);

	$this->runAction(FilesController::class, 'download', ['id' => 1]);
})->throws(ForbiddenHttpException::class);

it('offers the trash as a tab of the assets page', function (string $action) {
	ElementQueries::respond(Asset::class, []);

	expect($this->runAction(FilesController::class, $action)->variables['extraTabs'])->toBe(['trash' => 'Trash']);
})->with(['index', 'statistics', 'diagnostics', 'trash']);

it('leaves out the trash tab while the trash is disabled', function (string $action) {
	ElementQueries::respond(Asset::class, []);
	$this->hub()->getSettings()->enableTrash = false;

	expect($this->runAction(FilesController::class, $action)->variables['extraTabs'])->toBe([]);
})->with(['index', 'statistics', 'diagnostics']);

it('has no trash tab while the trash is disabled', function () {
	$this->hub()->getSettings()->enableTrash = false;

	$this->runAction(FilesController::class, 'trash');
})->throws(NotFoundHttpException::class);

it('only shows the trash to users who may view it', function () {
	ElementQueries::respond(Asset::class, []);
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Page::Assets->viewPermission(), Hub::PERMISSION_DELETE_ASSETS]);

	expect($this->runAction(FilesController::class, 'index')->variables['extraTabs'])->toBe([])
		->and(fn () => $this->runAction(FilesController::class, 'trash'))->toThrow(ForbiddenHttpException::class);
});

it('lists the trashed files', function () {
	$asset = new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg', 'size' => 10, 'keptFile' => true]);
	ElementQueries::respond(Asset::class, [$asset]);

	$response = $this->runAction(FilesController::class, 'trash');
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/files/trash')
		->and($variables['assets'])->toBe([$asset])
		->and($variables['deleters'])->toBe([])
		->and($variables['fileKinds']['image']->getIcon())->toBe('image')
		->and($variables['kindOptions'])->toBe([])
		->and(array_column($variables['volumes'], 'handle'))->toEqualCanonicalizing(['images', 'documents'])
		->and(array_column($variables['columns'], 'key'))->toBe(['volume', 'kind', 'size', 'file', 'deleted', 'deleter'])
		->and($variables['listPath'])->toBe('hub/data/assets/trash')
		->and(array_keys($variables['bulkActions']))->toBe(['export', 'restore', 'restoreWithRelations', 'deletePermanently'])
		->and(ElementQueries::executed(Asset::class)[0]->trashed)->toBeTrue();
});

it('reads the filters of the trashed files list', function () {
	ElementQueries::respond(Asset::class, []);

	$variables = $this->runAction(FilesController::class, 'trash', query: [
		'q' => 'logo',
		'volume' => 'images,bogus',
		'kind' => 'image,bogus',
		'file' => 'kept',
		'deletedFrom' => '2024-01-01',
		'sort' => 'size',
	])->variables;
	$filter = $variables['filter'];

	expect($filter->search)->toBe('logo')
		->and($filter->volumes)->toBe(['images'])
		->and($filter->kinds)->toBe(['image'])
		->and($filter->file)->toBe('kept')
		->and($filter->deletedFrom->format('Y-m-d'))->toBe('2024-01-01')
		->and($variables['filtered'])->toBeTrue()
		->and($variables['sort'])->toBe('size');
});

it('leaves out the options picked in the filters for requests only reading the results', function () {
	$asset = new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg', 'size' => 10]);
	ElementQueries::respond(Asset::class, [$asset]);
	Craft::$app->getRequest()->getHeaders()->set(BaseController::RESULTS_HEADER, '1');

	$variables = $this->runAction(FilesController::class, 'index', query: ['extension' => 'jpg', 'uploader' => 'me'])->variables;
	Craft::$app->getRequest()->getHeaders()->remove(BaseController::RESULTS_HEADER);

	expect($variables['assets'])->toBe([$asset])
		->and($variables['extensionOptions'])->toBe([])
		->and($variables['uploaderOptions'])->toBe([]);
});
