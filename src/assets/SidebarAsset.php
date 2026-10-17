<?php

namespace SomehowDigital\Craft\Hub\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * Styles and scripts of the panels Hub adds to native edit pages.
 */
class SidebarAsset extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = '@hub/assets/dist';

		$this->depends = [
			CpAsset::class,
		];

		$this->css = [
			'components.css',
			'sidebar.css',
		];

		$this->js = [
			'sidebar.js',
		];

		parent::init();
	}

	/**
	 * Registers the messages its scripts translate, which Craft only hands to the browser by name.
	 */
	public function registerAssetFiles($view): void
	{
		parent::registerAssetFiles($view);

		if ($view instanceof View) {
			$view->registerTranslations('hub', [
				'The labels could not be saved.',
			]);
		}
	}
}
