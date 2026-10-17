<?php

use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use SomehowDigital\Craft\Hub\controllers\VolumesController;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->volume(1, 'images', name: 'Images');
	$this->volume(2, 'documents', hasUrls: false, name: 'Documents');
	$this->field(AssetsField::class, 1, 'gallery', ['sources' => '*', 'name' => 'Gallery']);

	$this->db()->respond('FROM {{%assets}} {{assets}}', fn (string $sql) => str_contains($sql, 'GROUP BY `assets`.`volumeId`, `assets`.`kind`') ? [
		['volumeId' => 1, 'kind' => 'image', 'total' => 4, 'used' => 2, 'images' => 4, 'missingAlt' => 1, 'size' => 4000, 'lastUploaded' => '2024-02-01 00:00:00'],
		['volumeId' => 1, 'kind' => 'pdf', 'total' => 1, 'used' => 1, 'images' => 0, 'missingAlt' => 0, 'size' => 1000, 'lastUploaded' => '2024-01-01 00:00:00'],
	] : []);
	$this->db()->respond('FROM {{%relations}} {{relations}}', fn (string $sql) => str_contains($sql, 'GROUP BY `relations`.`fieldId`') ? [
		['fieldId' => 1, 'sectionId' => null, 'volumeId' => 1, 'relations' => 5, 'sources' => 2, 'targets' => 3],
	] : [['elementId' => 1, 'relations' => 2]]);

	$this->countableQueries();
});

it('lists the volumes', function () {
	$response = $this->runAction(VolumesController::class, 'index', query: ['kind' => 'image', 'urls' => 'yes', 'sizeMin' => '0', 'uploadedFrom' => '2024-01-01', 'uploadedTo' => 'yesterday']);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/volumes/index')
		->and(array_keys($variables['volumes']))->toBe([1])
		->and($variables['total'])->toBe(2)
		->and(array_keys($variables['fileKinds']))->toBe(['image', 'pdf'])
		->and($variables['filter']->sizeMin)->toBe(0.0)
		->and($variables['filter']->uploadedFrom->format('Y-m-d H:i'))->toBe('2024-01-01 00:00')
		->and($variables['filter']->uploadedTo)->toBeNull()
		->and($variables['counts']['kinds'])->toBe(['image' => 1, 'pdf' => 1])
		->and($variables['counts']['urls'])->toBe(['yes' => 1, 'no' => 1])
		->and($variables['summary'][0]['segments'][1]['value'])->toBe(1)
		->and($variables['summary'][1]['note'])->toBe('5.0 KB · 5 relations from Assets and Link fields')
		->and(array_column($variables['summary'][2]['segments'], 'value', 'label'))->toBe(['Image' => 4, 'PDF' => 1])
		->and($variables['summary'][3]['segments'][1]['value'])->toBe(1);
});

it('shows a volume', function () {
	$asset = new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg', 'size' => 10]);
	ElementQueries::respond(Asset::class, [$asset]);

	$response = $this->runAction(VolumesController::class, 'view', ['id' => 1], ['list' => 'alt']);
	$variables = $response->variables;
	[$assets, $kinds, $alt, $references] = $variables['summary'];

	expect($response->template)->toBe('hub/pages/volumes/view')
		->and($variables['info']->volume->handle)->toBe('images')
		->and($variables['assets'])->toBe([$asset])
		->and(array_keys($variables['diagnostics']))->toBe([1])
		->and($variables['relationCounts'])->toBe([1 => 2])
		->and($variables['listFixed'])->toBe('volume')
		->and($variables['filter']->volumes)->toBe(['images'])
		->and($assets['note'])->toBe('5.0 KB · 1.0 KB on average')
		->and(array_column($kinds['segments'], 'value', 'label'))->toBe(['Image' => 4, 'PDF' => 1])
		->and($alt['total'])->toBe(4)
		->and(array_column($references['segments'], 'value', 'label'))->toBe(['Gallery' => 5])
		->and($references['note'])->toBe('One Assets field and no Link field can relate to assets from this volume')
		->and($variables['reviewList'])->toBe('alt');
});

it('shows the review lists of volumes', function (?string $list, string $expected, string $order) {
	$variables = $this->runAction(VolumesController::class, 'view', ['id' => 2], ['list' => $list])->variables;

	expect($variables['reviewList'])->toBe($expected)
		->and(array_key_first($variables['reviewQuery']->orderBy))->toBe($order)
		->and($variables['reviewQuery']->volumeId)->toBe(2);
})->with([
	'unused' => [null, 'unused', 'assets.size'],
	'missing alternative text' => ['alt', 'alt', 'elements.dateCreated'],
	'large files' => ['large', 'large', 'assets.size'],
]);

it('fails for volumes that do not exist', function () {
	$this->runAction(VolumesController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);
