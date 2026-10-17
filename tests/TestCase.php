<?php

namespace SomehowDigital\Craft\Hub\Tests;

use Craft;
use Closure;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\services\Config;
use craft\web\Application;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase as BaseTestCase;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\Tests\Support\ContentModel;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use SomehowDigital\Craft\Hub\Tests\Support\FakeConnection;
use SomehowDigital\Craft\Hub\Tests\Support\FakeQueryService;
use SomehowDigital\Craft\Hub\Tests\Support\InteractsWithCraft;
use SomehowDigital\Craft\Hub\Tests\Support\RunsControllers;
use yii\base\Event;
use yii\caching\ArrayCache;
use yii\di\Container;

/**
 * Boots a fresh Craft app for each test, without a database: queries are answered by a {@see FakeConnection},
 * element queries by {@see ElementQueries}, counted by a {@see FakeQueryService}, and Craft services are mocked as needed.
 */
abstract class TestCase extends BaseTestCase
{
	use ContentModel;
	use InteractsWithCraft;
	use MockeryPHPUnitIntegration;
	use RunsControllers;

	protected function setUp(): void
	{
		parent::setUp();

		Craft::$container = new Container();

		$this->createApp();
	}

	protected function tearDown(): void
	{
		Event::offAll();
		ElementQueries::reset();
		// Craft's Db helper keeps the connection it first used, which is the app's of an earlier test otherwise.
		Closure::bind(static fn () => Db::$_db = null, null, Db::class)();
		Craft::$app = null;
		Craft::$container = new Container();

		parent::tearDown();
	}

	/**
	 * @param string $driver `mysql` or `pgsql`
	 */
	protected function createApp(string $driver = 'mysql'): Application
	{
		$src = CRAFT_VENDOR_PATH . '/craftcms/cms/src';

		$configService = new Config();
		$configService->appType = 'web';
		$configService->env = 'test';
		$configService->configDir = Craft::getAlias('@config');
		$configService->appDefaultsDir = "$src/config/defaults";

		$config = ArrayHelper::merge(
			['vendorPath' => CRAFT_VENDOR_PATH, 'env' => 'test', 'components' => ['config' => $configService]],
			require "$src/config/app.php",
			require "$src/config/app.web.php",
			[
				'isInstalled' => false,
				'components' => [
					'db' => [
						'class' => FakeConnection::class,
						'dsn' => "$driver:host=localhost;dbname=craft",
						'driverName' => $driver,
					],
					'log' => ['targets' => []],
					'cache' => ['class' => ArrayCache::class],
				],
			],
		);

		/** @var Application $app */
		$app = Craft::createObject($config);

		// Tests run on the command line, which requests take for console requests.
		$app->getRequest()->setIsConsoleRequest(false);

		$this->mockCraftServices();
		$this->createPlugin();

		return $app;
	}

	protected function createPlugin(): Hub
	{
		/** @var Hub $hub */
		$hub = Craft::createObject([
			'class' => Hub::class,
			'basePath' => dirname(__DIR__) . '/src',
			...Hub::config(),
		], ['hub', Craft::$app]);

		// Element queries aren't prepared, so they are counted without the rows of their subquery.
		$hub->set('queries', FakeQueryService::class);

		Hub::setInstance($hub);
		Craft::$app->setModule('hub', $hub);

		return $hub;
	}

	/**
	 * Makes the current request a request with the given query params, to the control panel unless said otherwise.
	 *
	 * @param array<string,mixed> $params
	 */
	protected function request(array $params = [], bool $cp = true, string $path = ''): void
	{
		$request = Craft::$app->getRequest();
		$request->setQueryParams($params);
		$request->setIsCpRequest($cp);

		// The path is resolved when the request is created.
		(function () use ($path) {
			$this->_path = $path;
			$this->_segments = $path !== '' ? explode('/', $path) : [];
		})->call($request);
	}

	protected function hub(): Hub
	{
		return Hub::getInstance();
	}

	protected function db(): FakeConnection
	{
		/** @var FakeConnection */
		return Craft::$app->getDb();
	}

	/**
	 * Replaces a component of the app, or of the plugin when the ID is prefixed with `hub.`, with a mock.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return MockInterface&T
	 */
	protected function mockComponent(string $id, string $class): MockInterface
	{
		$mock = Mockery::mock($class);

		if (str_starts_with($id, 'hub.')) {
			$this->hub()->set(substr($id, 4), $mock);
		} else {
			Craft::$app->set($id, $mock);
		}

		return $mock;
	}
}
