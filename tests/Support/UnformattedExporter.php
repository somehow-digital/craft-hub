<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\elements\db\ElementQueryInterface;
use craft\elements\exporters\Raw;

/**
 * An exporter whose data is sent as it is, rather than formatted by Craft.
 */
final class UnformattedExporter extends Raw
{
	public static function isFormattable(): bool
	{
		return false;
	}

	public function export(ElementQueryInterface $query): string
	{
		return 'a,b';
	}
}
