<?php

namespace SomehowDigital\Craft\Hub\models;

use SomehowDigital\Craft\Hub\enums\Severity;

/**
 * Result of a single review check on a detail page.
 */
class Check
{
	/** @var object[] sections, entry types, fields, volumes, entries or assets the check found */
	public array $items = [];

	/** number of affected elements, when they are listed below the checks instead */
	public ?int $count = null;

	/** link to the list of affected elements */
	public ?string $url = null;

	/**
	 * @param string $heading what is checked
	 * @param string $description what is wrong and what to do about it, shown when the check fails
	 * @param string $passedDescription why the check passed, shown when it passes
	 */
	public function __construct(
		public string $heading,
		public string $description,
		public string $passedDescription,
		public Severity $severity,
		public string $icon,
		public bool $passed,
	) {
	}
}
