<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\Model;

/**
 * An element that references a given entry or asset, along with the fields it references it through.
 *
 * @internal
 */
final class EntryReference extends Model
{
	/**
	 * @var array<string,array{label:string,field:?FieldInterface}> keyed by label
	 */
	public array $paths = [];

	public function __construct(
		public ElementInterface $element,
	) {
		parent::__construct();
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
