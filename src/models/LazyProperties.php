<?php

namespace SomehowDigital\Craft\Hub\models;

use Closure;
use LogicException;

/**
 * Properties computed on first access, so statistics only some pages show aren't queried on all of them.
 */
trait LazyProperties
{
	/** @var array<string,Closure> */
	private array $loaders = [];

	/**
	 * Computes the property with the loader when it is first read, unless it is set before.
	 * The property is unset, as PHP calls {@see __get()} for unset properties only.
	 */
	public function lazy(string $property, Closure $loader): void
	{
		unset($this->$property);
		$this->loaders[$property] = $loader;
	}

	public function __get(string $name): mixed
	{
		$loader = $this->loaders[$name] ?? throw new LogicException(sprintf('Undefined property: %s::$%s', static::class, $name));
		unset($this->loaders[$name]);

		return $this->$name = $loader();
	}

	/**
	 * Lazy properties are set, as Twig checks properties with `isset()` before reading them.
	 */
	public function __isset(string $name): bool
	{
		return isset($this->loaders[$name]);
	}
}
