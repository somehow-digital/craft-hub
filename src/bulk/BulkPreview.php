<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

/**
 * What a preview of a bulk action shows: rendered by a component (see `_components`) with the variables, along with the
 * number of the items the action applies to, so nothing is offered without any.
 */
final readonly class BulkPreview
{
	/**
	 * @param array<string,mixed> $variables
	 */
	public function __construct(
		public string $component,
		public int $total,
		public array $variables = [],
	) {}
}
