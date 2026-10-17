<?php

use craft\fields\Assets as AssetsField;
use craft\fields\Link;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

beforeEach(function () {
	$this->volume(1, 'images', name: 'Images');
	$this->volume(2, 'documents', hasUrls: false, name: 'Documents');
	$this->volume(3, 'archive', hasUrls: false, name: 'Archive');

	$this->field(AssetsField::class, 1, 'gallery', ['sources' => '*']);
	$this->field(AssetsField::class, 2, 'download', ['restrictLocation' => true, 'restrictedLocationSource' => 'volume:volume-documents', 'sources' => '*']);
	$this->field(Link::class, 3, 'link', ['types' => ['asset', 'url']]);
	$this->field(Link::class, 4, 'archiveLink', ['types' => ['asset'], 'typeSettings' => ['asset' => ['sources' => ['volume:volume-archive', 'volume:volume-images']]]]);
	$this->field(Link::class, 5, 'website', ['types' => ['url']]);

	$this->db()->respond('FROM {{%assets}} {{assets}}', [
		['volumeId' => 1, 'kind' => 'pdf', 'total' => 1, 'used' => 1, 'images' => 0, 'missingAlt' => 0, 'size' => 300, 'lastUploaded' => '2024-01-01 00:00:00'],
		['volumeId' => 1, 'kind' => 'image', 'total' => 4, 'used' => 2, 'images' => 4, 'missingAlt' => 1, 'size' => 4000, 'lastUploaded' => '2024-02-01 00:00:00'],
		['volumeId' => 2, 'kind' => 'pdf', 'total' => 2, 'used' => 0, 'images' => 0, 'missingAlt' => 0, 'size' => 500, 'lastUploaded' => null],
		// Assets of volumes that no longer exist are left out.
		['volumeId' => 99, 'kind' => 'video', 'total' => 9, 'used' => 0, 'images' => 0, 'missingAlt' => 0, 'size' => 9, 'lastUploaded' => null],
	]);

	$this->db()->respond('FROM {{%relations}} {{relations}}', [
		['fieldId' => 1, 'sectionId' => null, 'volumeId' => 1, 'relations' => 5, 'sources' => 2, 'targets' => 3],
		['fieldId' => 2, 'sectionId' => null, 'volumeId' => 2, 'relations' => 1, 'sources' => 1, 'targets' => 1],
		['fieldId' => 3, 'sectionId' => null, 'volumeId' => 1, 'relations' => 2, 'sources' => 2, 'targets' => 1],
	]);

	$this->service = $this->hub()->volumes;
});

it('lists volumes by name', function () {
	expect(array_map(fn (VolumeInfo $info) => $info->volume->handle, $this->service->getVolumes()))->toBe([3 => 'archive', 2 => 'documents', 1 => 'images'])
		->and($this->service->getVolume(1)->volume->handle)->toBe('images')
		->and($this->service->getVolume(99))->toBeNull();
});

it('sums the assets of each volume by file kind, largest first', function () {
	$images = $this->service->getVolume(1);

	expect($images->hasUrls)->toBeTrue()
		->and($images->stats->total)->toBe(5)
		->and($images->stats->size)->toBe(4300)
		->and($images->stats->missingAlt)->toBe(1)
		->and($images->stats->lastUploaded->format('Y-m-d'))->toBe('2024-02-01')
		->and($images->getKindValues())->toBe(['image', 'pdf'])
		->and($images->kinds['image']->total)->toBe(4)
		->and($this->service->getVolume(2)->hasUrls)->toBeFalse()
		->and($this->service->getVolume(3)->isEmpty())->toBeTrue();
});

it('sums the file kinds of all volumes, largest first', function () {
	$kinds = $this->service->getFileKinds();

	expect(array_keys($kinds))->toBe(['image', 'pdf'])
		->and($kinds['pdf'])->toBeInstanceOf(FileKindInfo::class)
		->and($kinds['pdf']->stats->total)->toBe(3)
		->and(array_map(fn ($volume) => $volume->handle, $kinds['pdf']->volumes))->toBe(['images', 'documents']);
});

it('finds the fields that can relate to the assets of each volume', function () {
	$handles = fn (array $fields) => array_map(fn ($field) => $field->handle, $fields);

	expect($handles($this->service->getVolume(1)->assetFields))->toBe(['gallery'])
		// Link fields that allow all volumes only allow volumes with URLs.
		->and($handles($this->service->getVolume(1)->linkFields))->toBe(['link', 'archiveLink'])
		->and($handles($this->service->getVolume(2)->assetFields))->toBe(['gallery', 'download'])
		->and($this->service->getVolume(2)->linkFields)->toBe([])
		->and($this->service->getVolume(3)->linkFields)->toBe([]);
});

it('sums the relations into each volume', function () {
	expect($this->service->getVolume(1)->relations->relations)->toBe(7)
		->and($this->service->getVolume(2)->relations->relations)->toBe(1)
		->and($this->service->getVolume(3)->relations->relations)->toBe(0);
});

it('builds the overview once per set of sites', function () {
	$this->site(2, 'de');

	$this->service->getVolumes();
	$this->service->getFileKinds();
	expect($this->db()->queriesMatching('FROM {{%assets}} {{assets}}'))->toHaveCount(1);

	$this->request([Hub::SITE_PARAM => 'de']);
	$this->createPlugin()->volumes->getVolumes();
	expect($this->db()->queriesMatching('FROM {{%assets}} {{assets}}'))->toHaveCount(2);
});
