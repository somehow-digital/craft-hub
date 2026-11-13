<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\enums;

/**
 * The permissions of the plugin, which the pages and the bulk actions are protected with. The permissions of the pages
 * are nested under the ones that give access to their data or structure, see Page.
 *
 * @internal
 */
enum Permission: string
{
	/** The permission Craft gives access to the plugin with, which every page is protected with. */
	public const PLUGIN = 'accessPlugin-hub';

	case AccessData = 'hub-accessData';
	case AccessStructure = 'hub-accessStructure';
	case ExportEntries = 'hub-exportEntries';
	case EnableEntries = 'hub-enableEntries';
	case ChangeAuthors = 'hub-changeAuthors';
	case MoveEntries = 'hub-moveEntries';
	case DeleteDrafts = 'hub-deleteDrafts';
	case DeleteEntries = 'hub-deleteEntries';
	case ViewTrashedEntries = 'hub-viewTrashedEntries';
	case ExportTrashedEntries = 'hub-exportTrashedEntries';
	case RestoreEntries = 'hub-restoreEntries';
	case PurgeEntries = 'hub-purgeEntries';
	case ExportAssets = 'hub-exportAssets';
	case DownloadAssets = 'hub-downloadAssets';
	case MoveAssets = 'hub-moveAssets';
	case GenerateTransforms = 'hub-generateTransforms';
	case DeleteTransforms = 'hub-deleteTransforms';
	case DeleteAssets = 'hub-deleteAssets';
	case ViewTrashedAssets = 'hub-viewTrashedAssets';
	case ExportTrashedAssets = 'hub-exportTrashedAssets';
	case RestoreAssets = 'hub-restoreAssets';
	case PurgeAssets = 'hub-purgeAssets';
	case ExportUsers = 'hub-exportUsers';
	case AssignUserGroups = 'hub-assignUserGroups';
	case SuspendUsers = 'hub-suspendUsers';
	case DeleteUsers = 'hub-deleteUsers';
	case ManageLabels = 'hub-manageLabels';
	case ManageMaintainers = 'hub-manageMaintainers';
	case ManageDiagnostics = 'hub-manageDiagnostics';
	case ManageFilters = 'hub-manageFilters';
	case ViewActivity = 'hub-viewActivity';
	case ViewWork = 'hub-viewWork';
}
