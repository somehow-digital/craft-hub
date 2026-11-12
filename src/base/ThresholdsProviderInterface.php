<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\base;

use SomehowDigital\Craft\Hub\enums\CheckKind;

/**
 * Tells the thresholds of the predefined filters, for the rules of the conditions that match against them.
 *
 * @internal
 */
interface ThresholdsProviderInterface
{
	/**
	 * Threshold of a predefined filter, like the months after which entries are stale.
	 */
	public function getThreshold(CheckKind $kind, string $handle): int;
}
