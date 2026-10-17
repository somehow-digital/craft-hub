<?php

namespace SomehowDigital\Craft\Hub\bulk;

use craft\web\Response;

/**
 * A bulk action sending a file rather than changing the items.
 */
interface SendsFile
{
	/**
	 * Sends the file of the items with the IDs, or null if there's nothing to send.
	 *
	 * @param int[] $ids
	 * @param array<string,mixed> $params what was picked in its preview
	 */
	public function send(Response $response, array $ids, array $params): ?Response;
}
