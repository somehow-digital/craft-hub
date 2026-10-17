<?php

namespace SomehowDigital\Craft\Hub\Tests\Support\Fields;

use craft\base\FieldLayoutProviderInterface;
use craft\fields\PlainText;
use craft\models\FieldLayout;

/**
 * A field with a field layout of its own, like Addresses fields.
 */
class LayoutProviderField extends PlainText implements FieldLayoutProviderInterface
{
	public ?FieldLayout $layout = null;

	public static function displayName(): string
	{
		return 'Layout Provider';
	}

	public function getHandle(): ?string
	{
		return $this->handle;
	}

	public function getFieldLayout(): FieldLayout
	{
		return $this->layout ??= new FieldLayout();
	}
}
