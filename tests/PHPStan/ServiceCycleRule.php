<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\Tests\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids classes of the layer that take each other in their constructors, directly or through others, which the
 * container cannot create. Covers what deptrac can't see within a layer.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ServiceCycleRule implements Rule
{
	public function getNodeType(): string
	{
		return CollectedDataNode::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$graph = [];

		foreach ($node->get(ServiceDependenciesCollector::class) as $perFile) {
			foreach ($perFile as [$class, $dependencies]) {
				$graph[$class] = $dependencies;
			}
		}

		$errors = [];

		foreach (array_keys($graph) as $class) {
			$path = $this->cycleThrough($class, $class, $graph, []);

			if ($path !== null) {
				$errors[] = RuleErrorBuilder::message('The constructors of ' . implode(' -> ', [$class, ...$path]) . ' depend on each other, so the container cannot create them. Pass what they share through a class both take.')
					->identifier('architecture.serviceCycle')
					->build();
			}
		}

		return $errors;
	}

	/**
	 * The classes leading from a class back to the target, if any.
	 *
	 * @param array<string,list<string>> $graph
	 * @param list<string> $visited
	 * @return list<string>|null
	 */
	private function cycleThrough(string $class, string $target, array $graph, array $visited): ?array
	{
		foreach ($graph[$class] ?? [] as $dependency) {
			if ($dependency === $target) {
				return [...$visited, $dependency];
			}

			if (!in_array($dependency, $visited, true)) {
				$path = $this->cycleThrough($dependency, $target, $graph, [...$visited, $dependency]);

				if ($path !== null) {
					return $path;
				}
			}
		}

		return null;
	}
}
