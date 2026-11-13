<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\base;

use SomehowDigital\Craft\Hub\models\FileDownload;

/**
 * A bulk action sending a file rather than changing the items.
 *
 * @internal
 */
interface SendsFileInterface
{
	/**
	 * Prepares the file of the items with the IDs, which the controller sends, or null if there's nothing to send.
	 *
	 * @param int[] $ids
	 * @param array<string,mixed> $params what was picked in its preview
	 */
	public function download(array $ids, array $params): ?FileDownload;
}
