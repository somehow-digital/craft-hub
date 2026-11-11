<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\Tests\PHPStan;

use craft\base\Model;
use craft\base\Plugin;
use craft\base\Widget;
use craft\console\Controller as ConsoleController;
use craft\db\Migration;
use craft\elements\db\ElementQuery;
use craft\queue\BaseJob;
use craft\web\AssetBundle;
use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use yii\base\Component;

/**
 * What the classes of each layer extend and how they are named. Dependencies between layers are in deptrac.yaml.
 */
final class ArchitectureRules
{
	private const PLUGIN = 'SomehowDigital\Craft\Hub';

	private const TESTS = self::PLUGIN . '\Tests';

	/**
	 * Layer namespaces with their base class and name suffix.
	 *
	 * Unlike Craft, layers have suffixes where their classes are alike, so names say what a class is.
	 * Nested layer namespaces are excluded from their parent's rules.
	 *
	 * @var array<non-empty-string,array{base:?class-string,suffix:?non-empty-string}>
	 */
	private const LAYERS = [
		'assets' => ['base' => AssetBundle::class, 'suffix' => 'Asset'],
		'base' => ['base' => null, 'suffix' => null],
		'bulk' => ['base' => null, 'suffix' => null],
		'conditions' => ['base' => null, 'suffix' => null],
		'conditions\rules' => ['base' => null, 'suffix' => 'ConditionRule'],
		'console\controllers' => ['base' => ConsoleController::class, 'suffix' => 'Controller'],
		'controllers' => ['base' => self::PLUGIN . '\controllers\BaseController', 'suffix' => 'Controller'],
		'db' => ['base' => null, 'suffix' => null],
		'elements\db' => ['base' => ElementQuery::class, 'suffix' => 'Query'],
		'enums' => ['base' => null, 'suffix' => null],
		'handlers' => ['base' => null, 'suffix' => null],
		'handlers\activity' => ['base' => null, 'suffix' => null],
		'helpers' => ['base' => null, 'suffix' => null],
		'jobs' => ['base' => BaseJob::class, 'suffix' => 'Job'],
		'migrations' => ['base' => Migration::class, 'suffix' => null],
		'models' => ['base' => null, 'suffix' => null],
		'services' => ['base' => Component::class, 'suffix' => 'Service'],
		'statistics' => ['base' => null, 'suffix' => 'Summary'],
		'widgets' => ['base' => Widget::class, 'suffix' => 'Widget'],
	];

	/** Created by Yii from config, so the plugin registers rather than constructs them. */
	private const COMPONENTS = [
		'assets',
		'console\controllers',
		'controllers',
		'migrations',
		'services',
		'widgets',
	];

	/**
	 * Layer classes extend their Craft or Yii base class and carry its suffix.
	 *
	 * @return iterable<string,Rule>
	 */
	#[TestRule]
	public function layerClassesExtendTheirBaseAndCarryItsName(): iterable
	{
		foreach (self::LAYERS as $namespace => ['base' => $base, 'suffix' => $suffix]) {
			if ($base !== null) {
				yield " ({$namespace} extend)" => PHPat::rule()
					->classes($this->layer($namespace))
					->excluding(Selector::isAbstract(), Selector::isInterface(), Selector::isTrait(), Selector::isEnum(), ...$this->nestedIn($namespace))
					->should()->extend()
					->classes(Selector::classname($base))
					->because("The classes of {$namespace} are {$base} classes, which Craft and Yii know how to create and run.");
			}

			if ($suffix !== null) {
				yield " ({$namespace} named)" => PHPat::rule()
					->classes($this->layer($namespace))
					->excluding(Selector::isInterface(), Selector::isTrait(), Selector::isEnum(), ...$this->nestedIn($namespace))
					->should()->beNamed('/' . $suffix . '$/', true)
					->because("The name of a class of {$namespace} ends with {$suffix}, so it tells what it is wherever it is used.");
			}
		}
	}

	#[TestRule]
	public function theRootHoldsOnlyThePlugin(): Rule
	{
		return PHPat::rule()
			->classes($this->root())
			->excluding(Selector::classname(self::PLUGIN . '\Hub'))
			->shouldNot()->exist()
			->because('Craft loads the plugin by its class at the root, where it never moves.');
	}

	#[TestRule]
	public function thePluginIsACraftPlugin(): Rule
	{
		return PHPat::rule()
			->classes(Selector::classname(self::PLUGIN . '\Hub'))
			->should()->extend()
			->classes(Selector::classname(Plugin::class))
			->because('The plugin is the class that Craft loads the plugin through.');
	}

	#[TestRule]
	public function everyClassLivesInTheNamespaceOfALayer(): Rule
	{
		return PHPat::rule()
			->classes($this->plugin())
			->excluding($this->root(), ...array_map($this->layer(...), array_keys(self::LAYERS)))
			->shouldNot()->exist()
			->because('A class belongs to a layer, whose namespace deptrac.yaml defines the dependencies of.');
	}

	#[TestRule]
	public function concreteClassesAreFinal(): Rule
	{
		return PHPat::rule()
			->classes($this->plugin())
			->excluding(Selector::isAbstract(), Selector::isInterface(), Selector::isTrait(), Selector::isEnum(), Selector::extends(\yii\console\Controller::class))
			->should()->beFinal()
			->because('Classes are composed rather than extended, so a change to one cannot break a class extending it. Tests replace what a class depends on at the boundaries (the database, queries and the services of Craft), not the classes of the plugin; only the console controllers are left open, as Yii writes their output straight to STDOUT, which tests silence by replacing the method.');
	}

	#[TestRule]
	public function modelsAreCraftModelsOrImmutableValueObjects(): Rule
	{
		return PHPat::rule()
			->classes($this->layer('models'))
			->excluding(Selector::extends(Model::class))
			->should()->beReadonly()
			->because('A model either validates input as a Craft model, or holds data that nothing changes once it is created.');
	}

	#[TestRule]
	public function interfacesLiveInTheBaseNamespace(): Rule
	{
		return PHPat::rule()
			->classes(Selector::AllOf($this->plugin(), Selector::isInterface()))
			->excluding($this->layer('base'))
			->shouldNot()->exist()
			->because('The interfaces are the ports that the services depend on and the infrastructure implements, so they are in one place, as craft\base is for Craft.');
	}

	#[TestRule]
	public function abstractClassesAreNamedBase(): Rule
	{
		return PHPat::rule()
			->classes(Selector::AllOf($this->plugin(), Selector::isAbstract(), Selector::Not(Selector::isInterface()), Selector::Not(Selector::isTrait())))
			->should()->beNamed('/\\\Base[A-Z]\w*$/', true)
			->because('Craft names its abstract classes so, such as BaseJob, and keeps them in the namespace of the classes extending them.');
	}

	#[TestRule]
	public function theBaseNamespaceHoldsOnlyAbstractions(): Rule
	{
		return PHPat::rule()
			->classes($this->layer('base'))
			->excluding(Selector::isAbstract(), Selector::isInterface(), Selector::isTrait())
			->shouldNot()->exist()
			->because('The base namespace holds what other classes implement, extend or include, not what they use.');
	}

	#[TestRule]
	public function interfacesAreNamedInterface(): Rule
	{
		return PHPat::rule()
			->classes(Selector::AllOf($this->plugin(), Selector::isInterface()))
			->should()->beNamed('/Interface$/', true)
			->because('Craft names its interfaces so, which keeps them apart from the classes implementing them.');
	}

	#[TestRule]
	public function traitsAreNamedTrait(): Rule
	{
		return PHPat::rule()
			->classes(Selector::AllOf($this->plugin(), Selector::isTrait()))
			->should()->beNamed('/Trait$/', true)
			->because('Craft names its traits so, which keeps them apart from the classes including them.');
	}

	#[TestRule]
	public function enumsLiveInTheEnumsNamespace(): Rule
	{
		return PHPat::rule()
			->classes(Selector::AllOf($this->plugin(), Selector::isEnum()))
			->excluding($this->layer('enums'))
			->shouldNot()->exist()
			->because('Enums are shared by every layer, so they are a leaf that depends on nothing.');
	}

	#[TestRule]
	public function theEnumsNamespaceHoldsOnlyEnums(): Rule
	{
		return PHPat::rule()
			->classes($this->layer('enums'))
			->should()->beEnum()
			->because('A set of constants is an enum, so its values are typed and listed in one place.');
	}

	#[TestRule]
	public function exceptionsLiveInTheErrorsNamespace(): Rule
	{
		return PHPat::rule()
			->classes(Selector::AllOf($this->plugin(), Selector::isThrowable()))
			->excluding($this->layer('errors'))
			->shouldNot()->exist()
			->because('Exceptions are shared by every layer, as craft\errors is for Craft.');
	}

	#[TestRule]
	public function migrationsAreNamedAsCraftCreatesThem(): Rule
	{
		return PHPat::rule()
			->classes($this->layer('migrations'))
			->should()->beNamed('/\\\(Install|m\d{6}_\d{6}_[a-z0-9_]+)$/', true)
			->because('Craft runs the migrations in the order of their names, which `craft make migration` creates.');
	}

	#[TestRule]
	public function helpersAreNotConstructed(): Rule
	{
		return PHPat::rule()
			->classes(Selector::all())
			->shouldNot()->construct()
			->classes($this->layer('helpers'))
			->because('Helpers are stateless functions, which are called statically, as Craft does with its helpers.');
	}

	#[TestRule]
	public function componentsAreNotConstructed(): Rule
	{
		return PHPat::rule()
			->classes(Selector::AllOf(Selector::all(), Selector::Not($this->root()), Selector::Not(Selector::inNamespace(self::TESTS))))
			->shouldNot()->construct()
			->classes(...array_map($this->layer(...), self::COMPONENTS))
			->because('The plugin wires the components, which are shared and configurable, rather than whoever uses them constructing their own; tests build them over fakes.');
	}

	#[TestRule]
	public function replaceableComponentsAreUsedByTheirBase(): Rule
	{
		return PHPat::rule()
			->classes($this->plugin())
			->shouldNot()->dependOn()
			->classes(
				Selector::classname('craft\\web\\Session'),
				Selector::classname('craft\\queue\\Queue'),
				Selector::inNamespace('craft\\cache'),
				Selector::inNamespace('craft\\mutex'),
			)
			->because('Projects replace the session, queue, cache and mutex of Craft with others, like Redis ones, which are not instances of its classes but of their base, like yii\\web\\Session.');
	}

	private function plugin(): SelectorInterface
	{
		return Selector::AllOf(Selector::inNamespace(self::PLUGIN), Selector::Not(Selector::inNamespace(self::TESTS)));
	}

	/**
	 * Classes outside any sub-namespace.
	 */
	private function root(): SelectorInterface
	{
		return Selector::inNamespace('/^' . preg_quote(self::PLUGIN, '/') . '$/', true);
	}

	/**
	 * @param non-empty-string $namespace
	 */
	private function layer(string $namespace): SelectorInterface
	{
		return Selector::inNamespace(self::PLUGIN . '\\' . $namespace);
	}

	/**
	 * Other layers nested in the namespace.
	 *
	 * @param non-empty-string $namespace
	 * @return list<SelectorInterface>
	 */
	private function nestedIn(string $namespace): array
	{
		$nested = array_filter(array_keys(self::LAYERS), static fn(string $other): bool => str_starts_with($other, $namespace . '\\'));

		return array_values(array_map($this->layer(...), $nested));
	}
}
