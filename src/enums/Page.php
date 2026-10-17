<?php

namespace SomehowDigital\Craft\Hub\enums;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Volume;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ColumnService;

/**
 * The pages of Hub's lists, below the "Data" and the "Structure" navigation items, each viewed, labeled and bulk-edited
 * by a permission of its own, nested under the permission accessing its area.
 */
enum Page: string
{
	case Entries = 'entries';
	case Assets = 'assets';
	case Users = 'users';
	case EntryTypes = 'entryTypes';
	case Sections = 'sections';
	case Fields = 'fields';
	case Volumes = 'volumes';

	/**
	 * Page of one of Hub's lists (see ColumnService::LIST_*); the user groups are on the users page.
	 */
	public static function fromList(string $list): ?self
	{
		return match ($list) {
			ColumnService::LIST_ENTRIES, ColumnService::LIST_TRASHED_ENTRIES => self::Entries,
			ColumnService::LIST_FILES, ColumnService::LIST_TRASHED_FILES => self::Assets,
			ColumnService::LIST_USERS, ColumnService::LIST_USER_GROUPS => self::Users,
			ColumnService::LIST_ENTRY_TYPES => self::EntryTypes,
			ColumnService::LIST_SECTIONS => self::Sections,
			ColumnService::LIST_FIELDS => self::Fields,
			ColumnService::LIST_VOLUMES => self::Volumes,
			default => null,
		};
	}

	/**
	 * Page listing an item, which has a detail page there.
	 */
	public static function fromItem(mixed $item): ?self
	{
		return match (true) {
			$item instanceof Entry => self::Entries,
			$item instanceof Asset => self::Assets,
			$item instanceof User => self::Users,
			$item instanceof EntryType => self::EntryTypes,
			$item instanceof Section => self::Sections,
			$item instanceof FieldInterface => self::Fields,
			$item instanceof Volume => self::Volumes,
			default => null,
		};
	}

	/**
	 * Whether the page is below the "Structure" navigation item, listing what holds the content, rather than the content.
	 */
	public function isStructure(): bool
	{
		return in_array($this, [self::EntryTypes, self::Sections, self::Fields, self::Volumes], true);
	}

	/**
	 * Permission accessing the area of the page, which its own permissions are nested under.
	 */
	public function areaPermission(): string
	{
		return $this->isStructure() ? Hub::PERMISSION_ACCESS_STRUCTURE : Hub::PERMISSION_ACCESS_DATA;
	}

	public function viewPermission(): string
	{
		return 'hub-view' . ucfirst($this->value);
	}

	public function labelPermission(): string
	{
		return 'hub-label' . ucfirst($this->value);
	}

	public function bulkEditPermission(): string
	{
		return 'hub-bulkEdit' . ucfirst($this->value);
	}

	/**
	 * Permissions of the actions applied to the items of the page, along with their label, nested under its view
	 * permission; they're offered as far as Craft's own permissions of each item allow.
	 *
	 * @return array<string,string>
	 */
	public function actionPermissions(): array
	{
		return match ($this) {
			self::Entries => [
				Hub::PERMISSION_EXPORT_ENTRIES => Craft::t('hub', 'Export entries'),
				Hub::PERMISSION_ENABLE_ENTRIES => Craft::t('hub', 'Enable and disable entries'),
				Hub::PERMISSION_CHANGE_AUTHORS => Craft::t('hub', 'Change the authors of entries'),
				Hub::PERMISSION_MOVE_ENTRIES => Craft::t('hub', 'Move entries to another section'),
				Hub::PERMISSION_DELETE_DRAFTS => Craft::t('hub', 'Delete drafts of entries'),
				Hub::PERMISSION_DELETE_ENTRIES => Craft::t('hub', 'Delete entries'),
			],
			self::Assets => [
				Hub::PERMISSION_EXPORT_ASSETS => Craft::t('hub', 'Export assets'),
				Hub::PERMISSION_DOWNLOAD_ASSETS => Craft::t('hub', 'Download assets'),
				Hub::PERMISSION_MOVE_ASSETS => Craft::t('hub', 'Move assets to another folder'),
				Hub::PERMISSION_GENERATE_TRANSFORMS => Craft::t('hub', 'Generate asset transforms'),
				Hub::PERMISSION_DELETE_TRANSFORMS => Craft::t('hub', 'Delete asset transforms'),
				Hub::PERMISSION_DELETE_ASSETS => Craft::t('hub', 'Delete assets'),
			],
			self::Users => [
				Hub::PERMISSION_EXPORT_USERS => Craft::t('hub', 'Export users'),
				Hub::PERMISSION_ASSIGN_USER_GROUPS => Craft::t('hub', 'Change the groups of users'),
				Hub::PERMISSION_SUSPEND_USERS => Craft::t('hub', 'Suspend users'),
				Hub::PERMISSION_DELETE_USERS => Craft::t('hub', 'Delete users'),
			],
			default => [],
		};
	}

	/**
	 * Permission viewing the trash tab of the page, nested under its view permission; null for pages without one.
	 */
	public function trashPermission(): ?string
	{
		return match ($this) {
			self::Entries => Hub::PERMISSION_VIEW_TRASHED_ENTRIES,
			self::Assets => Hub::PERMISSION_VIEW_TRASHED_ASSETS,
			default => null,
		};
	}

	/**
	 * Label of the permission viewing the trash tab of the page, see trashPermission().
	 */
	public function trashPermissionLabel(): ?string
	{
		return match ($this) {
			self::Entries => Craft::t('hub', 'View trashed entries'),
			self::Assets => Craft::t('hub', 'View trashed assets'),
			default => null,
		};
	}

	/**
	 * Permissions of the actions applied to the items in the trash of the page, along with their label, nested under its
	 * trash permission; they're offered as far as Craft's own permissions of each item allow.
	 *
	 * @return array<string,string>
	 */
	public function trashActionPermissions(): array
	{
		return match ($this) {
			self::Entries => [
				Hub::PERMISSION_EXPORT_TRASHED_ENTRIES => Craft::t('hub', 'Export trashed entries'),
				Hub::PERMISSION_RESTORE_ENTRIES => Craft::t('hub', 'Restore entries'),
				Hub::PERMISSION_PURGE_ENTRIES => Craft::t('hub', 'Delete entries permanently'),
			],
			self::Assets => [
				Hub::PERMISSION_EXPORT_TRASHED_ASSETS => Craft::t('hub', 'Export trashed assets'),
				Hub::PERMISSION_RESTORE_ASSETS => Craft::t('hub', 'Restore assets'),
				Hub::PERMISSION_PURGE_ASSETS => Craft::t('hub', 'Delete assets permanently'),
			],
			default => [],
		};
	}

	/**
	 * Name of the page, as its navigation item shows it.
	 */
	public function label(): string
	{
		return match ($this) {
			self::Entries => Craft::t('hub', 'Entries'),
			self::Assets => Craft::t('hub', 'Assets'),
			self::Users => Craft::t('hub', 'Users'),
			self::EntryTypes => Craft::t('hub', 'Entry Types'),
			self::Sections => Craft::t('hub', 'Sections'),
			self::Fields => Craft::t('hub', 'Fields'),
			self::Volumes => Craft::t('hub', 'Volumes'),
		};
	}

	/**
	 * Labels of the permissions viewing the page, labeling its items and bulk-editing them, in that order.
	 *
	 * @return array{0:string,1:string,2:string}
	 */
	public function permissionLabels(): array
	{
		return match ($this) {
			self::Entries => [Craft::t('hub', 'View entries'), Craft::t('hub', 'Label entries'), Craft::t('hub', 'Bulk-edit entries')],
			self::Assets => [Craft::t('hub', 'View assets'), Craft::t('hub', 'Label assets'), Craft::t('hub', 'Bulk-edit assets')],
			self::Users => [Craft::t('hub', 'View users'), Craft::t('hub', 'Label users'), Craft::t('hub', 'Bulk-edit users')],
			self::EntryTypes => [Craft::t('hub', 'View entry types'), Craft::t('hub', 'Label entry types'), Craft::t('hub', 'Bulk-edit entry types')],
			self::Sections => [Craft::t('hub', 'View sections'), Craft::t('hub', 'Label sections'), Craft::t('hub', 'Bulk-edit sections')],
			self::Fields => [Craft::t('hub', 'View fields'), Craft::t('hub', 'Label fields'), Craft::t('hub', 'Bulk-edit fields')],
			self::Volumes => [Craft::t('hub', 'View volumes'), Craft::t('hub', 'Label volumes'), Craft::t('hub', 'Bulk-edit volumes')],
		};
	}

	/**
	 * Path segment of the page, below that of its area.
	 */
	public function segment(): string
	{
		return match ($this) {
			self::EntryTypes => 'entry-types',
			default => $this->value,
		};
	}
}
