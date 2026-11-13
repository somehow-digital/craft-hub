<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use Closure;
use LogicException;

/**
 * Properties computed on first read, so statistics only some pages show aren't queried on all of them. A class using it
 * reads and sets them with a getter and a setter each, which is how Craft's models, and Twig, access properties.
 *
 * @internal
 */
trait LazyPropertiesTrait
{
	/** @var array<string,mixed> */
	private array $lazyValues = [];

	/** @var array<string,Closure():mixed> */
	private array $lazyLoaders = [];

	/**
	 * Computes the property with the loader when it is first read, unless it is set before.
	 *
	 * @param Closure():mixed $loader
	 */
	public function lazy(string $property, Closure $loader): void
	{
		unset($this->lazyValues[$property]);
		$this->lazyLoaders[$property] = $loader;
	}

	/**
	 * Lazy properties are set without computing them, as Twig checks properties with `isset()` before reading them.
	 *
	 * @param string $name
	 */
	public function __isset($name): bool
	{
		return isset($this->lazyLoaders[$name]) || isset($this->lazyValues[$name]) || parent::__isset($name);
	}

	protected function setLazyValue(string $property, mixed $value): void
	{
		unset($this->lazyLoaders[$property]);
		$this->lazyValues[$property] = $value;
	}

	protected function getLazyValue(string $property): mixed
	{
		if (isset($this->lazyLoaders[$property])) {
			$loader = $this->lazyLoaders[$property];
			unset($this->lazyLoaders[$property]);
			$this->lazyValues[$property] = $loader();
		}

		return $this->lazyValues[$property] ?? throw new LogicException(sprintf('Undefined property: %s::$%s', static::class, $property));
	}
}
