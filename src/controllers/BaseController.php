<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Closure;
use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Volume;
use craft\web\Controller;
use craft\web\View;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\FilterParams;
use SomehowDigital\Craft\Hub\helpers\ListSelection;
use SomehowDigital\Craft\Hub\helpers\ListSort;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\services\SnapshotService;
use SomehowDigital\Craft\Hub\services\UserService;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

abstract class BaseController extends Controller
{
	/** tabs of the detail pages, each on its own URL, the overview on that of the page itself */
	public const TAB_OVERVIEW = 'overview';
	public const TAB_STATISTICS = 'statistics';
	public const TAB_DIAGNOSTICS = 'diagnostics';
	public const TAB_ENTRIES = 'entries';
	public const TAB_ASSETS = 'assets';
	public const TAB_TRANSFORMS = 'transforms';
	public const TAB_HISTORY = 'history';

	/** header of the requests loading a list in place, as its filters change or the site scope does */
	public const FILTERS_HEADER = 'X-Hub-Filters';

	/** query param of a statistics tab loading the card of that key alone, see Summary::lazyCard() */
	public const CARD_PARAM = 'card';

	/** query param of a card of a statistics tab with the days its chart spans, see SnapshotService::chart() */
	public const RANGE_PARAM = 'range';

	protected array|int|bool $allowAnonymous = false;

	/** page of the controller (see Page), which the user must be able to view; controllers without one check their actions */
	protected ?Page $page = null;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) {
			return false;
		}

		$this->requireCpRequest();

		if ($this->page !== null && !Hub::getInstance()->canView($this->page)) {
			throw new ForbiddenHttpException('User is not authorized to perform this action.');
		}

		// The diagnostics tabs of the index pages are only there while diagnostics are enabled.
		if ($action->id === 'diagnostics' && !Hub::getInstance()->settings->enableDiagnostics) {
			throw new NotFoundHttpException('Diagnostics are disabled.');
		}

		// The statistics tabs of the index pages are only there while statistics are enabled.
		if ($action->id === 'statistics' && !Hub::getInstance()->settings->enableStatistics) {
			throw new NotFoundHttpException('Statistics are disabled.');
		}

		// The trash tabs of the index pages are only there while the trash is enabled, for users who may view it.
		if ($action->id === 'trash' && $this->page !== null) {
			if (!Hub::getInstance()->settings->enableTrash) {
				throw new NotFoundHttpException('The trash is disabled.');
			}

			if (!Hub::getInstance()->canViewTrash($this->page)) {
				throw new ForbiddenHttpException('User is not authorized to perform this action.');
			}
		}

		return true;
	}

	/**
	 * Adds the site scope and whether diagnostics and statistics are enabled to every Hub template.
	 */
	public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
	{
		$hub = Hub::getInstance();

		return parent::renderTemplate($template, [
			'selectedSites' => $hub->getSites(),
			'hubSite' => $hub->getSiteParam(),
			'siteIds' => $hub->getSiteIds(),
			'diagnosticsEnabled' => $hub->settings->enableDiagnostics,
			'statisticsEnabled' => $hub->settings->enableStatistics,
			...$variables,
		], $templateMode);
	}

	/**
	 * CP URL to a Hub page that keeps the selected sites.
	 *
	 * @param array<string,mixed> $params
	 */
	protected function hubUrl(string $path, array $params = []): string
	{
		return Hub::getInstance()->pageUrl($path, $params);
	}

	/**
	 * Tabs of the entries and assets index pages between their list and their statistics: the trash, while it's enabled,
	 * for users who may view it.
	 *
	 * @return array<string,string>
	 */
	protected function trashTabs(): array
	{
		return $this->page !== null && Hub::getInstance()->canViewTrash($this->page) ? ['trash' => Craft::t('hub', 'Trash')] : [];
	}

	/**
	 * Variables of the tabs of the settings page that edit the plugin's settings, for admins: the settings, those that failed
	 * to save passed back, the names of the ones overridden by `config/hub.php`, and whether they're read-only, as they're
	 * kept in the project config; none for other users, who may not change them.
	 *
	 * @return array<string,mixed>
	 */
	protected function settingsVariables(?Settings $settings = null): array
	{
		if (!Craft::$app->getUser()->getIsAdmin()) {
			return [];
		}

		$hub = Hub::getInstance();

		return [
			'settings' => $settings ?? $hub->settings,
			'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile($hub->handle)),
			'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
		];
	}

	/**
	 * Tab of a detail page selected by its URL, between the overview and the diagnostics, which are only there while enabled,
	 * as are the statistics.
	 *
	 * @param string[] $tabs tabs the page has besides those, like one listing its entries or the statistics
	 * @throws NotFoundHttpException for tabs the page doesn't have
	 */
	protected function detailTab(string $tab, array $tabs = []): string
	{
		if ($tab === self::TAB_DIAGNOSTICS && !Hub::getInstance()->settings->enableDiagnostics) {
			throw new NotFoundHttpException('Diagnostics are disabled.');
		}

		if ($tab === self::TAB_STATISTICS && !Hub::getInstance()->settings->enableStatistics) {
			throw new NotFoundHttpException('Statistics are disabled.');
		}

		if (!in_array($tab, [self::TAB_OVERVIEW, ...$tabs, self::TAB_DIAGNOSTICS], true)) {
			throw new NotFoundHttpException('Tab not found.');
		}

		// The tabs listing entries or assets are those of their pages, which the user may not view.
		if (in_array($tab, [self::TAB_ENTRIES, self::TAB_ASSETS], true) && !Hub::getInstance()->canView($tab)) {
			throw new ForbiddenHttpException('User is not authorized to perform this action.');
		}

		return $tab;
	}

	/**
	 * Keeps the filters and sorting of a list page in the session, so coming back to it shows them again.
	 * Requests with query params keep theirs, as do the requests loading the list in place, which leave out empty filters;
	 * other requests without any, like those of the nav, are redirected to the kept ones.
	 * Reviewing the selection ignores the filters, so they are kept as they are.
	 *
	 * @return Response|null the redirect to the kept filters, if any
	 */
	protected function keepFilters(string $path): ?Response
	{
		$request = Craft::$app->getRequest();

		if (ListSelection::isRequested()) {
			return null;
		}

		$session = Craft::$app->getSession();
		$key = "hub:filters:$path";
		$params = FilterParams::listParams($request->getQueryParamsWithoutPath());

		if ($params !== [] || $request->getHeaders()->has(self::FILTERS_HEADER)) {
			$params !== [] ? $session->set($key, $params) : $session->remove($key);

			return null;
		}

		// Kept params are filtered again, as redirecting without any would redirect again.
		$kept = FilterParams::listParams($session->get($key));

		return $kept !== [] && $request->getPageNum() === 1 ? $this->redirect($this->hubUrl($path, $kept)) : null;
	}

	/**
	 * Config of a condition posted by its builder, as a condition of the kind with the rules posted, whatever else was
	 * posted along; null without any rule.
	 *
	 * @return array<string,mixed>|null
	 */
	protected function conditionConfig(CheckKind $kind, mixed $posted): ?array
	{
		$rules = is_array($posted) && is_array($posted['conditionRules'] ?? null) ? $posted['conditionRules'] : [];
		$config = $kind->createCondition(['conditionRules' => $rules])->getConfig();

		return ($config['conditionRules'] ?? []) !== [] ? $config : null;
	}

	/**
	 * The query params of the request, read as the values of a list's filters.
	 */
	protected function filterParamsOfRequest(): FilterParams
	{
		return new FilterParams(Craft::$app->getRequest()->getQueryParamsWithoutPath());
	}

	/**
	 * Labels filter of a list, like `?label=1,4` or `?label=none` for unlabeled items.
	 *
	 * @return string[]
	 */
	protected function labelsParam(): array
	{
		return $this->filterParamsOfRequest()->list('label', Hub::getInstance()->labels->getFilterValues());
	}

	/**
	 * The filters of the list of a kind, set by the query params of the request.
	 */
	protected function listFilterFromRequest(CheckKind $kind): object
	{
		return Hub::getInstance()->filters->createListFilter($kind, Craft::$app->getRequest()->getQueryParamsWithoutPath());
	}

	/**
	 * Template variables of the saved filters of a list (see the `saved-filters` component).
	 *
	 * @param string $path page the list is on, which the filters link to
	 * @param string|null $current UID of the custom filter applied
	 * @return array<string,mixed>
	 */
	protected function savedFilterVariables(CheckKind $kind, string $path, ?string $current): array
	{
		return ['savedFilters' => Hub::getInstance()->filters->getMenu($kind, $path, $current)];
	}

	/**
	 * Template variables of the labels of a list (see the `labels` and `labelPicker` macros): all labels, to label its items with
	 * and filter it by, and the labels of the items shown, by their ID; none while labels are disabled, so they're shown nowhere.
	 *
	 * @param int[] $ids IDs of the items shown
	 * @return array<string,mixed>
	 */
	protected function labelVariables(string $list, array $ids): array
	{
		/** @var LabelService $labels */
		$labels = Hub::getInstance()->labels;

		if (!$labels->isEnabled()) {
			return ['labelList' => $list, 'labels' => [], 'labelOptions' => [], 'itemLabels' => [], 'canApplyLabels' => false, 'canManageLabels' => false];
		}

		$all = $labels->getAllLabels();

		// Options of the filter, by their value, which Twig would renumber when merging them.
		$options = [];
		foreach ($all as $id => $label) {
			$options[$id] = ['label' => $label->name, 'icon' => $label->getIcon(), 'color' => $label->getColor()];
		}

		if ($options !== []) {
			$options[LabelService::LABEL_NONE] = ['label' => Craft::t('hub', 'Unlabeled'), 'color' => 'gray'];
		}

		return [
			'labelList' => $list,
			'labels' => $all,
			'labelOptions' => $options,
			'itemLabels' => $labels->getLabelsOf($list, $ids),
			'canApplyLabels' => $labels->canApply($list),
			'canManageLabels' => $labels->canManage(),
		];
	}

	/**
	 * Variables of the entries list, filtered, sorted and paginated on the server.
	 * On section, entry type, field and user pages, it is limited to the entries of that section, entry type, nested entry field or author.
	 *
	 * @param string $path page the list is on, for its filters and sorting
	 * @return array<string,mixed>
	 */
	protected function entryListVariables(string $path, ?Section $section = null, ?EntryType $entryType = null, ?FieldInterface $field = null, ?User $author = null): array
	{
		$hub = Hub::getInstance();
		/** @var EntryService $entries */
		$entries = $hub->entries;
		$months = $hub->filters->getThreshold(CheckKind::Entries, 'stale');
		$entryTypes = $hub->structure->getEntryTypes();
		$nestedFields = array_filter($hub->structure->getFields(), fn (FieldInfo $info) => $info->isNested);

		// While reviewing the selection, only the list's own limits apply.
		$selection = ListSelection::fromRequest(ColumnService::LIST_ENTRIES, $path);
		/** @var EntryFilter $filter */
		$filter = $selection->review ? new EntryFilter() : $this->listFilterFromRequest(CheckKind::Entries);
		$filter->sections = $section !== null ? [$section->handle] : $filter->sections;
		$filter->types = $entryType !== null ? [$entryType->handle] : $filter->types;
		$filter->fields = $field !== null ? [$field->handle] : $filter->fields;
		$filter->authors = $author !== null ? [$author->id] : $filter->authors;

		// Only sections, nested entry fields and entry types used together, with the ones the list is limited to.
		$sectionIds = [];
		$fieldIds = [];
		$typeIds = [];
		foreach ($entryTypes as $info) {
			if ($entryType !== null && $info->entryType->id !== $entryType->id) {
				continue;
			}

			foreach ($info->sections as $typeSection) {
				if ($field === null && ($section === null || $typeSection->id === $section->id)) {
					$sectionIds[$typeSection->id] = true;
					$typeIds[$info->entryType->id] = true;
				}
			}

			foreach ($info->nestedFields as $nestedField) {
				if ($section === null && ($field === null || $nestedField->id === $field->id)) {
					$fieldIds[$nestedField->id] = true;
					$typeIds[$info->entryType->id] = true;
				}
			}
		}

		$listFixed = match (true) {
			$section !== null => 'section',
			$entryType !== null => 'type',
			$field !== null => 'field',
			$author !== null => 'author',
			default => null,
		};

		// Section entries have no field or owner, and nested entries no section. On a user's page, they are the author.
		$columns = $hub->columns->getColumns(ColumnService::LIST_ENTRIES, [
			...match (true) {
				$section !== null || $fieldIds === [] => ['field', 'owner'],
				$field !== null || $sectionIds === [] => ['section'],
				default => [],
			},
			...($author !== null ? ['creator'] : []),
		]);
		$shown = $hub->columns->getShownKeys($columns);

		$sort = ListSort::fromRequest($entries->getSortColumns(), EntryService::SORT_UPDATED);

		$query = $entries->getQuery($filter, $sort->column, $sort->direction);
		$selection->limitQuery($query);

		// The owners of nested entries and the authors are shown with them.
		$with = array_keys(array_intersect(['owner' => 'owner', 'authors' => 'creator'], $shown));
		if ($with !== []) {
			$query->with($with);
		}

		[$pageInfo, $results] = Paginator::fromRequest()->elements($query);

		$referenceCounts = in_array('references', $shown, true)
			? $hub->references->getReferenceCounts(array_map(fn (Entry $entry) => $entry->id, $results))
			: [];

		return [
			'entries' => $results,
			'pageInfo' => $pageInfo,
			'referenceCounts' => $referenceCounts,
			'lastEdited' => in_array('updated', $shown, true) ? $hub->edits->getLastEdited($results) : [],
			'sections' => array_filter($hub->structure->getSections(), fn (SectionInfo $info) => isset($sectionIds[$info->section->id])),
			'nestedFields' => array_filter($nestedFields, fn (FieldInfo $info) => isset($fieldIds[$info->field->id])),
			'entryTypes' => array_filter($entryTypes, fn (EntryTypeInfo $info) => isset($typeIds[$info->entryType->id])),
			'statuses' => array_intersect_key(Entry::statuses(), array_flip($entries->getStatuses())),
			'staleMonths' => $months,
			'authorOptions' => $author !== null ? [] : $this->userOptions($entries->getAuthors($section?->id, $entryType?->id, $field?->id)),
			'editorOptions' => $this->userOptions($hub->edits->getEditors($section?->id, $entryType?->id, $field?->id)),
			'filter' => $filter,
			'listPath' => $path,
			'listFixed' => $listFixed,
			'columns' => $columns,
			...$this->savedFilterVariables(CheckKind::Entries, $path, $filter->savedFilter),
			...$this->labelVariables(ColumnService::LIST_ENTRIES, array_map(fn (Entry $entry) => $entry->id, $results)),
			...$selection->variables($filter),
			...$sort->variables($path),
		];
	}

	/**
	 * Variables of the files list, filtered, sorted and paginated on the server.
	 * On volume and user pages, it is limited to the files of that volume or uploader.
	 *
	 * @param string $path page the list is on, for its filters and sorting
	 * @return array<string,mixed>
	 */
	protected function fileListVariables(string $path, ?Volume $volume = null, ?User $uploader = null): array
	{
		$hub = Hub::getInstance();
		/** @var FileService $files */
		$files = $hub->files;
		$fileKinds = $hub->volumes->getFileKinds();

		$selection = ListSelection::fromRequest(ColumnService::LIST_FILES, $path);
		/** @var FileFilter $filter */
		$filter = $selection->review ? new FileFilter() : $this->listFilterFromRequest(CheckKind::Assets);
		$filter->volumes = $volume !== null ? [$volume->handle] : $filter->volumes;
		$filter->uploaders = $uploader !== null ? [$uploader->id] : $filter->uploaders;

		// On a user's page, they are the uploader.
		$columns = $hub->columns->getColumns(ColumnService::LIST_FILES, [
			...($volume !== null ? ['volume'] : []),
			...($uploader !== null ? ['uploader'] : []),
		]);

		$sort = ListSort::fromRequest($files->getSortColumns(), FileService::SORT_UPLOADED);

		$query = $files->getQuery($filter, $sort->column, $sort->direction);
		$selection->limitQuery($query);

		// The uploaders are shown with their files.
		if (in_array('uploader', $hub->columns->getShownKeys($columns), true)) {
			$query->with(['uploader']);
		}

		[$pageInfo, $assets] = Paginator::fromRequest()->elements($query);

		// Files without references are flagged next to their name, which is always shown.
		$referenceCounts = $hub->references->getReferenceCounts(array_map(fn (Asset $asset) => $asset->id, $assets));

		return [
			'assets' => $assets,
			'pageInfo' => $pageInfo,
			'referenceCounts' => $referenceCounts,
			'canDownload' => $hub->canAct(Page::Assets, Hub::PERMISSION_DOWNLOAD_ASSETS),
			'fileKinds' => $fileKinds,
			'volumes' => $hub->volumes->getVolumes(),
			'extensions' => $files->getExtensions($volume?->id),
			'uploaderOptions' => $uploader !== null ? [] : $this->userOptions($files->getUploaders($volume?->id)),
			'filter' => $filter,
			'listPath' => $path,
			'listFixed' => match (true) {
				$volume !== null => 'volume',
				$uploader !== null => 'uploader',
				default => null,
			},
			'columns' => $columns,
			...$this->savedFilterVariables(CheckKind::Assets, $path, $filter->savedFilter),
			...$this->labelVariables(ColumnService::LIST_FILES, array_map(fn (Asset $asset) => $asset->id, $assets)),
			...$selection->variables($filter),
			...$sort->variables($path),
		];
	}

	/**
	 * Options of a filter by users, like the authors of entries: the user viewing the list first, as `me`, which saved
	 * filters keep to list each user's own items, then the users given.
	 *
	 * @param array<int,array{user:User,count:int}> $users keyed by user ID
	 * @return array<int|string,array{label:string,user:User}>
	 */
	protected function userOptions(array $users): array
	{
		$options = [];
		$me = Craft::$app->getUser()->getIdentity();

		if ($me !== null) {
			$options[FilterParams::ME] = ['label' => Craft::t('hub', 'Me'), 'user' => $me];
		}

		foreach ($users as $id => $item) {
			$options[$id] = ['label' => (string)$item['user']->name, 'user' => $item['user']];
		}

		return $options;
	}

	/**
	 * Variables of the users list, filtered, sorted and paginated on the server.
	 *
	 * @param string $path page the list is on, for its filters and sorting
	 * @return array<string,mixed>
	 */
	protected function userListVariables(string $path): array
	{
		$hub = Hub::getInstance();
		/** @var UserService $users */
		$users = $hub->users;
		$groups = $users->getGroups();

		$selection = ListSelection::fromRequest(ColumnService::LIST_USERS, $path);
		/** @var UserFilter $filter */
		$filter = $selection->review ? new UserFilter() : $this->listFilterFromRequest(CheckKind::Users);

		// Craft Solo has no groups, so neither the column nor the filter is offered.
		$columns = $hub->columns->getColumns(ColumnService::LIST_USERS, $groups === [] ? ['groups'] : []);
		$shown = $hub->columns->getShownKeys($columns);

		$sort = ListSort::fromRequest($users->getSortColumns(), UserService::SORT_CREATED);

		// Their photos are shown next to their names.
		$query = $users->getQuery($filter, $sort->column, $sort->direction)->with(['photo']);
		$selection->limitQuery($query);

		if (in_array('groups', $shown, true)) {
			$query->withGroups();
		}

		[$pageInfo, $results] = Paginator::fromRequest()->elements($query);
		$ids = array_map(fn (User $user) => $user->id, $results);
		$twoFactor = in_array('twoFactor', $shown, true) ? $users->getTwoFactor($ids) : [];

		return [
			'users' => $results,
			'pageInfo' => $pageInfo,
			'entryCounts' => in_array('entries', $shown, true) ? $users->getEntryCounts($ids) : [],
			'twoFactor' => $twoFactor,
			'groups' => $groups,
			'statuses' => User::statuses(),
			'idleMonths' => $hub->filters->getThreshold(CheckKind::Users, 'idle'),
			'filter' => $filter,
			'listPath' => $path,
			'columns' => $columns,
			...$this->savedFilterVariables(CheckKind::Users, $path, $filter->savedFilter),
			...$this->labelVariables(ColumnService::LIST_USERS, $ids),
			...$selection->variables($filter),
			...$sort->variables($path),
		];
	}

	/**
	 * Previews deleting the transforms of the files with the IDs, or only those with the transform IDs of them, as JSON like
	 * BulkController::actionPreview(): the files by the page asked for, along with their transforms, the totals covering
	 * all of them.
	 *
	 * @param int[] $ids
	 * @param int[]|null $transformIds
	 */
	protected function transformsPreview(array $ids, int $page, ?array $transformIds = null): Response
	{
		/** @var FileService $files */
		$files = Hub::getInstance()->files;
		[$assets, $transforms] = $files->getDeletableTransforms($ids, $transformIds);
		[$pageInfo, $shown] = (new Paginator($page))->items($assets);

		return $this->asJson([
			'total' => count($assets),
			'html' => $this->getView()->renderTemplate('hub/_components/transforms-preview', [
				'assets' => $shown,
				'transforms' => $transforms,
				'pageInfo' => $pageInfo,
				'count' => array_sum(array_map('count', $transforms)),
				'skipped' => $transformIds === null ? count(array_unique($ids)) - count($assets) : 0,
			], View::TEMPLATE_MODE_CP),
		]);
	}

	/**
	 * Panel of the card of a statistics tab requested by its key (see Summary::lazyCard()), with its data loaded.
	 *
	 * @param array<int,array{key:string,label:string,icon:string,load:Closure,history?:Closure}> $cards
	 * @return Response|null null unless a card is requested
	 * @throws NotFoundHttpException for cards the tab doesn't have
	 */
	protected function renderCard(array $cards): ?Response
	{
		$key = Craft::$app->getRequest()->getQueryParam(self::CARD_PARAM);

		if (!is_string($key)) {
			return null;
		}

		foreach ($cards as $card) {
			if ($card['key'] === $key) {
				$data = ($card['load'])();

				if (isset($card['history'], $data['total'])) {
					$range = (int)Craft::$app->getRequest()->getQueryParam(self::RANGE_PARAM, SnapshotService::RANGES[0]);
					$data['chart'] = Hub::getInstance()->snapshots->chart($card['history'], [...$card, ...$data], $range);
				}

				return $this->renderTemplate('hub/_components/statistics-card', ['card' => [...$card, ...$data]]);
			}
		}

		throw new NotFoundHttpException('Card not found.');
	}

	/**
	 * Statistics tab of an index page: the summary cards of its items, or the one requested (see renderCard()).
	 *
	 * @param array<int,array{key:string,label:string,icon:string,load:Closure,history?:Closure}> $cards
	 * @param bool $siteScoped whether the elements are scoped to the selected sites, which users aren't
	 * @param array<string,string> $extraTabs labels of the tabs between the list and the statistics, by their path segment
	 */
	protected function renderStatistics(CheckKind $kind, array $cards, bool $siteScoped = true, array $extraTabs = []): Response
	{
		if (($card = $this->renderCard($cards)) !== null) {
			return $card;
		}

		[, $area, $page] = explode('/', $kind->path());

		return $this->renderTemplate('hub/pages/statistics', [
			'area' => $area,
			'page' => $page,
			'title' => $kind->label(),
			'cards' => $cards,
			'siteScoped' => $siteScoped,
			'extraTabs' => $extraTabs,
		]);
	}

	/**
	 * Diagnostics tab of an index page: each enabled check of the kind with the number of items it flags, linking to the
	 * list showing them, as its filter does; failed checks first, the most urgent first, see DiagnosticsService::sort().
	 *
	 * @param bool $siteScoped whether the elements are scoped to the selected sites, which users aren't
	 * @param array<string,string> $extraTabs labels of the tabs between the list and the statistics, by their path segment
	 */
	protected function renderDiagnostics(CheckKind $kind, bool $siteScoped = true, array $extraTabs = []): Response
	{
		$diagnostics = Hub::getInstance()->diagnostics;
		[, $area, $page] = explode('/', $kind->path());
		$tasks = [];

		foreach ($diagnostics->getEnabledChecks($kind) as $definition) {
			$tasks[] = [
				'key' => $definition->getKey(),
				'label' => $definition->getListName(),
				'description' => $definition->getListDescription(),
				'icon' => $definition->getIcon(),
				'severity' => $definition->getSeverity(),
				'value' => $diagnostics->countFailing($definition),
				'url' => $this->hubUrl($kind->path(), $definition->getFilter()?->getListParams() ?? []),
			];
		}

		return $this->renderTemplate('hub/pages/diagnostics', [
			'area' => $area,
			'page' => $page,
			'title' => $kind->label(),
			'tasks' => $diagnostics->sort($tasks, $kind, fn (array $task) => [$task['key'], $task['value'] === 0, $task['severity']]),
			'siteScoped' => $siteScoped,
			'extraTabs' => $extraTabs,
		]);
	}
}
