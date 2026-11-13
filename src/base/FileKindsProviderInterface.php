<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\base;

use SomehowDigital\Craft\Hub\models\FileKindInfo;

/**
 * Tells the file kinds there are, for the rules of the conditions that offer them.
 *
 * @internal
 */
interface FileKindsProviderInterface
{
	/**
	 * @return array<string,FileKindInfo>
	 */
	public function getKinds(): array;
}
