<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\enums;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;

/**
 * The pages of Hub's lists, below the "Data" and the "Structure" navigation items, each viewed, labeled and bulk-edited
 * by a permission of its own, nested under the permission accessing its area.
 *
 * @internal
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
		return ListType::tryFrom($list)?->page();
	}

	/**
	 * Page listing an item, which has a detail page there.
	 */
	public static function fromItem(mixed $item): ?self
	{
		return match (true) {
			$item instanceof Entry => self::Entries,
			$item instanceof Asset => self::Assets,
			$item instanceof User, $item instanceof UserGroup => self::Users,
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
		return $this->isStructure() ? Permission::AccessStructure->value : Permission::AccessData->value;
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
				Permission::ExportEntries->value => Craft::t('hub', 'Export entries'),
				Permission::EnableEntries->value => Craft::t('hub', 'Enable and disable entries'),
				Permission::ChangeAuthors->value => Craft::t('hub', 'Change the authors of entries'),
				Permission::MoveEntries->value => Craft::t('hub', 'Move entries to another section'),
				Permission::DeleteDrafts->value => Craft::t('hub', 'Delete drafts of entries'),
				Permission::DeleteEntries->value => Craft::t('hub', 'Delete entries'),
			],
			self::Assets => [
				Permission::ExportAssets->value => Craft::t('hub', 'Export assets'),
				Permission::DownloadAssets->value => Craft::t('hub', 'Download assets'),
				Permission::MoveAssets->value => Craft::t('hub', 'Move assets to another folder'),
				Permission::GenerateTransforms->value => Craft::t('hub', 'Generate asset transforms'),
				Permission::DeleteTransforms->value => Craft::t('hub', 'Delete asset transforms'),
				Permission::DeleteAssets->value => Craft::t('hub', 'Delete assets'),
			],
			self::Users => [
				Permission::ExportUsers->value => Craft::t('hub', 'Export users'),
				Permission::AssignUserGroups->value => Craft::t('hub', 'Change the groups of users'),
				Permission::SuspendUsers->value => Craft::t('hub', 'Suspend users'),
				Permission::DeleteUsers->value => Craft::t('hub', 'Delete users'),
			],
			self::EntryTypes, self::Sections, self::Fields, self::Volumes => [],
		};
	}

	/**
	 * Permission viewing the trash tab of the page, nested under its view permission; null for pages without one.
	 */
	public function trashPermission(): ?string
	{
		return match ($this) {
			self::Entries => Permission::ViewTrashedEntries->value,
			self::Assets => Permission::ViewTrashedAssets->value,
			self::Users, self::EntryTypes, self::Sections, self::Fields, self::Volumes => null,
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
			self::Users, self::EntryTypes, self::Sections, self::Fields, self::Volumes => null,
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
				Permission::ExportTrashedEntries->value => Craft::t('hub', 'Export trashed entries'),
				Permission::RestoreEntries->value => Craft::t('hub', 'Restore entries'),
				Permission::PurgeEntries->value => Craft::t('hub', 'Delete entries permanently'),
			],
			self::Assets => [
				Permission::ExportTrashedAssets->value => Craft::t('hub', 'Export trashed assets'),
				Permission::RestoreAssets->value => Craft::t('hub', 'Restore assets'),
				Permission::PurgeAssets->value => Craft::t('hub', 'Delete assets permanently'),
			],
			self::Users, self::EntryTypes, self::Sections, self::Fields, self::Volumes => [],
		};
	}

	/**
	 * Name of the page, as its navigation item shows it.
	 */
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
			self::Entries, self::Assets, self::Users, self::Sections, self::Fields, self::Volumes => $this->value,
		};
	}
}
