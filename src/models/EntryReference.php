<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\ElementInterface;
use craft\base\FieldInterface;

/**
 * An element that references a given entry or asset, along with the fields it references it through.
 */
class EntryReference
{
	/**
	 * @var array<string,array{label:string,field:?FieldInterface}> keyed by label
	 */
	public array $paths = [];

	public function __construct(
		public ElementInterface $element,
	) {
	}

	/**
	 * @param string $label field names leading to the reference, joined by arrows for nested entries
	 * @param FieldInterface|null $field the relation field holding the reference
	 */
	public function addPath(string $label, ?FieldInterface $field): void
	{
		$this->paths[$label] ??= ['label' => $label, 'field' => $field];
	}
}
