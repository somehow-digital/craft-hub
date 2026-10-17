<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\models\EntryType;

/**
 * A field layout that contains a given field, described by its owner and the owner's type.
 */
class LayoutUsage
{
	public function __construct(
		public string $ownerType,
		public string $label,
		public ?string $url = null,
		public int $instances = 1,
		public EntryType|FieldInterface|null $model = null,
		public ?string $color = null,
	) {
	}
}
