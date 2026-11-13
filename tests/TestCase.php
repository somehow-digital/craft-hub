<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\Tests;

use Closure;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\fs\Local;
use craft\helpers\ArrayHelper;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\models\FieldLayout;
use craft\models\UserGroup;
use craft\services\Config;
use craft\services\Elements;
use craft\web\Application;
use LogicException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionMethod;
use ReflectionProperty;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\models\DeletionItem;
use SomehowDigital\Craft\Hub\models\DeletionPreview;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\DeletionService;
use SomehowDigital\Craft\Hub\services\DiagnosticsService;
use SomehowDigital\Craft\Hub\services\EditService;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\FilterService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\services\MaintainerService;
use SomehowDigital\Craft\Hub\services\QueryService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use SomehowDigital\Craft\Hub\services\ReviewService;
use SomehowDigital\Craft\Hub\services\StatisticsService;
use SomehowDigital\Craft\Hub\services\StructureService;
use SomehowDigital\Craft\Hub\services\UserService;
use SomehowDigital\Craft\Hub\services\VolumeService;
use SomehowDigital\Craft\Hub\services\WorkService;
use SomehowDigital\Craft\Hub\Tests\Support\ContentModel;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use SomehowDigital\Craft\Hub\Tests\Support\FakeConnection;
use SomehowDigital\Craft\Hub\Tests\Support\FakeQueryService;
use SomehowDigital\Craft\Hub\Tests\Support\InteractsWithCraft;
use SomehowDigital\Craft\Hub\Tests\Support\RunsControllers;
use yii\base\Event;
use yii\caching\ArrayCache;
use yii\di\Container;
use yii\di\ServiceLocator;
use yii\web\Response;

/**
 * Boots a fresh Craft app for each test, without a database: queries are answered by a {@see FakeConnection},
 * element queries by {@see ElementQueries}, counted by a {@see FakeQueryService}, and Craft services are mocked as needed.
 *
 * @property CheckService $checks
 * @property ColumnService $columns
 * @property DeletionService $deletion
 * @property DiagnosticsService $diagnostics
 * @property EditService $edits
 * @property EntryService $entries
 * @property FileService $files
 * @property FilterService $filters
 * @property LabelService $labels
 * @property ListService $lists
 * @property MaintainerService $maintainers
 * @property QueryService $queries
 * @property ReferenceService $references
 * @property ReviewService $review
 * @property StatisticsService $statistics
 * @property StructureService $structure
 * @property UserService $users
 * @property VolumeService $volumeService
 * @property WorkService $work
 * @property User|null $identity
 * @property CheckDefinition $definition
 * @property User $jane
 * @property User $john
 * @property UserGroup $editors
 * @property Entry $target
 * @property FieldInterface $related
 * @property FieldInterface $text
 * @property FieldLayout $layout
 * @property string $textUid
 * @property string $relatedUid
 * @property list<array<mixed>> $recorded
 * @property Closure(): list<string> $recordedEdits
 * @property Closure(): void $track
 * @property Closure(object, bool=): mixed $saved
 * @property Closure(object, bool=): mixed $deleting
 * @property Closure(object): mixed $deleted
 * @property Closure(DeletionItem): list<string> $groupLabels
 * @property Closure(array<string,mixed>): array<mixed> $save
 * @property Closure(array<string,mixed>, string=): Response $deleteTransforms
 * @property Closure(array<string,mixed>): array<string,mixed> $row
 * @property Closure(DeletionPreview, string): list<string> $effects
 * @property Closure(int, array<mixed>=): Entry $entry
 * @property Closure(ElementQuery<array-key,covariant ElementInterface>): string $sql
 * @property Closure(Snapshot): int $sectionCount
 * @property Elements&MockInterface $elementsMock
 * @property Local&MockInterface $fsMock
 */
abstract class TestCase extends BaseTestCase
{
	use ContentModel;
	use InteractsWithCraft;
	use MockeryPHPUnitIntegration;
	use RunsControllers;

	/** @var array<string,mixed> */
	private array $dynamic = [];

	/** @var array<string,object> services of the plugin the test replaced, by their component ID */
	private array $replaced = [];

	// What the tests assign to $this is kept here, which also makes PHPStan honor the @property tags above.
	public function &__get(string $name): mixed
	{
		if (!array_key_exists($name, $this->dynamic)) {
			throw new LogicException("Undefined test property {$name}.");
		}

		return $this->dynamic[$name];
	}

	public function __set(string $name, mixed $value): void
	{
		$this->dynamic[$name] = $value;
	}

	public function __isset(string $name): bool
	{
		return array_key_exists($name, $this->dynamic);
	}

	protected function setUp(): void
	{
		parent::setUp();

		$this->createApp();
	}

	protected function tearDown(): void
	{
		Event::offAll();
		ElementQueries::reset();
		// Craft's Db helper keeps the connection it first used, which is the app's of an earlier test otherwise.
		Closure::bind(static fn(): null => Db::$_db = null, null, Db::class)();
		// Craft's CP helper keeps the site that the first request asked for.
		Closure::bind(static fn(): null => Cp::$_requestedSite = null, null, Cp::class)();
		(new ReflectionProperty(Craft::class, 'app'))->setValue(null, null);
		Craft::$container = new Container();

		parent::tearDown();
	}

	/**
	 * @param string $driver `mysql` or `pgsql`
	 */
	protected function createApp(string $driver = 'mysql'): Application
	{
		// The services the container shares belong to the app that created them.
		Craft::$container = new Container();
		$this->replaced = [];

		$src = CRAFT_VENDOR_PATH . '/craftcms/cms/src';

		$configService = new Config();
		$configService->appType = 'web';
		$configService->env = 'test';
		$configService->configDir = Craft::getAlias('@config');
		$configService->appDefaultsDir = "{$src}/config/defaults";

		$config = ArrayHelper::merge(
			['vendorPath' => CRAFT_VENDOR_PATH, 'env' => 'test', 'components' => ['config' => $configService]],
			require "{$src}/config/app.php",
			require "{$src}/config/app.web.php",
			[
				'isInstalled' => false,
				'components' => [
					'db' => [
						'class' => FakeConnection::class,
						'dsn' => "{$driver}:host=localhost;dbname=craft",
						'driverName' => $driver,
					],
					'log' => ['targets' => []],
					'cache' => ['class' => ArrayCache::class],
				],
			],
		);

		unset($config['class']);
		$app = new Application($config);

		// Tests run on the command line, which requests take for console requests.
		$app->getRequest()->setIsConsoleRequest(false);

		$this->mockCraftServices();
		$this->createPlugin();

		return $app;
	}

	protected function createPlugin(): Hub
	{
		$hub = new Hub('hub', Craft::$app, [
			'basePath' => dirname(__DIR__) . '/src',
			...Hub::config(),
		]);

		// Element queries aren't prepared, so they are counted without the rows of their subquery.
		Craft::$container->setSingleton(QueryService::class, static fn(): QueryService => FakeQueryService::make());

		Hub::setInstance($hub);
		Craft::$app->setModule('hub', $hub);

		// What the plugin created while it started was made before the element queries were faked.
		$this->resetServices();

		return $hub;
	}

	/**
	 * Makes the current request a request with the given query params, to the control panel unless said otherwise.
	 *
	 * @param array<mixed> $params
	 */
	protected function request(array $params = [], bool $cp = true, string $path = ''): void
	{
		$request = webRequest();
		$request->setQueryParams($params);
		$request->setIsCpRequest($cp);

		// The path is resolved when the request is created.
		(function () use ($path): void {
			$this->_path = $path;
			$this->_segments = $path !== '' ? explode('/', $path) : [];
		})->call($request);
	}

	protected function hub(): Hub
	{
		return Hub::getInstance();
	}

	public function db(): FakeConnection
	{
		/** @var FakeConnection */
		return Craft::$app->getDb();
	}

	/**
	 * Creates the services of the plugin anew, for what they remember of earlier reads.
	 */
	public function resetServices(): void
	{
		(function (): void {
			$this->define();
		})->call($this->hub());

		// The components are created again from their definitions.
		Closure::bind(function (): void {
			$this->_components = [];
		}, $this->hub(), ServiceLocator::class)();

		Craft::$container->setSingleton(QueryService::class, static fn(): QueryService => FakeQueryService::make());

		/** @var array<string,array{class:class-string}> $components */
		$components = Hub::config()['components'];

		foreach ($this->replaced as $id => $service) {
			$this->hub()->set($id, $service);
			Craft::$container->setSingleton($components[$id]['class'], static fn(): object => $service);
		}
	}

	/**
	 * Replaces a service of the plugin, and so the services created afterwards with it.
	 */
	protected function replaceService(string $id, object $service): void
	{
		$this->replaced[$id] = $service;
		$this->resetServices();
	}

	/**
	 * What the plugin registers, created with the services the test replaced; Hub keeps these as protected methods.
	 *
	 * @template T of object
	 * @param class-string<T> $handler
	 * @return T
	 */
	protected function handler(string $method, string $handler): object
	{
		$created = (new ReflectionMethod(Hub::class, $method))->invoke($this->hub());
		if (!$created instanceof $handler) {
			throw new LogicException("Not a {$handler}.");
		}

		return $created;
	}

	/**
	 * Registers the tracking of activities again, in place of the one registered with the services of the plugin.
	 */
	protected function trackActivity(): void
	{
		Event::offAll();
		(new ReflectionMethod(Hub::class, 'registerActivityTracking'))->invoke($this->hub());
	}

	/**
	 * Replaces a component of the app, or of the plugin when the ID is prefixed with `hub.`, with a mock.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return MockInterface&T
	 */
	public function mockComponent(string $id, string $class): MockInterface
	{
		/** @var T&MockInterface $mock */
		$mock = Mockery::mock($class);

		if (str_starts_with($id, 'hub.')) {
			$this->replaceService(substr($id, 4), $mock);
		} else {
			Craft::$app->set($id, $mock);
		}

		return $mock;
	}
}
