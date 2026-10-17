<?php

namespace SomehowDigital\Craft\Hub\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Styles for the panels Hub adds to native edit pages.
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

		parent::init();
	}
}
