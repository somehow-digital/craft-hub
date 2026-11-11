<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\controllers\UserSettingsController;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use craft\web\Session;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\User as WebUser;
use LogicException;
use RuntimeException;
use SomehowDigital\Craft\Hub\bulk\BulkActions;
use SomehowDigital\Craft\Hub\handlers\activity\AccountActivity;
use SomehowDigital\Craft\Hub\handlers\activity\ConfigActivity;
use SomehowDigital\Craft\Hub\handlers\activity\ContentActivity;
use SomehowDigital\Craft\Hub\handlers\activity\SystemActivity;
use SomehowDigital\Craft\Hub\handlers\ActivityTracker;
use SomehowDigital\Craft\Hub\handlers\ContentTracker;
use SomehowDigital\Craft\Hub\handlers\DefineSidebarHtmlHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpNavItemsHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpRoutesHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterPermissionsHandler;
use SomehowDigital\Craft\Hub\handlers\TrashTracker;
use SomehowDigital\Craft\Hub\handlers\UserGroupSidebarHandler;
use SomehowDigital\Craft\Hub\jobs\IndexReferencesJob;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\ActivityFeedService;
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
use SomehowDigital\Craft\Hub\services\WorkService;
use SomehowDigital\Craft\Hub\statistics\EntriesSummary;
use SomehowDigital\Craft\Hub\statistics\EntryTypesSummary;
use SomehowDigital\Craft\Hub\statistics\FieldsSummary;
use SomehowDigital\Craft\Hub\statistics\FilesSummary;
use SomehowDigital\Craft\Hub\statistics\SectionsSummary;
use SomehowDigital\Craft\Hub\statistics\UsersSummary;
use SomehowDigital\Craft\Hub\statistics\VolumesSummary;
use SomehowDigital\Craft\Hub\widgets\MyWorkWidget;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event;
use yii\caching\CacheInterface;

/**
 * @property AccessService $access
 * @property ActivityService $activity
 * @property ActivityFeedService $feed
 * @property BulkActions $bulkActions
 * @property VolumesSummary $volumesSummary
 * @property UsersSummary $usersSummary
 * @property SectionsSummary $sectionsSummary
 * @property FilesSummary $filesSummary
 * @property FieldsSummary $fieldsSummary
 * @property EntryTypesSummary $entryTypesSummary
 * @property EntriesSummary $entriesSummary
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
 * @property WorkService $work
 * @property Settings $settings
 */
final class Hub extends Plugin
{
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

		$this->define();

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
			$this->registerWidgets();
		}
	}

	/**
	 * The services are shared, and get what they depend on in their constructors, which is resolved when they are created, as
	 * tests and projects replace the services of Craft.
	 */
	private function define(): void
	{
		Craft::$container->setSingletons([
			// <wiring>
			AccessService::class => fn(): AccessService => new AccessService($this->settings, Craft::createObject(ActivityService::class), $this->webUser()),
			ActivityFeedService::class => fn(): ActivityFeedService => new ActivityFeedService(Craft::createObject(UserService::class), Craft::createObject(QueryService::class), Craft::createObject(SiteScopeService::class), Craft::createObject(AccessService::class), Craft::createObject(LabelService::class), Craft::createObject(DiagnosticsService::class), Craft::createObject(FilterService::class), Craft::$app->getFields(), Craft::$app->getSites(), Craft::$app->getEntries(), Craft::$app->getVolumes(), Craft::$app->getUserGroups(), Craft::$app->getCategories(), Craft::$app->getTags(), Craft::$app->getGql(), $this->webUser()),
			ActivityService::class => fn(): ActivityService => new ActivityService($this->settings, Craft::$app->getDb(), $this->webRequest(), Craft::$app->getUsers(), $this->webUser(), $this->webSession()),
			BulkActions::class => fn(): BulkActions => new BulkActions(Craft::createObject(AccessService::class), Craft::createObject(ActivityService::class), Craft::createObject(BulkService::class), Craft::createObject(DeletionService::class), Craft::createObject(EntryService::class), Craft::createObject(FileService::class), Craft::createObject(UserService::class), Craft::createObject(TrashService::class), Craft::createObject(LabelService::class), Craft::createObject(SelectionService::class), Craft::$app->getElements(), Craft::$app->getUsers(), Craft::$app->getAssets(), Craft::$app->getEntries(), Craft::$app->getPath(), Craft::$app, static fn(): CmsEdition => Craft::$app->edition, $this->webUser()),
			BulkService::class => fn(): BulkService => new BulkService(Craft::createObject(SiteScopeService::class), Craft::createObject(EntryService::class), Craft::createObject(FileService::class), Craft::createObject(UserService::class), Craft::createObject(TrashService::class), Craft::createObject(ListService::class), Craft::$app->getElements()),
			CheckService::class => fn(): CheckService => new CheckService(Craft::createObject(DiagnosticsService::class), Craft::createObject(AccessService::class), Craft::createObject(SiteScopeService::class)),
			ColumnService::class => fn(): ColumnService => new ColumnService(Craft::createObject(MaintainerService::class), Craft::$app->getUsers(), $this->webUser()),
			DeletionService::class => fn(): DeletionService => new DeletionService(Craft::createObject(ReferenceService::class), Craft::createObject(FileService::class), Craft::createObject(UserService::class), Craft::$app->getElements(), Craft::$app->getFormatter()),
			DiagnosticsService::class => fn(): DiagnosticsService => new DiagnosticsService(Craft::createObject(FilterService::class), Craft::createObject(QueryService::class), Craft::createObject(SiteScopeService::class), Craft::createObject(EntryService::class), Craft::createObject(FileService::class), Craft::createObject(UserService::class), Craft::createObject(BulkService::class), Craft::createObject(ListFilterService::class), Craft::$app->getDb()),
			EditService::class => fn(): EditService => new EditService(Craft::createObject(UserService::class), Craft::$app->getDb(), $this->webRequest(), $this->webUser()),
			EntriesSummary::class => fn(): EntriesSummary => new EntriesSummary(Craft::createObject(SiteScopeService::class), Craft::createObject(SnapshotService::class), Craft::createObject(StructureService::class), Craft::createObject(VolumeService::class), Craft::createObject(StatisticsService::class), Craft::createObject(EntryService::class), Craft::createObject(FilterService::class), Craft::createObject(ReviewService::class)),
			EntryService::class => fn(): EntryService => new EntryService(Craft::createObject(ReviewService::class), Craft::createObject(SiteScopeService::class), Craft::createObject(QueryService::class), Craft::createObject(LabelService::class), Craft::createObject(FilterService::class), Craft::createObject(UserService::class), Craft::createObject(EditService::class), Craft::$app->getEntries(), Craft::$app->getFields(), Craft::$app->getElements(), Craft::$app->getRequest(), $this->webUser()),
			EntryTypesSummary::class => fn(): EntryTypesSummary => new EntryTypesSummary(Craft::createObject(SiteScopeService::class), Craft::createObject(SnapshotService::class), Craft::createObject(StructureService::class), Craft::createObject(VolumeService::class), Craft::createObject(StatisticsService::class), Craft::createObject(EntryService::class), Craft::createObject(FilterService::class)),
			FieldsSummary::class => fn(): FieldsSummary => new FieldsSummary(Craft::createObject(SiteScopeService::class), Craft::createObject(SnapshotService::class), Craft::createObject(StructureService::class), Craft::createObject(VolumeService::class), Craft::createObject(StatisticsService::class)),
			FileService::class => fn(): FileService => new FileService(Craft::createObject(ReviewService::class), Craft::createObject(SiteScopeService::class), Craft::createObject(QueryService::class), Craft::createObject(LabelService::class), Craft::createObject(FilterService::class), Craft::createObject(UserService::class), Craft::createObject(ActivityService::class), Craft::$app->getDb(), Craft::$app->getElements(), Craft::$app->getImageTransforms(), Craft::$app->getVolumes(), Craft::$app->getAssets(), Craft::$app->getRequest(), $this->webUser()),
			FilesSummary::class => fn(): FilesSummary => new FilesSummary(Craft::createObject(SiteScopeService::class), Craft::createObject(SnapshotService::class), Craft::createObject(StructureService::class), Craft::createObject(VolumeService::class), Craft::createObject(StatisticsService::class), Craft::createObject(FileService::class)),
			FilterService::class => fn(): FilterService => new FilterService(Craft::createObject(SiteScopeService::class), Craft::$app->getDb(), $this->webUser()),
			LabelService::class => fn(): LabelService => new LabelService($this->settings, Craft::createObject(AccessService::class), Craft::createObject(ActivityService::class), Craft::$app->getDb(), Craft::$app->getEntries(), Craft::$app->getFields(), Craft::$app->getVolumes(), Craft::$app->getUserGroups(), $this->webUser()),
			ListFilterService::class => fn(): ListFilterService => new ListFilterService(Craft::createObject(LabelService::class), Craft::createObject(FilterService::class), Craft::createObject(StructureService::class), Craft::createObject(EntryService::class), Craft::createObject(VolumeService::class), Craft::createObject(UserService::class), Craft::$app->getEntries(), Craft::$app->getVolumes()),
			ListService::class => fn(): ListService => new ListService(Craft::createObject(FilterService::class), Craft::createObject(StructureService::class), Craft::createObject(VolumeService::class), Craft::createObject(LabelService::class)),
			MaintainerService::class => fn(): MaintainerService => new MaintainerService($this->settings, Craft::createObject(AccessService::class), Craft::createObject(ActivityService::class), Craft::createObject(UserService::class), Craft::$app->getEntries(), Craft::$app->getFields(), Craft::$app->getVolumes(), Craft::$app->getUserGroups(), Craft::$app->getDb(), $this->webUser()),
			NavigationService::class => fn(): NavigationService => new NavigationService(Craft::createObject(AccessService::class), Craft::createObject(WorkService::class), Craft::createObject(ActivityService::class), Craft::createObject(SiteScopeService::class), $this->webRequest()),
			QueryService::class => fn(): QueryService => new QueryService(Craft::createObject(SiteScopeService::class), Craft::$app->getDb(), Craft::$app->getSites()),
			ReferenceService::class => fn(): ReferenceService => new ReferenceService(Craft::$app->getSites(), Craft::$app->getElements(), Craft::$app->getFields(), Craft::$app->getDb()),
			ReviewService::class => fn(): ReviewService => new ReviewService(Craft::createObject(SiteScopeService::class), Craft::createObject(FilterService::class), Craft::createObject(QueryService::class), Craft::createObject(EditService::class)),
			SectionsSummary::class => fn(): SectionsSummary => new SectionsSummary(Craft::createObject(SiteScopeService::class), Craft::createObject(SnapshotService::class), Craft::createObject(StructureService::class), Craft::createObject(VolumeService::class), Craft::createObject(StatisticsService::class), Craft::createObject(AccessService::class), Craft::createObject(EntryService::class), Craft::createObject(FilterService::class)),
			SelectionService::class => fn(): SelectionService => new SelectionService(Craft::createObject(BulkService::class), Craft::createObject(SiteScopeService::class), $this->webRequest(), $this->webSession()),
			SiteScopeService::class => fn(): SiteScopeService => new SiteScopeService(Craft::$app->getSites(), Craft::$app->getRequest(), $this->webSession()),
			SnapshotService::class => fn(): SnapshotService => new SnapshotService(Craft::createObject(StatisticsService::class), Craft::createObject(StructureService::class), Craft::createObject(VolumeService::class), Craft::createObject(UserService::class), $this->settings, Craft::$app->getDb(), $this->cache()),
			StatisticsService::class => fn(): StatisticsService => new StatisticsService(Craft::createObject(SiteScopeService::class), Craft::$app->getDb()),
			StructureService::class => fn(): StructureService => new StructureService(Craft::createObject(SiteScopeService::class), Craft::createObject(StatisticsService::class), Craft::$app->getEntries(), Craft::$app->getFields(), Craft::$app->getSites(), Craft::$app->getVolumes(), Craft::$app->getCategories(), Craft::$app->getTags(), Craft::$app->getGlobals()),
			TrashService::class => fn(): TrashService => new TrashService(Craft::createObject(EntryService::class), Craft::createObject(SiteScopeService::class), Craft::createObject(ReviewService::class), Craft::createObject(QueryService::class), Craft::createObject(FileService::class), Craft::createObject(UserService::class), Craft::$app->getVolumes(), Craft::$app->getDb()),
			UserService::class => fn(): UserService => new UserService(Craft::createObject(QueryService::class), Craft::createObject(LabelService::class), Craft::createObject(FilterService::class), Craft::$app->getUserGroups(), Craft::$app->getUserPermissions(), Craft::$app->getElements(), Craft::$app->getProjectConfig(), Craft::$app->getDb(), static fn(): CmsEdition => Craft::$app->edition, $this->webUser()),
			UsersSummary::class => fn(): UsersSummary => new UsersSummary(Craft::createObject(SiteScopeService::class), Craft::createObject(SnapshotService::class), Craft::createObject(StructureService::class), Craft::createObject(VolumeService::class), Craft::createObject(StatisticsService::class), Craft::createObject(FilterService::class), Craft::createObject(UserService::class), Craft::$app->getUserGroups()),
			VolumeService::class => fn(): VolumeService => new VolumeService(Craft::createObject(SiteScopeService::class), Craft::createObject(StatisticsService::class), Craft::$app->getVolumes(), Craft::$app->getFields()),
			VolumesSummary::class => fn(): VolumesSummary => new VolumesSummary(Craft::createObject(SiteScopeService::class), Craft::createObject(SnapshotService::class), Craft::createObject(StructureService::class), Craft::createObject(VolumeService::class), Craft::createObject(StatisticsService::class), Craft::createObject(FileService::class)),
			WorkService::class => fn(): WorkService => new WorkService(Craft::createObject(SiteScopeService::class), $this->settings, Craft::createObject(MaintainerService::class), Craft::createObject(AccessService::class), Craft::createObject(DiagnosticsService::class), Craft::createObject(EntryService::class), Craft::createObject(StructureService::class), Craft::createObject(CheckService::class), Craft::createObject(VolumeService::class), Craft::createObject(QueryService::class), $this->cache(), Craft::$app->getDb(), $this->webUser()),
			// </wiring>
		]);
	}

	/**
	 * The user of the web application, which the console has none of, where what takes it acts without one.
	 */
	private function webUser(): ?WebUser
	{
		return Craft::$app instanceof WebApplication ? Craft::$app->getUser() : null;
	}

	private function webSession(): ?Session
	{
		return Craft::$app instanceof WebApplication ? Craft::$app->getSession() : null;
	}

	private function cache(): CacheInterface
	{
		return Craft::$app->getCache() ?? throw new LogicException('The app has no cache.');
	}

	private function webRequest(): ?WebRequest
	{
		return Craft::$app instanceof WebApplication ? Craft::$app->getRequest() : null;
	}

	private function webResponse(): ?WebResponse
	{
		return Craft::$app instanceof WebApplication ? Craft::$app->getResponse() : null;
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
				'feed' => ['class' => ActivityFeedService::class],
				'bulk' => ['class' => BulkService::class],
				'bulkActions' => ['class' => BulkActions::class],
				'volumesSummary' => ['class' => VolumesSummary::class],
				'usersSummary' => ['class' => UsersSummary::class],
				'sectionsSummary' => ['class' => SectionsSummary::class],
				'filesSummary' => ['class' => FilesSummary::class],
				'fieldsSummary' => ['class' => FieldsSummary::class],
				'entryTypesSummary' => ['class' => EntryTypesSummary::class],
				'entriesSummary' => ['class' => EntriesSummary::class],
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
				'work' => ['class' => WorkService::class],
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
	 * adding some has to do so in a migration too. The last edits of the entries already there are backfilled, and their references indexed in the background.
	 */
	protected function afterInstall(): void
	{
		$this->edits->backfill();
		Queue::push(new IndexReferencesJob());
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
	public function getSettingsResponse(): WebResponse
	{
		return $this->webResponse()?->redirect(UrlHelper::cpUrl('hub/manage/settings')) ?? throw new LogicException('Settings open in the control panel.');
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
			fn(RegisterCpNavItemsEvent $event) => $this->navHandler()($event)
		);
	}

	protected function registerSidebar(): void
	{
		// The handlers are created once the events happen, with the services they need.
		foreach ([Entry::class, Asset::class, User::class] as $class) {
			Event::on($class, Element::EVENT_DEFINE_SIDEBAR_HTML, fn(DefineHtmlEvent $event) => $this->sidebarHandler()($event));
		}

		Event::on(UserSettingsController::class, Controller::EVENT_AFTER_ACTION, fn(ActionEvent $event) => $this->groupSidebarHandler()($event));
	}

	/**
	 * Removes the labels and maintainers of items deleted for good, the expired activities and snapshots, and the files
	 * kept of trashed assets Craft deleted permanently, along with Craft's garbage.
	 */
	protected function registerWidgets(): void
	{
		Event::on(
			Dashboard::class,
			Dashboard::EVENT_REGISTER_WIDGET_TYPES,
			function (RegisterComponentTypesEvent $event): void {
				$event->types[] = MyWorkWidget::class;
			}
		);
	}

	protected function registerGarbageCollection(): void
	{
		Event::on(
			Gc::class,
			Gc::EVENT_RUN,
			function (): void {
				$this->labels->deleteOrphans();
				$this->maintainers->deleteOrphans();
				$this->activity->deleteExpired();
				$this->snapshots->deleteExpired();
				$this->trash->deletePurgedFiles();
				$this->work->deleteExpired();
			}
		);
	}

	/**
	 * Records what users do in the activity log, on every request, while it's enabled.
	 */
	protected function registerActivityTracking(): void
	{
		if ($this->settings->enableActivity) {
			(new ActivityTracker(
				new ContentActivity($this->activity, $this->settings, Craft::$app->getElements(), $this->webRequest()),
				new AccountActivity($this->activity, Craft::$app->getUsers(), Craft::$app->getUserGroups(), $this->cache(), $this->webRequest(), $this->webResponse(), $this->webUser()),
				new ConfigActivity($this->activity, static fn(): string => Craft::$app->getVersion(), Craft::$app->getUserGroups(), Craft::$app->getPlugins(), Craft::$app->getProjectConfig(), Craft::$app->getGql()),
				new SystemActivity($this->activity, Craft::$app->getVolumes(), $this->webRequest(), $this->webResponse()),
			))->register();
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
			fn() => $this->snapshots->pushIfDue()
		);
	}

	/**
	 * Keeps the index of the reference tags in the content and the last edits of entries, on every request.
	 */
	protected function registerContentTracking(): void
	{
		$this->contentTracker()->register();
	}

	/**
	 * Keeps the files of assets moved to the trash, on every request, while it's enabled, see TrashTracker.
	 */
	protected function registerTrashTracking(): void
	{
		if ($this->settings->keepTrashedFiles) {
			$this->trashTracker()->register();
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

	protected function navHandler(): RegisterCpNavItemsHandler
	{
		return new RegisterCpNavItemsHandler($this->navigation);
	}

	protected function sidebarHandler(): DefineSidebarHtmlHandler
	{
		return new DefineSidebarHtmlHandler(
			$this->access,
			$this->maintainers,
			$this->labels,
			$this->checks,
			$this->edits,
			$this->references,
			$this->activity,
			$this->feed,
			$this->settings,
			Craft::$app->getView(),
		);
	}

	protected function groupSidebarHandler(): UserGroupSidebarHandler
	{
		return new UserGroupSidebarHandler($this->access, $this->labels, $this->maintainers, $this->activity, $this->feed, Craft::$app->getView(), Craft::$app->getUserGroups());
	}

	protected function contentTracker(): ContentTracker
	{
		return new ContentTracker($this->references, $this->edits, $this->work);
	}

	protected function trashTracker(): TrashTracker
	{
		return new TrashTracker($this->trash);
	}
}
