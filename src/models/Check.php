<?php

namespace SomehowDigital\Craft\Hub\models;

use SomehowDigital\Craft\Hub\enums\Severity;

/**
 * Result of a single diagnostic check on a detail page.
 */
class Check
{
	/** key of the check as configured, see CheckDefinition::getKey() */
	public ?string $key = null;

	/** @var object[] sections, entry types, fields, volumes, entries or assets the check found */
	public array $items = [];

	/** number of affected elements, when they are listed below the checks instead */
	public ?int $count = null;

	/** link to where the affected elements can be looked at or fixed, shown when the check fails */
	public ?string $url = null;

	/** label of the link, `Show list` when not set */
	public ?string $action = null;

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

	/**
	 * Links the check to the next step, unless there is nowhere to go, e.g. as the user can't open the page.
	 */
	public function link(?string $url, string $action): static
	{
		if ($url !== null) {
			$this->url = $url;
			$this->action = $action;
		}

		return $this;
	}
}
