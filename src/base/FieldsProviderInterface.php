<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\base;

use SomehowDigital\Craft\Hub\models\FieldInfo;

/**
 * Tells the fields there are, for the rules of the conditions that offer them.
 *
 * @internal
 */
interface FieldsProviderInterface
{
	/**
	 * @return array<int,FieldInfo>
	 */
	public function getFields(): array;
}
