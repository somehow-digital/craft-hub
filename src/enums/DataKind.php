<?php

namespace SomehowDigital\Craft\Hub\enums;

use Craft;

/**
 * What kind of data a field holds.
 */
enum DataKind: string
{
	case Nested = 'nested';
	case Relational = 'relational';
	case Plain = 'plain';

	public function label(): string
	{
		return match ($this) {
			self::Nested => Craft::t('hub', 'Nested entries'),
			self::Relational => Craft::t('hub', 'Relations'),
			self::Plain => Craft::t('hub', 'Plain values'),
		};
	}

	public function color(): string
	{
		return match ($this) {
			self::Nested => UsageKind::Nested->color(),
			self::Relational => UsageKind::Reference->color(),
			self::Plain => 'gray',
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::Nested => UsageKind::Nested->icon(),
			self::Relational => UsageKind::Reference->icon(),
			self::Plain => 'font',
		};
	}
}
