<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\Tests\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;

/**
 * Collects the classes of the layer that each of its classes takes in its constructor, for {@see ServiceCycleRule}.
 *
 * @implements Collector<InClassNode, array{string, list<string>}>
 */
final readonly class ServiceDependenciesCollector implements Collector
{
	/**
	 * @param string $namespace The namespace of the layer.
	 */
	public function __construct(
		private string $namespace,
	) {}

	public function getNodeType(): string
	{
		return InClassNode::class;
	}

	public function processNode(Node $node, Scope $scope): ?array
	{
		$class = $node->getClassReflection();

		if (!str_starts_with($class->getName(), $this->namespace . '\\') || !$class->hasConstructor()) {
			return null;
		}

		$dependencies = [];

		foreach ($class->getConstructor()->getVariants()[0]->getParameters() as $parameter) {
			foreach ($parameter->getType()->getObjectClassNames() as $name) {
				if (str_starts_with($name, $this->namespace . '\\')) {
					$dependencies[] = $name;
				}
			}
		}

		return [$class->getName(), $dependencies];
	}
}
