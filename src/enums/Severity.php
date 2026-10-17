<?php

namespace SomehowDigital\Craft\Hub\enums;

use Craft;

/**
 * How urgent a failed check is.
 */
enum Severity: string
{
	case Error = 'error';
	case Warning = 'warning';
	case Notice = 'notice';

	public function label(): string
	{
		return match ($this) {
			self::Error => Craft::t('hub', 'Clean up'),
			self::Warning => Craft::t('hub', 'Needs attention'),
			self::Notice => Craft::t('hub', 'Worth a look'),
		};
	}

	public function color(): string
	{
		return match ($this) {
			self::Error => 'red',
			self::Warning => 'orange',
			self::Notice => 'blue',
		};
	}

	public function rank(): int
	{
		return match ($this) {
			self::Error => 0,
			self::Warning => 1,
			self::Notice => 2,
		};
	}
}
