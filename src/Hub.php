<?php

namespace SomehowDigital\Craft\Hub;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\handlers\ActivityTracker;
use SomehowDigital\Craft\Hub\handlers\ContentTracker;
use SomehowDigital\Craft\Hub\handlers\DefineEntrySidebarHtmlHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpNavItemsHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpRoutesHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterPermissionsHandler;
use SomehowDigital\Craft\Hub\models\Settings;
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
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\services\QueryService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use SomehowDigital\Craft\Hub\services\ReviewService;
use SomehowDigital\Craft\Hub\services\StatisticsService;
use SomehowDigital\Craft\Hub\services\StructureService;
use SomehowDigital\Craft\Hub\services\UserService;
use SomehowDigital\Craft\Hub\services\VolumeService;
use yii\base\Event;
use yii\web\Response;

/**
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
 * @property ListService $lists
 * @property QueryService $queries
 * @property ReferenceService $references
 * @property ReviewService $review
 * @property StatisticsService $statistics
 * @property StructureService $structure
 * @property LabelService $labels
 * @property UserService $users
 * @property VolumeService $volumes
 * @property Settings $settings
 */
class Hub extends Plugin
{
	/** the permissions of the pages are nested under these, see Page */
	public const PERMISSION_ACCESS_DATA = 'hub-accessData';
	public const PERMISSION_ACCESS_STRUCTURE = 'hub-accessStructure';
	public const PERMISSION_ENABLE_ENTRIES = 'hub-enableEntries';
	public const PERMISSION_DELETE_ENTRIES = 'hub-deleteEntries';
	public const PERMISSION_DOWNLOAD_ASSETS = 'hub-downloadAssets';
	public const PERMISSION_DELETE_TRANSFORMS = 'hub-deleteTransforms';
	public const PERMISSION_DELETE_ASSETS = 'hub-deleteAssets';
	public const PERMISSION_SUSPEND_USERS = 'hub-suspendUsers';
	public const PERMISSION_MANAGE_LABELS = 'hub-manageLabels';
	public const PERMISSION_MANAGE_DIAGNOSTICS = 'hub-manageDiagnostics';
	public const PERMISSION_MANAGE_FILTERS = 'hub-manageFilters';
	public const PERMISSION_VIEW_ACTIVITY = 'hub-viewActivity';
	public const SITE_PARAM = 'hubSite';

	public bool $hasCpSection = true;
	public bool $hasCpSettings = true;
	public string $schemaVersion = '1.0.0';

	/** @var Site[]|null memoized, as the site scope is read for every count and link */
	private ?array $sites = null;

	/** @var int[]|null */
	private ?array $siteIds = null;

	public function init(): void
	{
		parent::init();

		Craft::setAlias('@hub', $this->getBasePath());

		$this->registerPermissions();
		$this->registerGarbageCollection();
		$this->registerActivityTracking();
		$this->registerContentTracking();

		if (Craft::$app->getRequest()->getIsCpRequest()) {
			$this->registerCpRoutes();
			$this->registerCpNavItems();
			$this->registerEntrySidebar();
		}
	}

	public static function config(): array
	{
		return [
			'components' => [
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
				'lists' => ['class' => ListService::class],
				'queries' => ['class' => QueryService::class],
				'references' => ['class' => ReferenceService::class],
				'review' => ['class' => ReviewService::class],
				'statistics' => ['class' => StatisticsService::class],
				'structure' => ['class' => StructureService::class],
				'labels' => ['class' => LabelService::class],
				'users' => ['class' => UserService::class],
				'volumes' => ['class' => VolumeService::class],
			],
		];
	}

	/**
	 * The sites selected via the comma-separated `hubSite` query param, like `?hubSite=en,de`; empty when all sites are in scope.
	 *
	 * @return Site[]
	 */
	public function getSites(): array
	{
		return $this->sites ??= $this->resolveSites();
	}

	/**
	 * @return Site[]
	 */
	private function resolveSites(): array
	{
		$request = Craft::$app->getRequest();
		$value = $request->getIsConsoleRequest() ? null : $request->getQueryParam(self::SITE_PARAM);
		$handles = array_unique(array_filter(array_map('trim', explode(',', is_string($value) ? $value : '')), fn (string $handle) => $handle !== ''));
		$editableIds = Craft::$app->getSites()->getEditableSiteIds();

		// Kept in the order of the sites, so the same selection always reads the same.
		return array_values(array_filter(
			Craft::$app->getSites()->getAllSites(),
			fn (Site $site) => in_array($site->handle, $handles, true) && in_array($site->id, $editableIds, false),
		));
	}

	/**
	 * The `hubSite` query param of the selected sites, or null when all sites are in scope.
	 */
	public function getSiteParam(): ?string
	{
		$sites = $this->getSites();

		return $sites !== [] ? implode(',', array_map(fn (Site $site) => $site->handle, $sites)) : null;
	}

	/**
	 * IDs of the sites counts are scoped to: the selected sites, or all editable sites.
	 *
	 * @return int[]
	 */
	public function getSiteIds(): array
	{
		if ($this->siteIds !== null) {
			return $this->siteIds;
		}

		$sites = $this->getSites();
		$siteIds = $sites !== [] ? array_map(fn (Site $site) => $site->id, $sites) : Craft::$app->getSites()->getEditableSiteIds();
		sort($siteIds);

		return $this->siteIds = $siteIds;
	}

	/**
	 * The plugin's own navigation item: the "Data" one, or, for users who may open none of its pages, the "Structure" or
	 * the "Hub" one, so Craft places it all the same. The others are added around it.
	 *
	 * @see RegisterCpNavItemsHandler
	 */
	public function getCpNavItem(): ?array
	{
		return $this->getDataNavItem() ?? $this->getStructureNavItem() ?? $this->getManageNavItem();
	}

	/**
	 * The "Data" navigation item, listing the content, with the pages the user may view. The "Hub" one is added right
	 * before it, the "Structure" one right after it.
	 *
	 * @see getStructureNavItem()
	 * @see getManageNavItem()
	 */
	public function getDataNavItem(): ?array
	{
		return $this->areaNavItem('hub/data', Craft::t('hub', 'Data'), 'database', [Page::Entries, Page::Assets, Page::Users]);
	}

	/**
	 * The "Structure" navigation item, listing what holds the content, with the pages the user may view.
	 */
	public function getStructureNavItem(): ?array
	{
		return $this->areaNavItem('hub/structure', Craft::t('hub', 'Structure'), 'sitemap', [Page::EntryTypes, Page::Sections, Page::Fields, Page::Volumes]);
	}

	/**
	 * Navigation item of an area with the pages the user may view, unless they may view none.
	 *
	 * @param Page[] $pages
	 */
	private function areaNavItem(string $path, string $label, string $icon, array $pages): ?array
	{
		$labels = [];
		foreach ($pages as $page) {
			if ($this->canView($page)) {
				$labels[$page->segment()] = $page->label();
			}
		}

		if ($labels === []) {
			return null;
		}

		return [
			'label' => $label,
			'url' => $path,
			'icon' => $icon,
			'subnav' => $this->subnav($path, $labels),
		];
	}

	/**
	 * The "Hub" navigation item, with the activity log, for users who may view it, and the settings page managing what
	 * Hub's pages share, like the labels of the lists, the checks of the diagnostics tabs and the saved filters of the
	 * lists, for users who may manage any of it; added before the "Data" one, unless the user may open neither.
	 */
	public function getManageNavItem(): ?array
	{
		$labels = array_filter([
			'activity' => $this->activity->canView() ? Craft::t('hub', 'Activity') : null,
			'settings' => $this->canManageSettings() ? Craft::t('hub', 'Settings') : null,
		]);

		if ($labels === []) {
			return null;
		}

		return [
			'label' => Craft::t('hub', 'Hub'),
			'url' => 'hub/manage',
			'icon' => $this->getBasePath() . '/icon-mask.svg',
			'subnav' => $this->subnav('hub/manage', $labels),
		];
	}

	/**
	 * Whether the current user may open the settings page, as they may manage the saved filters, the labels or the checks;
	 * admins may manage the settings of the statistics and the activity log too.
	 */
	public function canManageSettings(): bool
	{
		$user = Craft::$app->getUser();

		return $user->checkPermission(self::PERMISSION_MANAGE_LABELS)
			|| $user->checkPermission(self::PERMISSION_MANAGE_DIAGNOSTICS)
			|| $user->checkPermission(self::PERMISSION_MANAGE_FILTERS);
	}

	/**
	 * Whether the current user may view a page (see Page), by the page or its value: they may access its area and view it.
	 */
	public function canView(Page|string $page): bool
	{
		$page = is_string($page) ? Page::tryFrom($page) : $page;
		$user = Craft::$app->getUser();

		return $page !== null && $user->checkPermission($page->areaPermission()) && $user->checkPermission($page->viewPermission());
	}

	/**
	 * Whether the current user may label the items of a page; whether labels are enabled is up to LabelService::canApply().
	 */
	public function canLabel(Page $page): bool
	{
		return $this->canView($page) && Craft::$app->getUser()->checkPermission($page->labelPermission());
	}

	/**
	 * Whether the current user may select several items of a page at once, to apply the actions to all of them; without it,
	 * the actions are applied to one item at a time, from its context menu.
	 */
	public function canBulkEdit(Page $page): bool
	{
		return $this->canView($page) && Craft::$app->getUser()->checkPermission($page->bulkEditPermission());
	}

	/**
	 * Whether the current user may apply an action to the items of a page, by its permission (see Page::actionPermissions());
	 * Craft's own permissions of each item still apply.
	 */
	public function canAct(Page $page, string $permission): bool
	{
		return $this->canView($page) && isset($page->actionPermissions()[$permission]) && Craft::$app->getUser()->checkPermission($permission);
	}

	/**
	 * Whether the current user may open the pages of a list (see ColumnService), so its items can be selected and labeled.
	 */
	public function canViewList(string $list): bool
	{
		if ($list === ColumnService::LIST_ACTIVITY) {
			return $this->activity->canView();
		}

		return ($page = Page::fromList($list)) !== null && $this->canView($page);
	}

	/**
	 * Whether the current user may open the Hub page of the item, as they may view the page listing it.
	 */
	public function canViewPageOf(mixed $item): bool
	{
		return ($page = Page::fromItem($item)) !== null && $this->canView($page);
	}

	/**
	 * @param array<string,string> $labels labels of the pages below the path, by their path segment
	 * @return array<string,array{label:string,url:string,linkAttributes:array{href:string}}>
	 */
	private function subnav(string $path, array $labels): array
	{
		// Keep the selected sites when switching between Hub pages.
		$params = Craft::$app->getRequest()->getSegment(1) === 'hub' ? [self::SITE_PARAM => $this->getSiteParam()] : [];

		$items = [];
		foreach ($labels as $segment => $label) {
			// Craft only selects a subnav item whose URL is part of the request path, so it is the bare path, while the
			// link itself carries the selected sites.
			$items[$segment] = [
				'label' => $label,
				'url' => "$path/$segment",
				'linkAttributes' => ['href' => UrlHelper::cpUrl("$path/$segment", $params)],
			];
		}

		return $items;
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

	protected function registerEntrySidebar(): void
	{
		Event::on(
			Entry::class,
			Element::EVENT_DEFINE_SIDEBAR_HTML,
			new DefineEntrySidebarHtmlHandler()
		);
	}

	/**
	 * Removes the labels of items deleted for good and the expired activities, along with Craft's garbage.
	 */
	protected function registerGarbageCollection(): void
	{
		Event::on(
			Gc::class,
			Gc::EVENT_RUN,
			function () {
				$this->labels->deleteOrphans();
				$this->activity->deleteExpired();
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
	 * Keeps the index of the reference tags in the content and the last edits of entries, on every request.
	 */
	protected function registerContentTracking(): void
	{
		(new ContentTracker())->register();
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
