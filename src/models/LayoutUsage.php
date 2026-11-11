<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\models\EntryType;

/**
 * A field layout that contains a given field, described by its owner and the owner's type.
 *
 * @internal
 */
final readonly class LayoutUsage
{
	public function __construct(
		public string $ownerType,
		public string $label,
		public ?string $url = null,
		public int $instances = 1,
		public EntryType|FieldInterface|null $model = null,
		public ?string $color = null,
	) {}

	/**
	 * The usage for another number of instances of the layout.
	 */
	public function withInstances(int $instances): self
	{
		return new self($this->ownerType, $this->label, $this->url, $instances, $this->model, $this->color);
	}
}
