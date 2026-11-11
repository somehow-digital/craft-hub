<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\enums;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;

/**
 * What the checks of a diagnostics tab are of: the elements of a data page, or the items of a structure page. Saved
 * filters are of the lists of these pages too.
 *
 * @internal
 */
enum CheckKind: string
{
	case Entries = 'entries';
	case Assets = 'assets';
	case Users = 'users';
	case EntryTypes = 'entryTypes';
	case Sections = 'sections';
	case Fields = 'fields';
	case Volumes = 'volumes';

	public function label(): string
	{
		return match ($this) {
			self::Entries => Craft::t('app', 'Entries'),
			self::Assets => Craft::t('app', 'Assets'),
			self::Users => Craft::t('app', 'Users'),
			self::EntryTypes => Craft::t('hub', 'Entry Types'),
			self::Sections => Craft::t('app', 'Sections'),
			self::Fields => Craft::t('app', 'Fields'),
			self::Volumes => Craft::t('app', 'Volumes'),
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::Entries => 'newspaper',
			self::Assets => 'image',
			self::Users => 'user-group',
			self::EntryTypes => 'shapes',
			self::Sections => 'layer-group',
			self::Fields => 'pen-ruler',
			self::Volumes => 'hard-drive',
		};
	}

	/**
	 * Path of the index page, whose diagnostics tab lists the checks.
	 */
	public function path(): string
	{
		return match ($this) {
			self::Entries => 'hub/data/entries',
			self::Assets => 'hub/data/assets',
			self::Users => 'hub/data/users',
			self::EntryTypes => 'hub/structure/entry-types',
			self::Sections => 'hub/structure/sections',
			self::Fields => 'hub/structure/fields',
			self::Volumes => 'hub/structure/volumes',
		};
	}

	/**
	 * Page listing what is checked, viewed by a permission of its own.
	 */
	public function page(): Page
	{
		return match ($this) {
			self::Entries => Page::Entries,
			self::Assets => Page::Assets,
			self::Users => Page::Users,
			self::EntryTypes => Page::EntryTypes,
			self::Sections => Page::Sections,
			self::Fields => Page::Fields,
			self::Volumes => Page::Volumes,
		};
	}

	/**
	 * List of what is checked, see ListType.
	 */
	public function list(): string
	{
		return match ($this) {
			self::Entries => ListType::Entries->value,
			self::Assets => ListType::Files->value,
			self::Users => ListType::Users->value,
			self::EntryTypes => ListType::EntryTypes->value,
			self::Sections => ListType::Sections->value,
			self::Fields => ListType::Fields->value,
			self::Volumes => ListType::Volumes->value,
		};
	}

	/**
	 * @return class-string<ElementInterface>|null the element type checked, or null for the items of a structure page
	 */
	public function elementType(): ?string
	{
		return match ($this) {
			self::Entries => Entry::class,
			self::Assets => Asset::class,
			self::Users => User::class,
			self::EntryTypes, self::Sections, self::Fields, self::Volumes => null,
		};
	}
}
