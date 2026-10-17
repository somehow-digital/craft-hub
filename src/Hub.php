<?php

namespace SomehowDigital\Craft\Hub;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\controllers\UserSettingsController;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use RuntimeException;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\handlers\ActivityTracker;
use SomehowDigital\Craft\Hub\handlers\ContentTracker;
use SomehowDigital\Craft\Hub\handlers\DefineSidebarHtmlHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpNavItemsHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpRoutesHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterPermissionsHandler;
use SomehowDigital\Craft\Hub\handlers\TrashTracker;
use SomehowDigital\Craft\Hub\handlers\UserGroupSidebarHandler;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\DeletionService;
use SomehowDigital\Craft\Hub\services\DiagnosticsService;
use SomehowDigital\Craft\Hub\services\EditService;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\FilterService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\services\ListFilterService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\services\MaintainerService;
use SomehowDigital\Craft\Hub\services\NavigationService;
use SomehowDigital\Craft\Hub\services\QueryService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use SomehowDigital\Craft\Hub\services\ReviewService;
use SomehowDigital\Craft\Hub\services\SelectionService;
use SomehowDigital\Craft\Hub\services\SiteScopeService;
use SomehowDigital\Craft\Hub\services\SnapshotService;
use SomehowDigital\Craft\Hub\services\StatisticsService;
use SomehowDigital\Craft\Hub\services\StructureService;
use SomehowDigital\Craft\Hub\services\TrashService;
use SomehowDigital\Craft\Hub\services\UserService;
use SomehowDigital\Craft\Hub\services\VolumeService;
use yii\base\Controller;
use yii\base\Event;
use yii\web\Response;

/**
 * @property AccessService $access
 * @property ActivityService $activity
 * @property BulkService $bulk
 * @property CheckService $checks
 * @property ColumnService $columns
 * @property DeletionService $deletion
 * @property DiagnosticsService $diagnostics
 * @property EditService $edits
 * @property EntryService $entries
 * @property FileService $files
 * @property FilterService $filters
 * @property LabelService $labels
 * @property ListFilterService $listFilters
 * @property ListService $lists
 * @property MaintainerService $maintainers
 * @property NavigationService $navigation
 * @property QueryService $queries
 * @property ReferenceService $references
 * @property ReviewService $review
 * @property SelectionService $selection
 * @property SiteScopeService $siteScope
 * @property SnapshotService $snapshots
 * @property StatisticsService $statistics
 * @property StructureService $structure
 * @property TrashService $trash
 * @property UserService $users
 * @property VolumeService $volumes
 * @property Settings $settings
 */
class Hub extends Plugin
{
	/** the permissions of the pages are nested under these, see Page */
	public const PERMISSION_ACCESS_DATA = 'hub-accessData';
	public const PERMISSION_ACCESS_STRUCTURE = 'hub-accessStructure';
	public const PERMISSION_EXPORT_ENTRIES = 'hub-exportEntries';
	public const PERMISSION_ENABLE_ENTRIES = 'hub-enableEntries';
	public const PERMISSION_CHANGE_AUTHORS = 'hub-changeAuthors';
	public const PERMISSION_MOVE_ENTRIES = 'hub-moveEntries';
	public const PERMISSION_DELETE_DRAFTS = 'hub-deleteDrafts';
	public const PERMISSION_DELETE_ENTRIES = 'hub-deleteEntries';
	public const PERMISSION_VIEW_TRASHED_ENTRIES = 'hub-viewTrashedEntries';
	public const PERMISSION_EXPORT_TRASHED_ENTRIES = 'hub-exportTrashedEntries';
	public const PERMISSION_RESTORE_ENTRIES = 'hub-restoreEntries';
	public const PERMISSION_PURGE_ENTRIES = 'hub-purgeEntries';
	public const PERMISSION_EXPORT_ASSETS = 'hub-exportAssets';
	public const PERMISSION_DOWNLOAD_ASSETS = 'hub-downloadAssets';
	public const PERMISSION_MOVE_ASSETS = 'hub-moveAssets';
	public const PERMISSION_GENERATE_TRANSFORMS = 'hub-generateTransforms';
	public const PERMISSION_DELETE_TRANSFORMS = 'hub-deleteTransforms';
	public const PERMISSION_DELETE_ASSETS = 'hub-deleteAssets';
	public const PERMISSION_VIEW_TRASHED_ASSETS = 'hub-viewTrashedAssets';
	public const PERMISSION_EXPORT_TRASHED_ASSETS = 'hub-exportTrashedAssets';
	public const PERMISSION_RESTORE_ASSETS = 'hub-restoreAssets';
	public const PERMISSION_PURGE_ASSETS = 'hub-purgeAssets';
	public const PERMISSION_EXPORT_USERS = 'hub-exportUsers';
	public const PERMISSION_ASSIGN_USER_GROUPS = 'hub-assignUserGroups';
	public const PERMISSION_SUSPEND_USERS = 'hub-suspendUsers';
	public const PERMISSION_DELETE_USERS = 'hub-deleteUsers';
	public const PERMISSION_MANAGE_LABELS = 'hub-manageLabels';
	public const PERMISSION_MANAGE_MAINTAINERS = 'hub-manageMaintainers';
	public const PERMISSION_MANAGE_DIAGNOSTICS = 'hub-manageDiagnostics';
	public const PERMISSION_MANAGE_FILTERS = 'hub-manageFilters';
	public const PERMISSION_VIEW_ACTIVITY = 'hub-viewActivity';
	public const SITE_PARAM = 'hubSite';

	public bool $hasCpSection = true;
	public bool $hasCpSettings = true;
	public string $schemaVersion = '1.0.0';

	/**
	 * The plugin, which Craft loads along with its other plugins, so it's there wherever Hub's code runs.
	 */
	public static function getInstance(): static
	{
		return parent::getInstance() ?? throw new RuntimeException('Hub isn’t loaded.');
	}

	public function init(): void
	{
		parent::init();

		Craft::setAlias('@hub', $this->getBasePath());

		$this->registerPermissions();
		$this->registerGarbageCollection();
		$this->registerActivityTracking();
		$this->registerContentTracking();
		$this->registerTrashTracking();

		if (Craft::$app->getRequest()->getIsCpRequest()) {
			$this->registerCpRoutes();
			$this->registerCpNavItems();
			$this->registerSidebar();
			$this->registerSnapshots();
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function config(): array
	{
		return [
			'components' => [
				'access' => ['class' => AccessService::class],
				'activity' => ['class' => ActivityService::class],
				'bulk' => ['class' => BulkService::class],
				'checks' => ['class' => CheckService::class],
				'columns' => ['class' => ColumnService::class],
				'deletion' => ['class' => DeletionService::class],
				'diagnostics' => ['class' => DiagnosticsService::class],
				'edits' => ['class' => EditService::class],
				'entries' => ['class' => EntryService::class],
				'files' => ['class' => FileService::class],
				'filters' => ['class' => FilterService::class],
				'labels' => ['class' => LabelService::class],
				'listFilters' => ['class' => ListFilterService::class],
				'lists' => ['class' => ListService::class],
				'maintainers' => ['class' => MaintainerService::class],
				'navigation' => ['class' => NavigationService::class],
				'queries' => ['class' => QueryService::class],
				'references' => ['class' => ReferenceService::class],
				'review' => ['class' => ReviewService::class],
				'selection' => ['class' => SelectionService::class],
				'siteScope' => ['class' => SiteScopeService::class],
				'snapshots' => ['class' => SnapshotService::class],
				'statistics' => ['class' => StatisticsService::class],
				'structure' => ['class' => StructureService::class],
				'trash' => ['class' => TrashService::class],
				'users' => ['class' => UserService::class],
				'volumes' => ['class' => VolumeService::class],
			],
		];
	}

	/**
	 * The plugin's own navigation item: the "Data" one, or, for users who may open none of its pages, the "Structure" or
	 * the "Hub" one, so Craft places it all the same. The others are added around it.
	 *
	 * @see RegisterCpNavItemsHandler
	 *
	 * @return array<string,mixed>|null
	 */
	public function getCpNavItem(): ?array
	{
		return $this->navigation->getDataNavItem() ?? $this->navigation->getStructureNavItem() ?? $this->navigation->getManageNavItem();
	}

	/**
	 * Gives the predefined filters and default checks their rows, so they can be reordered and toggled by their ID. A release
	 * adding some has to do so in a migration too.
	 */
	protected function afterInstall(): void
	{
		$this->filters->saveDefaults();
		$this->diagnostics->saveDefaults();
	}

	protected function createSettingsModel(): Settings
	{
		return new Settings();
	}

	/**
	 * Craft's settings page of the plugin leads to Hub's own settings page.
	 */
	public function getSettingsResponse(): Response
	{
		return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('hub/manage/settings'));
	}

	protected function registerCpRoutes(): void
	{
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_CP_URL_RULES,
			new RegisterCpRoutesHandler()
		);
	}

	protected function registerCpNavItems(): void
	{
		Event::on(
			Cp::class,
			Cp::EVENT_REGISTER_CP_NAV_ITEMS,
			new RegisterCpNavItemsHandler()
		);
	}

	protected function registerSidebar(): void
	{
		$handler = new DefineSidebarHtmlHandler();

		foreach ([Entry::class, Asset::class, User::class] as $class) {
			Event::on($class, Element::EVENT_DEFINE_SIDEBAR_HTML, $handler);
		}

		Event::on(UserSettingsController::class, Controller::EVENT_AFTER_ACTION, new UserGroupSidebarHandler());
	}

	/**
	 * Removes the labels and maintainers of items deleted for good, the expired activities and snapshots, and the files
	 * kept of trashed assets Craft deleted permanently, along with Craft's garbage.
	 */
	protected function registerGarbageCollection(): void
	{
		Event::on(
			Gc::class,
			Gc::EVENT_RUN,
			function () {
				$this->labels->deleteOrphans();
				$this->maintainers->deleteOrphans();
				$this->activity->deleteExpired();
				$this->snapshots->deleteExpired();
				$this->trash->deletePurgedFiles();
			}
		);
	}

	/**
	 * Records what users do in the activity log, on every request, while it's enabled.
	 */
	protected function registerActivityTracking(): void
	{
		if ($this->settings->enableActivity) {
			(new ActivityTracker())->register();
		}
	}

	/**
	 * Pushes a job taking a snapshot of the statistics once one is due, after a control panel request, so snapshots are
	 * taken without a cron job too, see SnapshotService::pushIfDue().
	 */
	protected function registerSnapshots(): void
	{
		Event::on(
			WebApplication::class,
			WebApplication::EVENT_AFTER_REQUEST,
			fn () => $this->snapshots->pushIfDue()
		);
	}

	/**
	 * Keeps the index of the reference tags in the content and the last edits of entries, on every request.
	 */
	protected function registerContentTracking(): void
	{
		(new ContentTracker())->register();
	}

	/**
	 * Keeps the files of assets moved to the trash, on every request, while it's enabled, see TrashTracker.
	 */
	protected function registerTrashTracking(): void
	{
		if ($this->settings->keepTrashedFiles) {
			(new TrashTracker())->register();
		}
	}

	protected function registerPermissions(): void
	{
		Event::on(
			UserPermissions::class,
			UserPermissions::EVENT_REGISTER_PERMISSIONS,
			new RegisterPermissionsHandler()
		);
	}
}
