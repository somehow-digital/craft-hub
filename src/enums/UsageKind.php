<?php

namespace SomehowDigital\Craft\Hub\enums;

use Craft;

/**
 * How an entry type is used.
 */
enum UsageKind: string
{
	case Section = 'section';
	case Reference = 'reference';
	case Link = 'link';
	case Nested = 'nested';
	case HasUrls = 'urls';
	case Unused = 'unused';
	case Empty = 'empty';

	/**
	 * Kinds shown as usage on the entry types list.
	 *
	 * @return self[]
	 */
	public static function listed(): array
	{
		return array_values(array_filter(self::cases(), fn (self $kind) => $kind !== self::HasUrls));
	}

	public function label(): string
	{
		return match ($this) {
			self::Section => Craft::t('hub', 'Section'),
			self::Nested => Craft::t('hub', 'Matrix'),
			self::Reference => Craft::t('hub', 'Reference'),
			self::Link => Craft::t('hub', 'Link'),
			self::HasUrls => Craft::t('hub', 'Has URLs'),
			self::Unused => Craft::t('hub', 'Unused'),
			self::Empty => Craft::t('hub', 'Empty'),
		};
	}

	public function description(): string
	{
		return match ($this) {
			self::Section => Craft::t('hub', 'Assigned to a section.'),
			self::Nested => Craft::t('hub', 'Assigned to a nested entry field (e.g. Matrix, CKEditor).'),
			self::Reference => Craft::t('hub', 'Can be referenced through an Entries field.'),
			self::Link => Craft::t('hub', 'Can be linked to through a Link field.'),
			self::HasUrls => Craft::t('hub', 'Entries of this type have their own URLs.'),
			self::Unused => Craft::t('hub', 'Not assigned to any section or nested entry field.'),
			self::Empty => Craft::t('hub', 'Assigned, but no entries exist.'),
		};
	}

	public function color(): string
	{
		return match ($this) {
			self::Section => 'blue',
			self::Nested => 'violet',
			self::Reference => 'teal',
			self::Link => 'cyan',
			self::HasUrls => 'green',
			self::Unused => 'red',
			self::Empty => 'orange',
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::Section => 'layer-group',
			self::Nested => 'diagram-nested',
			self::Reference => 'link',
			self::Link => 'arrow-up-right-from-square',
			self::HasUrls => 'globe',
			self::Unused => 'ghost',
			self::Empty => 'box-open',
		};
	}
}
