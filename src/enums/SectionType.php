<?php

namespace SomehowDigital\Craft\Hub\enums;

use Craft;
use craft\models\Section;

enum SectionType: string
{
	case Single = Section::TYPE_SINGLE;
	case Channel = Section::TYPE_CHANNEL;
	case Structure = Section::TYPE_STRUCTURE;

	public function label(): string
	{
		return match ($this) {
			self::Single => Craft::t('app', 'Single'),
			self::Channel => Craft::t('app', 'Channel'),
			self::Structure => Craft::t('app', 'Structure'),
		};
	}

	public function color(): string
	{
		return match ($this) {
			self::Single => 'sky',
			self::Channel => 'indigo',
			self::Structure => 'teal',
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::Single => 'file',
			self::Channel => 'list',
			self::Structure => 'sitemap',
		};
	}
}
