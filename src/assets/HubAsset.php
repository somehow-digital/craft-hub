<?php

namespace SomehowDigital\Craft\Hub\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

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
}
