<?php

use craft\web\assets\cp\CpAsset;
use SomehowDigital\Craft\Hub\assets\HubAsset;
use SomehowDigital\Craft\Hub\assets\SidebarAsset;

it('bundles the styles and scripts of the Hub pages', function () {
	$bundle = new HubAsset();

	expect($bundle->sourcePath)->toBe(Craft::getAlias('@hub/assets/dist'))
		->and($bundle->depends)->toBe([CpAsset::class])
		->and($bundle->css)->toBe(['components.css', 'styles.css'])
		->and($bundle->js)->toBe(['scripts.js']);
});

it('bundles the styles and scripts of the sidebar panels', function () {
	$bundle = new SidebarAsset();

	expect($bundle->sourcePath)->toBe(Craft::getAlias('@hub/assets/dist'))
		->and($bundle->depends)->toBe([CpAsset::class])
		->and($bundle->css)->toBe(['components.css', 'sidebar.css'])
		->and($bundle->js)->toBe(['sidebar.js']);
});

it('ships the files it bundles', function (string $file) {
	expect(Craft::getAlias("@hub/assets/dist/$file"))->toBeFile();
})->with(['components.css', 'styles.css', 'scripts.js', 'sidebar.css', 'sidebar.js']);
