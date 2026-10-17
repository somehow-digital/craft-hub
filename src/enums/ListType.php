<?php

namespace SomehowDigital\Craft\Hub\enums;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;

/**
 * Hub's lists and what belongs to each of them, by the list's name (see ColumnService::LIST_*).
 */
enum ListType: string
{
	case Sections = 'sections';
	case EntryTypes = 'entryTypes';
	case Fields = 'fields';
	case Volumes = 'volumes';
	case Entries = 'entries';
	case Files = 'files';
	case Users = 'users';
	case UserGroups = 'userGroups';
	case TrashedEntries = 'trashedEntries';
	case TrashedFiles = 'trashedFiles';
	case Activity = 'activity';

	/**
	 * Page of the list; the user groups are on the users page, and the activity log has a page of its own.
	 */
	public function page(): ?Page
	{
		return match ($this) {
			self::Entries, self::TrashedEntries => Page::Entries,
			self::Files, self::TrashedFiles => Page::Assets,
			self::Users, self::UserGroups => Page::Users,
			self::EntryTypes => Page::EntryTypes,
			self::Sections => Page::Sections,
			self::Fields => Page::Fields,
			self::Volumes => Page::Volumes,
			self::Activity => null,
		};
	}

	/**
	 * Kind of the items of the list.
	 */
	public function subject(): ?ActivitySubject
	{
		return match ($this) {
			self::Entries, self::TrashedEntries => ActivitySubject::Entry,
			self::Files, self::TrashedFiles => ActivitySubject::Asset,
			self::Users => ActivitySubject::User,
			self::Sections => ActivitySubject::Section,
			self::EntryTypes => ActivitySubject::EntryType,
			self::Fields => ActivitySubject::Field,
			self::Volumes => ActivitySubject::Volume,
			self::UserGroups => ActivitySubject::UserGroup,
			self::Activity => null,
		};
	}

	/**
	 * Element type of the items of the list, for lists of elements.
	 *
	 * @return class-string<ElementInterface>|null
	 */
	public function elementType(): ?string
	{
		return match ($this) {
			self::Entries, self::TrashedEntries => Entry::class,
			self::Files, self::TrashedFiles => Asset::class,
			self::Users => User::class,
			default => null,
		};
	}

	public function isTrash(): bool
	{
		return $this === self::TrashedEntries || $this === self::TrashedFiles;
	}

	/**
	 * Path of the page of the labeled lists, which a label's use leads to.
	 */
	public function path(): ?string
	{
		return match ($this) {
			self::Entries => 'hub/data/entries',
			self::Files => 'hub/data/assets',
			self::Users => 'hub/data/users',
			self::UserGroups => 'hub/data/users/groups',
			self::Sections => 'hub/structure/sections',
			self::EntryTypes => 'hub/structure/entry-types',
			self::Fields => 'hub/structure/fields',
			self::Volumes => 'hub/structure/volumes',
			default => null,
		};
	}

	/**
	 * Name of the labeled lists.
	 */
	public function label(): ?string
	{
		return match ($this) {
			self::Entries => Craft::t('hub', 'Entries'),
			self::Files => Craft::t('hub', 'Assets'),
			self::Users => Craft::t('hub', 'Users'),
			self::UserGroups => Craft::t('app', 'User Groups'),
			self::Sections => Craft::t('hub', 'Sections'),
			self::EntryTypes => Craft::t('hub', 'Entry Types'),
			self::Fields => Craft::t('hub', 'Fields'),
			self::Volumes => Craft::t('hub', 'Volumes'),
			default => null,
		};
	}
}
