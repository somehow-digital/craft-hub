<?php

declare(strict_types=1);

use craft\base\Plugin;
use craft\base\Widget;
use craft\console\Controller as ConsoleController;
use craft\queue\BaseJob;
use craft\web\AssetBundle;
use craft\web\Controller as WebController;
use Pest\Preset;
use yii\base\Component;

/** The namespace of the plugin, and of its tests, which may debug and assert. */
const PLUGIN_NAMESPACE = 'SomehowDigital\Craft\Hub';
const TESTS_NAMESPACE = PLUGIN_NAMESPACE . '\Tests';

/** The namespaces of the components of the plugin, which other namespaces may not depend on. */
const CONTROLLERS_NAMESPACE = PLUGIN_NAMESPACE . '\controllers';
const ASSETS_NAMESPACE = PLUGIN_NAMESPACE . '\assets';
const WIDGETS_NAMESPACE = PLUGIN_NAMESPACE . '\widgets';
const CONSOLE_NAMESPACE = PLUGIN_NAMESPACE . '\console';

arch('it does not debug or print', function (): void {
	(new Preset())->php()->ignoring(TESTS_NAMESPACE);
});

arch('it does not use insecure functions', function (): void {
	(new Preset())->security()->ignoring(TESTS_NAMESPACE);
});

arch('it does not sleep', function (): void {
	expect(['sleep', 'usleep'])->not->toBeUsed();
});

arch('it reads the environment through Craft', function (): void {
	expect(['getenv', 'putenv'])->not->toBeUsed()->ignoring(TESTS_NAMESPACE);
});

arch('it declares strict types', function (): void {
	expect(PLUGIN_NAMESPACE)->toUseStrictTypes();
});

arch('it compares strictly', function (): void {
	// The trash lists compare their filter with the default one by value, which holds the dates of the filter as objects.
	expect(PLUGIN_NAMESPACE)->toUseStrictEquality()->ignoring([
		CONTROLLERS_NAMESPACE . '\EntriesController',
		CONTROLLERS_NAMESPACE . '\FilesController',
	]);
});

arch('it does not depend on the tests', function (): void {
	expect(PLUGIN_NAMESPACE)->not->toUse(TESTS_NAMESPACE)->ignoring(TESTS_NAMESPACE);
});

arch('the plugin extends the Craft base', function (): void {
	expect(PLUGIN_NAMESPACE . '\Hub')->toExtend(Plugin::class);
});

arch('web controllers are Craft controllers', function (): void {
	// The variables of the lists are shared by the controllers of the lists as a trait.
	expect(CONTROLLERS_NAMESPACE)
		->toExtend(WebController::class)
		->ignoring(CONTROLLERS_NAMESPACE . '\ListVariables');
	expect(CONTROLLERS_NAMESPACE)
		->toHaveSuffix('Controller')
		->ignoring(CONTROLLERS_NAMESPACE . '\ListVariables');
});

arch('console controllers are Craft console controllers', function (): void {
	expect(CONSOLE_NAMESPACE . '\controllers')
		->toExtend(ConsoleController::class)
		->toHaveSuffix('Controller');
});

arch('asset bundles are Craft asset bundles', function (): void {
	expect(ASSETS_NAMESPACE)
		->toExtend(AssetBundle::class)
		->toHaveSuffix('Asset');
});

arch('services are Yii components', function (): void {
	expect(PLUGIN_NAMESPACE . '\services')
		->toExtend(Component::class)
		->toHaveSuffix('Service');
});

arch('jobs are Craft queue jobs', function (): void {
	expect(PLUGIN_NAMESPACE . '\jobs')->toExtend(BaseJob::class);
});

arch('widgets are Craft widgets', function (): void {
	expect(WIDGETS_NAMESPACE)->toExtend(Widget::class);
});

arch('helpers are final classes', function (): void {
	expect(PLUGIN_NAMESPACE . '\helpers')
		->toBeClasses()
		->toBeFinal();
});

arch('the enums namespace holds enums', function (): void {
	expect(PLUGIN_NAMESPACE . '\enums')->toBeEnums();
});

arch('enums are backed by strings', function (): void {
	expect(PLUGIN_NAMESPACE)->enums()->toBeStringBackedEnums()->ignoring(TESTS_NAMESPACE);
});

arch('services do not depend on the controllers or the control panel', function (): void {
	expect(PLUGIN_NAMESPACE . '\services')->not->toUse([
		CONTROLLERS_NAMESPACE,
		CONSOLE_NAMESPACE,
		ASSETS_NAMESPACE,
		WIDGETS_NAMESPACE,
	]);
});

arch('helpers, models and enums do not depend on the components of the plugin', function (): void {
	expect([PLUGIN_NAMESPACE . '\helpers', PLUGIN_NAMESPACE . '\models', PLUGIN_NAMESPACE . '\enums'])->not->toUse([
		CONTROLLERS_NAMESPACE,
		CONSOLE_NAMESPACE,
		PLUGIN_NAMESPACE . '\handlers',
		PLUGIN_NAMESPACE . '\jobs',
		ASSETS_NAMESPACE,
		WIDGETS_NAMESPACE,
	]);
});
