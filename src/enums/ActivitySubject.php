<?php

namespace SomehowDigital\Craft\Hub\enums;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use SomehowDigital\Craft\Hub\services\ColumnService;

/**
 * What an activity of the activity log was done to: an element, something holding the content, a part of the system's
 * settings, something of Hub's own, or the system itself.
 */
enum ActivitySubject: string
{
	case Entry = 'entry';
	case Asset = 'asset';
	case User = 'user';
	case Category = 'category';
	case Tag = 'tag';
	case GlobalSet = 'globalSet';
	case Element = 'element';
	case Section = 'section';
	case EntryType = 'entryType';
	case Field = 'field';
	case Volume = 'volume';
	case UserGroup = 'userGroup';
	case Site = 'site';
	case CategoryGroup = 'categoryGroup';
	case TagGroup = 'tagGroup';
	case ImageTransform = 'imageTransform';
	case Plugin = 'plugin';
	case SiteGroup = 'siteGroup';
	case Filesystem = 'filesystem';
	case Route = 'route';
	case GqlSchema = 'gqlSchema';
	case GqlToken = 'gqlToken';
	case Settings = 'settings';
	case ProjectConfig = 'projectConfig';
	case System = 'system';
	case Database = 'database';
	case Utility = 'utility';
	case Label = 'label';
	case Check = 'check';
	case Filter = 'filter';

	/**
	 * Kind of an element; elements of other types are just elements, their type kept along with the activity.
	 */
	public static function fromElement(ElementInterface $element): self
	{
		return match (true) {
			$element instanceof Entry => self::Entry,
			$element instanceof Asset => self::Asset,
			$element instanceof User => self::User,
			$element instanceof Category => self::Category,
			$element instanceof Tag => self::Tag,
			$element instanceof GlobalSet => self::GlobalSet,
			default => self::Element,
		};
	}

	/**
	 * Kind of the elements of a type, like those an element action was performed on.
	 *
	 * @param class-string<ElementInterface> $type
	 */
	public static function fromElementType(string $type): self
	{
		return match (true) {
			is_a($type, Entry::class, true) => self::Entry,
			is_a($type, Asset::class, true) => self::Asset,
			is_a($type, User::class, true) => self::User,
			is_a($type, Category::class, true) => self::Category,
			is_a($type, Tag::class, true) => self::Tag,
			is_a($type, GlobalSet::class, true) => self::GlobalSet,
			default => self::Element,
		};
	}

	/**
	 * Kind of the items of one of Hub's lists (see ColumnService::LIST_*).
	 */
	public static function fromList(string $list): ?self
	{
		return match ($list) {
			ColumnService::LIST_ENTRIES => self::Entry,
			ColumnService::LIST_FILES => self::Asset,
			ColumnService::LIST_USERS => self::User,
			ColumnService::LIST_SECTIONS => self::Section,
			ColumnService::LIST_ENTRY_TYPES => self::EntryType,
			ColumnService::LIST_FIELDS => self::Field,
			ColumnService::LIST_VOLUMES => self::Volume,
			ColumnService::LIST_USER_GROUPS => self::UserGroup,
			default => null,
		};
	}

	/**
	 * Whether the subject is an element, which can be edited by its ID.
	 */
	public function isElement(): bool
	{
		return in_array($this, [self::Entry, self::Asset, self::User, self::Category, self::Tag, self::GlobalSet, self::Element], true);
	}

	public function label(): string
	{
		return match ($this) {
			self::Entry => Craft::t('app', 'Entry'),
			self::Asset => Craft::t('app', 'Asset'),
			self::User => Craft::t('app', 'User'),
			self::Category => Craft::t('app', 'Category'),
			self::Tag => Craft::t('app', 'Tag'),
			self::GlobalSet => Craft::t('app', 'Global Set'),
			self::Element => Craft::t('hub', 'Element'),
			self::Section => Craft::t('app', 'Section'),
			self::EntryType => Craft::t('app', 'Entry Type'),
			self::Field => Craft::t('app', 'Field'),
			self::Volume => Craft::t('app', 'Volume'),
			self::UserGroup => Craft::t('app', 'User Group'),
			self::Site => Craft::t('app', 'Site'),
			self::CategoryGroup => Craft::t('app', 'Category Group'),
			self::TagGroup => Craft::t('app', 'Tag Group'),
			self::ImageTransform => Craft::t('app', 'Image Transform'),
			self::Plugin => Craft::t('app', 'Plugin'),
			self::SiteGroup => Craft::t('app', 'Site Group'),
			self::Filesystem => Craft::t('app', 'Filesystem'),
			self::Route => Craft::t('app', 'Route'),
			self::GqlSchema => Craft::t('app', 'GraphQL Schema'),
			self::GqlToken => Craft::t('app', 'GraphQL Token'),
			self::Settings => Craft::t('app', 'Settings'),
			self::ProjectConfig => Craft::t('app', 'Project Config'),
			self::System => Craft::t('app', 'System'),
			self::Database => Craft::t('app', 'Database'),
			self::Utility => Craft::t('app', 'Utility'),
			self::Label => Craft::t('hub', 'Label'),
			self::Check => Craft::t('hub', 'Check'),
			self::Filter => Craft::t('hub', 'Saved filter'),
		};
	}

	public function color(): string
	{
		return match ($this) {
			self::Entry => 'blue',
			self::Asset, self::Volume, self::Filesystem => 'amber',
			self::User, self::UserGroup => 'violet',
			self::Category, self::CategoryGroup, self::Tag, self::TagGroup => 'teal',
			self::GlobalSet => 'sky',
			self::Section, self::EntryType => 'indigo',
			self::Field => 'pink',
			self::Site, self::SiteGroup, self::Route => 'cyan',
			self::GqlSchema, self::GqlToken => 'pink',
			self::Label => 'teal',
			self::Check => 'indigo',
			self::Filter => 'sky',
			self::Element, self::ImageTransform, self::Plugin, self::Settings, self::ProjectConfig, self::System, self::Database, self::Utility => 'gray',
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::Entry => 'newspaper',
			self::Asset => 'image',
			self::User => 'user',
			self::Category, self::CategoryGroup => 'sitemap',
			self::Tag, self::TagGroup => 'tag',
			self::GlobalSet => 'globe',
			self::Element => 'cube',
			self::Section => 'layer-group',
			self::EntryType => 'shapes',
			self::Field => 'pen-to-square',
			self::Volume => 'folder-open',
			self::UserGroup => 'users',
			self::Site => 'earth-americas',
			self::ImageTransform => 'crop',
			self::Plugin => 'plug',
			self::SiteGroup => 'folder-tree',
			self::Filesystem => 'hard-drive',
			self::Route => 'route',
			self::GqlSchema => 'diagram-project',
			self::GqlToken => 'key',
			self::Settings => 'gear',
			self::ProjectConfig => 'file-code',
			self::System => 'server',
			self::Database => 'database',
			self::Utility => 'wrench',
			self::Label => 'tags',
			self::Check => 'stethoscope',
			self::Filter => 'filter',
		};
	}
}
