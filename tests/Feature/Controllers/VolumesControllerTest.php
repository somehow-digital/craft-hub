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
		['volumeId' => 1, 'kind' => 'image', 'total' => 4, 'used' => 2, 'images' => 4, 'missingAlt' => 1, 'size' => 4000, 'unusedSize' => 0, 'lastUploaded' => '2024-02-01 00:00:00'],
		['volumeId' => 1, 'kind' => 'pdf', 'total' => 1, 'used' => 1, 'images' => 0, 'missingAlt' => 0, 'size' => 1000, 'unusedSize' => 0, 'lastUploaded' => '2024-01-01 00:00:00'],
	] : []);
	$this->db()->respond('FROM {{%relations}} {{relations}}', fn (string $sql) => str_contains($sql, 'GROUP BY `relations`.`fieldId`') ? [
		['fieldId' => 1, 'sectionId' => null, 'volumeId' => 1, 'references' => 5, 'sources' => 2, 'targets' => 3],
	] : [['elementId' => 1, 'references' => 2]]);
});

it('lists the volumes', function () {
	$response = $this->runAction(VolumesController::class, 'index', query: ['kind' => 'image', 'urls' => 'yes', 'sizeMin' => '0', 'uploadedFrom' => '2024-01-01', 'uploadedTo' => 'yesterday']);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/volumes/index')
		->and(array_keys($variables['volumes']))->toBe([1])
		->and($variables['total'])->toBe(2)
		->and($variables['pageInfo']->total)->toBe(1)
		->and($variables['pageInfo']->totalPages)->toBe(1)
		->and(array_keys($variables['fileKinds']))->toBe(['image', 'pdf'])
		->and($variables['filter']->sizeMin)->toBe(0.0)
		->and($variables['filter']->uploadedFrom->format('Y-m-d H:i'))->toBe('2024-01-01 00:00')
		->and($variables['filter']->uploadedTo)->toBeNull()
		->and($variables)->not->toHaveKey('summary');
});

it('summarizes the volumes on their statistics tab', function () {
	$cards = loadCards($this->runAction(VolumesController::class, 'statistics')->variables['cards']);

	expect($cards[0]['segments'][1]['value'])->toBe(1)
		->and($cards[1]['note'])->toBe('5 references from Assets and Link fields')
		->and($cards[1]['details'][0]['value'])->toBe(5000)
		->and(array_column($cards[0]['table']['rows'], 'value', 'label'))->toBe(['Images' => 5, 'Documents' => 0])
		->and(array_column($cards[2]['segments'], 'value', 'label'))->toBe(['Image' => 4, 'PDF' => 1])
		->and($cards[3]['segments'][1]['value'])->toBe(1);
});

it('has no statistics tab while statistics are disabled', function () {
	$this->hub()->getSettings()->enableStatistics = false;

	try {
		$this->runAction(VolumesController::class, 'statistics');
	} finally {
		$this->hub()->getSettings()->enableStatistics = true;
	}
})->throws(NotFoundHttpException::class);

it('tells the templates whether statistics are enabled', function () {
	$this->hub()->getSettings()->enableStatistics = false;

	try {
		$variables = $this->runAction(VolumesController::class, 'view', ['id' => 1])->variables;
	} finally {
		$this->hub()->getSettings()->enableStatistics = true;
	}

	expect($variables['statisticsEnabled'])->toBeFalse()
		->and($this->runAction(VolumesController::class, 'index')->variables['statisticsEnabled'])->toBeTrue();
});

it('shows a volume', function () {
	$response = $this->runAction(VolumesController::class, 'view', ['id' => 1]);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/volumes/view')
		->and($variables['selectedTab'])->toBe('overview')
		->and($variables['info']->volume->handle)->toBe('images')
		->and($variables)->not->toHaveKeys(['summary', 'assets', 'reviewList']);
});

it('summarizes a volume on its statistics tab', function () {
	$variables = $this->runAction(VolumesController::class, 'view', ['id' => 1, 'tab' => 'statistics'])->variables;
	[$assets, $kinds, $alt, $references] = loadCards($variables['summary']);

	expect($variables['selectedTab'])->toBe('statistics')
		->and($assets['note'])->toBeNull()
		->and(array_column($assets['details'], 'value', 'label'))->toMatchArray(['Total size' => 5000, 'Average size' => 1000])
		->and(array_column($kinds['segments'], 'value', 'label'))->toBe(['Image' => 4, 'PDF' => 1])
		->and($alt['total'])->toBe(4)
		->and(array_column($references['segments'], 'value', 'label'))->toBe(['Gallery' => 5])
		->and($references['note'])->toBe('One Assets field and no Link field can relate to assets from this volume')
		->and($variables)->not->toHaveKeys(['assets', 'reviewList']);
});

it('lists the assets of a volume on a tab of its own', function () {
	$asset = new Asset(['id' => 1, 'siteId' => 1, 'kind' => 'image', 'filename' => 'a.jpg', 'size' => 10]);
	ElementQueries::respond(Asset::class, [$asset]);

	$variables = $this->runAction(VolumesController::class, 'view', ['id' => 1, 'tab' => 'assets'])->variables;

	expect($variables['selectedTab'])->toBe('assets')
		->and($variables['assets'])->toBe([$asset])
		->and($variables['referenceCounts'])->toBe([1 => 2])
		->and($variables['listFixed'])->toBe('volume')
		->and($variables['listPath'])->toBe('hub/structure/volumes/1/assets')
		->and($variables['filter']->volumes)->toBe(['images'])
		->and($variables)->not->toHaveKeys(['summary', 'reviewList']);
});

it('shows the checks of volumes on their diagnostics tab', function () {
	$variables = $this->runAction(VolumesController::class, 'view', ['id' => 2, 'tab' => 'diagnostics'])->variables;

	expect(array_map(fn ($check) => $check->key, $variables['checks']))->toContain('volumes.empty', 'assets.unused')
		->and($variables)->not->toHaveKeys(['summary', 'reviewList']);
});

it('fails for volumes that do not exist', function () {
	$this->runAction(VolumesController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);
