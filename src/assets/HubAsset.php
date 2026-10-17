<?php

namespace SomehowDigital\Craft\Hub\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * Styles and scripts of Hub's own pages.
 */
class HubAsset extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = '@hub/assets/dist';

		$this->depends = [
			CpAsset::class,
		];

		$this->css = [
			'components.css',
			'styles.css',
		];

		$this->js = [
			'scripts.js',
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
				'Are you sure you want to delete “{name}”?',
				'Are you sure you want to remove “{name}” from all items labeled with it? The label is kept.',
				'Couldn’t delete the filter.',
				'Couldn’t save the check.',
				'Couldn’t save the filter.',
				'Not used',
				'The action could not be applied.',
				'The columns could not be saved.',
				'The content could not be loaded.',
				'The label could not be cleared.',
				'The labels could not be loaded.',
				'The labels could not be saved.',
				'The page could not be loaded.',
				'The preview could not be loaded.',
				'The selection could not be saved.',
				'{count} disabled',
				'{count} unused',
			]);
		}
	}
}
