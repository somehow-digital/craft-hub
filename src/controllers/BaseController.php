<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;
use craft\web\Controller;
use craft\web\twig\variables\Paginate;
use DateTime;
use DateTimeZone;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\SectionType;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\models\UserStatistics;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\QueryService;
use SomehowDigital\Craft\Hub\services\ReviewService;
use SomehowDigital\Craft\Hub\services\UserService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

abstract class BaseController extends Controller
{
	public const LIST_UNREFERENCED = 'unreferenced';
	public const LIST_STALE = 'stale';
	public const LIST_UNUSED = 'unused';
	public const LIST_MISSING_ALT = 'alt';
	public const LIST_LARGE = 'large';

	public const PAGE_SIZE = 50;

	/** tabs of the detail pages, each on its own URL, the overview on that of the page itself */
	public const TAB_OVERVIEW = 'overview';
	public const TAB_DIAGNOSTICS = 'diagnostics';
	public const TAB_ENTRIES = 'entries';
	public const TAB_ASSETS = 'assets';
	public const TAB_TRANSFORMS = 'transforms';
	public const TAB_HISTORY = 'history';

	/** header of the requests loading a list in place, as its filters change or the site scope does */
	public const FILTERS_HEADER = 'X-Hub-Filters';

	protected array|int|bool $allowAnonymous = false;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) {
			return false;
		}

		$this->requireCpRequest();
		$this->requirePermission(Hub::PERMISSION_ACCESS);

		return true;
	}

	/**
	 * Adds the site scope to every Hub template.
	 */
	public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
	{
		$hub = Hub::getInstance();

		return parent::renderTemplate($template, [
			'selectedSites' => $hub->getSites(),
			'hubSite' => $hub->getSiteParam(),
			'siteIds' => $hub->getSiteIds(),
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
		return UrlHelper::cpUrl($path, [Hub::SITE_PARAM => Hub::getInstance()->getSiteParam(), ...$params]);
	}

	/**
	 * Tab of a detail page selected by its URL, between the overview and the diagnostics.
	 *
	 * @param string[] $tabs tabs the page has besides those, like one listing its entries
	 * @throws NotFoundHttpException for tabs the page doesn't have
	 */
	protected function detailTab(string $tab, array $tabs = []): string
	{
		if (!in_array($tab, [self::TAB_OVERVIEW, ...$tabs, self::TAB_DIAGNOSTICS], true)) {
			throw new NotFoundHttpException('Tab not found.');
		}

		return $tab;
	}

	/**
	 * Keeps the filters and sorting of a list page in the session, so coming back to it shows them again.
	 * Requests with query params keep theirs, as do the requests loading the list in place, which leave out empty filters;
	 * other requests without any, like those of the nav, are redirected to the kept ones.
	 *
	 * @return Response|null the redirect to the kept filters, if any
	 */
	protected function keepFilters(string $path): ?Response
	{
		$request = Craft::$app->getRequest();
		$session = Craft::$app->getSession();
		$key = "hub:filters:$path";
		$params = $this->filterParams($request->getQueryParamsWithoutPath());

		if ($params !== [] || $request->getHeaders()->has(self::FILTERS_HEADER)) {
			$params !== [] ? $session->set($key, $params) : $session->remove($key);

			return null;
		}

		// Kept params are filtered again, as redirecting without any would redirect again.
		$kept = $this->filterParams($session->get($key));

		return $kept !== [] && $request->getPageNum() === 1 ? $this->redirect($this->hubUrl($path, $kept)) : null;
	}

	/**
	 * Query params of a list that are its filters and sorting, leaving out the site scope and the page.
	 * Craft adds the site of the CP to its URLs on multi-site installs, so that isn't a filter either.
	 *
	 * @return array<string,mixed>
	 */
	private function filterParams(mixed $params): array
	{
		if (!is_array($params)) {
			return [];
		}

		unset($params[Hub::SITE_PARAM], $params['site'], $params['page']);

		return $params;
	}

	/**
	 * Comma-separated values of a filter, like `?volume=images,documents`, optionally limited to the allowed ones.
	 *
	 * @param string[]|null $allowed
	 * @return string[]
	 */
	protected function listParam(string $name, ?array $allowed = null): array
	{
		$value = Craft::$app->getRequest()->getQueryParam($name, '');
		$values = array_values(array_unique(array_filter(array_map('trim', explode(',', is_string($value) ? $value : '')), fn (string $item) => $item !== '')));

		return $allowed !== null ? array_values(array_intersect($values, $allowed)) : $values;
	}

	/**
	 * Comma-separated IDs of a filter, like `?author=1,4`.
	 *
	 * @return int[]
	 */
	protected function idsParam(string $name): array
	{
		return array_values(array_filter(array_map('intval', $this->listParam($name)), fn (int $id) => $id > 0));
	}

	/**
	 * A non-negative number of a range filter, or null if it is missing or invalid.
	 */
	protected function numberParam(string $name): ?float
	{
		$value = Craft::$app->getRequest()->getQueryParam($name);

		return is_string($value) && is_numeric($value) && (float)$value >= 0 ? (float)$value : null;
	}

	/**
	 * A whole non-negative number of a range filter, or null if it is missing or invalid.
	 */
	protected function intParam(string $name): ?int
	{
		$value = $this->numberParam($name);

		return $value !== null ? (int)floor($value) : null;
	}

	/**
	 * A day of a date range filter, as sent by date inputs (`Y-m-d`), in the user's time zone.
	 */
	protected function dateParam(string $name): ?DateTime
	{
		$value = Craft::$app->getRequest()->getQueryParam($name);

		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return null;
		}

		return DateTime::createFromFormat('!Y-m-d', $value, new DateTimeZone(Craft::$app->getTimeZone())) ?: null;
	}

	/**
	 * Column and direction a list is sorted by: columns sort in their natural direction unless `dir` says otherwise.
	 *
	 * @param array<string,int> $sortColumns column => natural direction
	 * @return array{0:string,1:int} column and `SORT_ASC` or `SORT_DESC`
	 */
	protected function sortParams(array $sortColumns, string $defaultSort): array
	{
		$request = Craft::$app->getRequest();
		$sort = (string)$request->getQueryParam('sort', '');
		$sort = isset($sortColumns[$sort]) ? $sort : $defaultSort;

		$direction = match ((string)$request->getQueryParam('dir', '')) {
			'asc' => SORT_ASC,
			'desc' => SORT_DESC,
			default => $sortColumns[$sort],
		};

		return [$sort, $direction];
	}

	/**
	 * Template variables of a sorted list: the column headers, and the sorting kept as hidden fields,
	 * so changing a filter keeps it; the default sort is left out.
	 *
	 * @param array<string,int> $sortColumns column => natural direction
	 * @return array<string,mixed>
	 */
	protected function sortVariables(string $path, array $sortColumns, string $defaultSort, string $sort, int $direction): array
	{
		$natural = $direction === $sortColumns[$sort];

		return [
			'sortHeaders' => $this->sortHeaders($path, $sortColumns, $defaultSort, $sort, $direction),
			'sort' => $sort !== $defaultSort || !$natural ? $sort : null,
			'dir' => !$natural ? ($direction === SORT_ASC ? 'asc' : 'desc') : null,
		];
	}

	/**
	 * Link and current direction of each sortable column header, for lists sorted on the server.
	 * Clicking a column sorts in its natural direction, then reverses it, then resets the sorting; the default column just reverses.
	 * The filters are kept, the page is reset, and the default sort is left out of the URL, unless nothing else is left in it,
	 * as a URL without any params would be redirected to the kept filters and sorting.
	 *
	 * @param array<string,int> $sortColumns column => natural direction
	 * @return array<string,array{url:string,direction:string|null}>
	 */
	protected function sortHeaders(string $path, array $sortColumns, string $defaultSort, string $sort, int $direction): array
	{
		$params = Craft::$app->getRequest()->getQueryParamsWithoutPath();
		unset($params['sort'], $params['dir'], $params['page']);

		$headers = [];
		foreach ($sortColumns as $column => $natural) {
			$reset = $column === $sort && $direction !== $natural;
			$next = $column === $sort && !$reset ? ($direction === SORT_ASC ? SORT_DESC : SORT_ASC) : $natural;
			$query = $params;

			if ($reset || ($column === $defaultSort && $next === $natural)) {
				$query = $this->filterParams($query) !== [] ? $query : [...$query, 'sort' => $defaultSort];
			} else {
				$query['sort'] = $column;

				if ($next !== $natural) {
					$query['dir'] = $next === SORT_ASC ? 'asc' : 'desc';
				}
			}

			$headers[$column] = [
				'url' => UrlHelper::cpUrl($path, $query),
				'direction' => $column === $sort ? ($direction === SORT_ASC ? 'asc' : 'desc') : null,
			];
		}

		return $headers;
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
		$months = $hub->settings->staleThresholdMonths;
		$entryTypes = $hub->structure->getEntryTypes();
		$nestedFields = array_filter($hub->structure->getFields(), fn (FieldInfo $info) => $info->isNested);

		$filter = $this->entryFilterFromRequest($entries->getStatuses(), $months, array_map(fn (FieldInfo $info) => $info->field->handle, $nestedFields));
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

		$sortColumns = $entries->getSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, EntryService::SORT_UPDATED);

		$query = $entries->getQuery($filter, $sort, $direction)->limit(self::PAGE_SIZE);

		// The owners of nested entries and the authors are shown with them.
		$with = array_keys(array_intersect(['owner' => 'owner', 'authors' => 'creator'], $shown));
		if ($with !== []) {
			$query->with($with);
		}

		[$pageInfo, $results] = $this->paginateList($query);

		$referenceCounts = in_array('references', $shown, true)
			? $hub->references->getReferenceCounts(array_map(fn (Entry $entry) => $entry->id, $results))
			: [];

		return [
			'entries' => $results,
			'pageInfo' => $pageInfo,
			'referenceCounts' => $referenceCounts,
			'sections' => array_filter($hub->structure->getSections(), fn (SectionInfo $info) => isset($sectionIds[$info->section->id])),
			'nestedFields' => array_filter($nestedFields, fn (FieldInfo $info) => isset($fieldIds[$info->field->id])),
			'entryTypes' => array_filter($entryTypes, fn (EntryTypeInfo $info) => isset($typeIds[$info->entryType->id])),
			'statuses' => array_intersect_key(Entry::statuses(), array_flip($entries->getStatuses())),
			'staleMonths' => $months,
			'authors' => $author !== null ? [] : $entries->getAuthors($section?->id, $entryType?->id, $field?->id),
			'filter' => $filter,
			'listPath' => $path,
			'listFixed' => $listFixed,
			'columns' => $columns,
			'bulkActions' => $hub->bulk->getActions(ColumnService::LIST_ENTRIES),
			...$this->sortVariables($path, $sortColumns, EntryService::SORT_UPDATED, $sort, $direction),
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

		$filter = $this->fileFilterFromRequest(array_map('strval', array_keys($fileKinds)));
		$filter->volumes = $volume !== null ? [$volume->handle] : $filter->volumes;
		$filter->uploaders = $uploader !== null ? [$uploader->id] : $filter->uploaders;

		// On a user's page, they are the uploader.
		$columns = $hub->columns->getColumns(ColumnService::LIST_FILES, [
			...($volume !== null ? ['volume'] : []),
			...($uploader !== null ? ['uploader'] : []),
		]);

		$sortColumns = $files->getSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, FileService::SORT_UPLOADED);

		$query = $files->getQuery($filter, $sort, $direction)->limit(self::PAGE_SIZE);

		// The uploaders are shown with their files.
		if (in_array('uploader', $hub->columns->getShownKeys($columns), true)) {
			$query->with(['uploader']);
		}

		[$pageInfo, $assets] = $this->paginateList($query);

		// Files without references are flagged next to their name, which is always shown.
		$referenceCounts = $hub->references->getReferenceCounts(array_map(fn (Asset $asset) => $asset->id, $assets));

		return [
			'assets' => $assets,
			'pageInfo' => $pageInfo,
			'referenceCounts' => $referenceCounts,
			'fileKinds' => $fileKinds,
			'volumes' => $hub->volumes->getVolumes(),
			'extensions' => $files->getExtensions($volume?->id),
			'uploaders' => $uploader !== null ? [] : $files->getUploaders($volume?->id),
			'filter' => $filter,
			'listPath' => $path,
			'listFixed' => match (true) {
				$volume !== null => 'volume',
				$uploader !== null => 'uploader',
				default => null,
			},
			'columns' => $columns,
			'bulkActions' => $hub->bulk->getActions(ColumnService::LIST_FILES),
			...$this->sortVariables($path, $sortColumns, FileService::SORT_UPLOADED, $sort, $direction),
		];
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

		$filter = $this->userFilterFromRequest(array_map(fn (array $info) => (string)$info['group']->handle, $groups));

		// Craft Solo has no groups, so neither the column nor the filter is offered.
		$columns = $hub->columns->getColumns(ColumnService::LIST_USERS, $groups === [] ? ['groups'] : []);
		$shown = $hub->columns->getShownKeys($columns);

		$sortColumns = $users->getSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, UserService::SORT_CREATED);

		// Their photos are shown next to their names.
		$query = $users->getQuery($filter, $sort, $direction)->with(['photo'])->limit(self::PAGE_SIZE);

		if (in_array('groups', $shown, true)) {
			$query->withGroups();
		}

		[$pageInfo, $results] = $this->paginateList($query);
		$ids = array_map(fn (User $user) => $user->id, $results);
		$twoFactor = in_array('twoFactor', $shown, true) ? $users->getTwoFactor($ids) : [];

		return [
			'users' => $results,
			'pageInfo' => $pageInfo,
			'entryCounts' => in_array('entries', $shown, true) ? $users->getEntryCounts($ids) : [],
			'twoFactor' => $twoFactor,
			'groups' => $groups,
			'statuses' => User::statuses(),
			'idleMonths' => $hub->settings->idleUserThresholdMonths,
			'filter' => $filter,
			'listPath' => $path,
			'columns' => $columns,
			'bulkActions' => $hub->bulk->getActions(ColumnService::LIST_USERS),
			...$this->sortVariables($path, $sortColumns, UserService::SORT_CREATED, $sort, $direction),
		];
	}

	/**
	 * Paginates a list by the page of the URL.
	 *
	 * Only the elements of the page are loaded, as {@see QueryService} finds them, and counted.
	 *
	 * @return array{0:Paginate,1:array<int,mixed>}
	 */
	protected function paginateList(ElementQuery $query): array
	{
		$pageSize = $query->limit ?: self::PAGE_SIZE;

		/** @var QueryService $queries */
		$queries = Hub::getInstance()->queries;
		$total = $queries->total($query);
		[$page, $offset, $totalPages] = $this->pageBounds($total, Craft::$app->getRequest()->getPageNum(), $pageSize);
		$results = $queries->page($query, $offset, $pageSize);

		return [$this->pageInfo($offset, count($results), $totalPages, $page, $total), $results];
	}

	/**
	 * Paginates a query on a single site by the page of the URL, loading the elements of the page with the query itself,
	 * for elements {@see QueryService} can't load again by their ID, like revisions.
	 *
	 * @return array{0:Paginate,1:array<int,mixed>}
	 */
	protected function paginateQuery(ElementQuery $query): array
	{
		/** @var QueryService $queries */
		$queries = Hub::getInstance()->queries;
		$total = $queries->total($query);
		[$page, $offset, $totalPages] = $this->pageBounds($total, Craft::$app->getRequest()->getPageNum(), self::PAGE_SIZE);
		$results = (clone $query)->offset($offset)->limit(self::PAGE_SIZE)->all();

		return [$this->pageInfo($offset, count($results), $totalPages, $page, $total), $results];
	}

	/**
	 * Paginates a list filtered and sorted in memory, like those of the structure pages, keeping its keys.
	 *
	 * @template T
	 * @param array<int,T> $items
	 * @return array{0:Paginate,1:array<int,T>}
	 */
	protected function paginateItems(array $items): array
	{
		$total = count($items);
		[$page, $offset, $totalPages] = $this->pageBounds($total, Craft::$app->getRequest()->getPageNum(), self::PAGE_SIZE);
		$results = array_slice($items, $offset, self::PAGE_SIZE, true);

		return [$this->pageInfo($offset, count($results), $totalPages, $page, $total), $results];
	}

	/**
	 * The page within the pages of a list, like Craft's paginator keeps it, with the offset of its first item and the number of pages.
	 *
	 * @return array{0:int,1:int,2:int} page, offset and total pages
	 */
	private function pageBounds(int $total, int $page, int $pageSize): array
	{
		$totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 1;
		$page = max(1, min($page, $totalPages));

		return [$page, ($page - 1) * $pageSize, $totalPages];
	}

	private function pageInfo(int $offset, int $count, int $totalPages, int $page, int $total): Paginate
	{
		return new Paginate([
			'first' => $offset + 1,
			'last' => $offset + $count,
			'total' => $total,
			'currentPage' => $page,
			'totalPages' => $totalPages,
		]);
	}

	/**
	 * Filters of the entries list arrive as query params, like those of the other lists.
	 *
	 * @param string[] $statuses statuses that can be filtered by
	 * @param int $months months without updates after which entries are stale
	 * @param string[] $fields handles of the nested entry fields that can be filtered by
	 */
	private function entryFilterFromRequest(array $statuses, int $months, array $fields): EntryFilter
	{
		$request = Craft::$app->getRequest();
		$entriesService = Craft::$app->getEntries();
		$usage = $request->getQueryParam('usage');
		$origin = $request->getQueryParam('origin');

		$filter = new EntryFilter();
		$filter->search = trim((string)$request->getQueryParam('q', ''));
		$filter->sections = $this->listParam('section', array_map(fn (Section $section) => $section->handle, $entriesService->getAllSections()));
		$filter->types = $this->listParam('type', array_map(fn (EntryType $type) => $type->handle, $entriesService->getAllEntryTypes()));
		$filter->origin = in_array($origin, [EntryService::ORIGIN_SECTION, EntryService::ORIGIN_NESTED], true) ? $origin : null;
		$filter->fields = $this->listParam('field', $fields);
		$filter->statuses = $this->listParam('status', $statuses);
		$filter->authors = $this->idsParam('author');
		$filter->usage = in_array($usage, [EntryService::USAGE_REFERENCED, EntryService::USAGE_UNREFERENCED], true) ? $usage : null;
		$filter->referencesMin = $this->intParam('referencesMin');
		$filter->referencesMax = $this->intParam('referencesMax');
		$filter->postedFrom = $this->dateParam('postedFrom');
		$filter->postedTo = $this->dateParam('postedTo');
		$filter->updatedFrom = $this->dateParam('updatedFrom');
		$filter->updatedTo = $this->dateParam('updatedTo');
		$filter->staleMonths = $request->getQueryParam('stale') === '1' ? $months : null;
		$filter->drafts = $request->getQueryParam('drafts') === '1';

		return $filter;
	}

	/**
	 * Filters of the files list arrive as query params, like those of the other lists.
	 *
	 * @param string[] $kinds file kinds that can be filtered by
	 */
	private function fileFilterFromRequest(array $kinds): FileFilter
	{
		$request = Craft::$app->getRequest();
		$usage = $request->getQueryParam('usage');

		$filter = new FileFilter();
		$filter->search = trim((string)$request->getQueryParam('q', ''));
		$filter->volumes = $this->listParam('volume', array_map(fn (Volume $volume) => $volume->handle, Craft::$app->getVolumes()->getAllVolumes()));
		$filter->kinds = $this->listParam('kind', $kinds);
		$filter->extensions = array_values(array_filter(array_map('mb_strtolower', $this->listParam('extension')), fn (string $extension) => preg_match('/^[a-z0-9]+$/', $extension) === 1));
		$filter->orientations = $this->listParam('orientation', FileFilter::getOrientations());
		$filter->uploaders = $this->idsParam('uploader');
		$filter->usage = in_array($usage, [FileService::USAGE_USED, FileService::USAGE_UNUSED], true) ? $usage : null;
		$filter->referencesMin = $this->intParam('referencesMin');
		$filter->referencesMax = $this->intParam('referencesMax');
		$filter->sizeMin = $this->numberParam('sizeMin');
		$filter->sizeMax = $this->numberParam('sizeMax');
		$filter->widthMin = $this->intParam('widthMin');
		$filter->widthMax = $this->intParam('widthMax');
		$filter->heightMin = $this->intParam('heightMin');
		$filter->heightMax = $this->intParam('heightMax');
		$filter->uploadedFrom = $this->dateParam('uploadedFrom');
		$filter->uploadedTo = $this->dateParam('uploadedTo');
		$filter->missingAlt = $request->getQueryParam('alt') === '1';
		$filter->oversized = $request->getQueryParam('oversized') === '1';

		return $filter;
	}

	/**
	 * Filters of the users list arrive as query params, like those of the other lists.
	 *
	 * @param string[] $groups handles of the groups that can be filtered by
	 */
	private function userFilterFromRequest(array $groups): UserFilter
	{
		$request = Craft::$app->getRequest();
		$twoFactor = $request->getQueryParam('twoFactor');

		$filter = new UserFilter();
		$filter->search = trim((string)$request->getQueryParam('q', ''));
		$filter->groups = $this->listParam('group', $groups !== [] ? [...$groups, UserFilter::GROUP_NONE] : []);
		$filter->statuses = $this->listParam('status', array_keys(User::statuses()));
		$filter->admin = $request->getQueryParam('admin') === '1';
		$filter->logins = $this->listParam('login', UserFilter::getLogins());
		$filter->loginFrom = $this->dateParam('loginFrom');
		$filter->loginTo = $this->dateParam('loginTo');
		$filter->createdFrom = $this->dateParam('createdFrom');
		$filter->createdTo = $this->dateParam('createdTo');
		$filter->twoFactor = in_array($twoFactor, [UserFilter::TWO_FACTOR_WITH, UserFilter::TWO_FACTOR_WITHOUT], true) ? $twoFactor : null;
		$filter->unverifiedEmail = $request->getQueryParam('unverified') === '1';
		$filter->entriesMin = $this->intParam('entriesMin');
		$filter->entriesMax = $this->intParam('entriesMax');

		return $filter;
	}

	/**
	 * Summary cards for the entries list, linking to its filters.
	 *
	 * @param SectionInfo[] $sections
	 * @return array<int,array<string,mixed>>
	 */
	protected function entriesSummary(array $sections): array
	{
		$hub = Hub::getInstance();
		$path = 'hub/data/entries';
		$months = $hub->settings->staleThresholdMonths;

		// Like the list, the entries of both sections and nested entry fields are counted.
		$stats = $hub->statistics->sumEntries($hub->getSiteIds());
		$counts = [];
		$handles = [];
		foreach ($sections as $info) {
			$name = Craft::t('site', $info->section->name);
			$counts[$name] = $info->stats->total;
			$handles[$name] = $info->section->handle;
		}

		$typeTotals = [];
		foreach ($hub->statistics->getEntryRows($hub->getSiteIds()) as $row) {
			$typeTotals[$row['typeId']] = ($typeTotals[$row['typeId']] ?? 0) + $row['stats']->total;
		}

		$typeCounts = [];
		$typeHandles = [];
		foreach ($hub->structure->getEntryTypes() as $info) {
			if (($typeTotals[$info->entryType->id] ?? 0) > 0) {
				$name = Craft::t('site', $info->entryType->name);
				$typeCounts[$name] = $typeTotals[$info->entryType->id];
				$typeHandles[$name] = $info->entryType->handle;
			}
		}

		$entriesCard = $this->entriesCard($stats);
		$entriesCard['segments'][2]['url'] = $this->hubUrl($path, ['status' => Entry::STATUS_DISABLED]);

		$stale = $hub->review->countStale($months);

		return [
			$entriesCard,
			$this->topCard(Craft::t('hub', 'Sections'), 'layer-group', $counts, 'section', path: $path, filterValues: $handles),
			$this->topCard(Craft::t('hub', 'Entry Types'), 'shapes', $typeCounts, 'type', path: $path, filterValues: $typeHandles),
			[
				'label' => Craft::t('hub', 'Activity'),
				'icon' => 'clock',
				'url' => null,
				'total' => $stats->total,
				'segments' => [
					['label' => Craft::t('hub', 'Recently updated'), 'value' => max(0, $stats->total - $stale), 'color' => 'green'],
					['label' => Craft::t('hub', 'Stale'), 'value' => $stale, 'color' => 'orange', 'url' => $this->hubUrl($path, ['stale' => 1])],
				],
				'note' => Craft::t('hub', 'Stale: not updated for {months, plural, =1{one month} other{# months}}', ['months' => $months]),
			],
		];
	}

	/**
	 * Summary cards for the sections list.
	 *
	 * @param SectionInfo[] $sections
	 * @return array<int,array<string,mixed>>
	 */
	protected function sectionsSummary(array $sections): array
	{
		$path = 'hub/structure/sections';
		$stats = array_map(fn (SectionInfo $info) => $info->stats, $sections);
		$types = array_count_values(array_map(fn (SectionInfo $info) => $info->section->type, $sections));
		$referenced = count(array_filter($sections, fn (SectionInfo $info) => $info->references->references > 0));
		$withUrls = count(array_filter($sections, fn (SectionInfo $info) => $info->hasUrls));

		$entries = new EntryStatistics();
		$references = 0;
		foreach ($sections as $info) {
			$entries->add($info->stats);
			$references += $info->references->references;
		}

		$sectionsCard = $this->sectionsCard($sections);
		$sectionsCard['note'] = Craft::t('hub', '{count, plural, =0{None} =1{One} other{#}} with URLs', ['count' => $withUrls]);

		return [
			$sectionsCard,
			[
				'label' => Craft::t('hub', 'Types'),
				'icon' => 'sitemap',
				'url' => null,
				'total' => count($sections),
				'segments' => array_map(fn (SectionType $type) => [
					'label' => $type->label(),
					'value' => $types[$type->value] ?? 0,
					'color' => $type->color(),
					'icon' => $type->icon(),
					'url' => $this->hubUrl($path, ['type' => $type->value]),
				], SectionType::cases()),
			],
			$this->entriesCard($entries, Craft::t('hub', '{references, number} references into {referenced, number} sections', [
				'references' => $references,
				'referenced' => $referenced,
			])),
			$this->activityCard($stats, $path),
		];
	}

	/**
	 * Summary cards for the entry types list.
	 *
	 * @param EntryTypeInfo[] $entryTypes
	 * @return array<int,array<string,mixed>>
	 */
	protected function entryTypesSummary(array $entryTypes): array
	{
		$path = 'hub/structure/entry-types';
		$stats = array_map(fn (EntryTypeInfo $info) => $info->stats, $entryTypes);

		$entries = new EntryStatistics();
		foreach ($stats as $item) {
			$entries->add($item);
		}

		$kinds = array_count_values(array_merge(...array_map(fn (EntryTypeInfo $info) => $info->getKindValues(), $entryTypes)));

		return [
			$this->entryTypesCard($entryTypes),
			[
				'label' => Craft::t('hub', 'Usage'),
				'icon' => 'diagram-nested',
				'url' => null,
				'total' => count($entryTypes),
				'segments' => array_map(fn (UsageKind $kind) => [
					'label' => $kind->label(),
					'value' => $kinds[$kind->value] ?? 0,
					'color' => $kind->color(),
					'icon' => $kind->icon(),
					'url' => $this->hubUrl($path, ['kind' => $kind->value]),
				], UsageKind::listed()),
			],
			$this->entriesCard($entries),
			$this->activityCard($stats, $path),
		];
	}

	/**
	 * Summary cards for the fields list.
	 *
	 * @param FieldInfo[] $fields
	 * @return array<int,array<string,mixed>>
	 */
	protected function fieldsSummary(array $fields): array
	{
		$path = 'hub/structure/fields';
		$kinds = array_count_values(array_map(fn (FieldInfo $info) => $info->getDataKind()->value, $fields));
		$typeCounts = array_count_values(array_map(fn (FieldInfo $info) => $info->getTypeName(), $fields));
		$ownerCounts = [];
		$nestedEntries = 0;
		$references = 0;

		foreach ($fields as $info) {
			foreach ($info->layouts as $usage) {
				$ownerCounts[$usage->ownerType] = ($ownerCounts[$usage->ownerType] ?? 0) + 1;
			}

			$nestedEntries += $info->isNested ? $info->stats->total : 0;
			$references += $info->isRelational ? $info->references->references : 0;
		}

		return [
			$this->fieldsCard($fields),
			[
				'label' => Craft::t('hub', 'Data'),
				'icon' => 'database',
				'url' => null,
				'total' => count($fields),
				'segments' => array_map(fn (DataKind $kind) => [
					'label' => $kind->label(),
					'value' => $kinds[$kind->value] ?? 0,
					'color' => $kind->color(),
					'icon' => $kind->icon(),
					'url' => $this->hubUrl($path, ['kind' => $kind->value]),
				], DataKind::cases()),
				'note' => Craft::t('hub', '{entries, number} nested entries · {references, number} references', [
					'entries' => $nestedEntries,
					'references' => $references,
				]),
			],
			$this->topCard(Craft::t('hub', 'Field Types'), 'puzzle-piece', $typeCounts, 'type', path: $path),
			$this->topCard(Craft::t('hub', 'Relations'), 'link', $ownerCounts, 'owner', path: $path, sum: true),
		];
	}

	/**
	 * Summary cards for the volumes list.
	 *
	 * @param VolumeInfo[] $volumes
	 * @param FileKindInfo[] $fileKinds
	 * @return array<int,array<string,mixed>>
	 */
	protected function volumesSummary(array $volumes, array $fileKinds): array
	{
		$path = 'hub/structure/volumes';
		$assets = new AssetStatistics();
		$references = 0;
		foreach ($volumes as $info) {
			$assets->add($info->stats);
			$references += $info->references->references;
		}

		$kinds = [];
		$kindValues = [];
		foreach ($fileKinds as $info) {
			$kinds[$info->getLabel()] = $info->stats->total;
			$kindValues[$info->getLabel()] = $info->kind;
		}

		return [
			$this->volumesCard($volumes),
			$this->assetsCard($assets, Craft::t('hub', '{references, number} references from Assets and Link fields', [
				'references' => $references,
			])),
			$this->topCard(Craft::t('hub', 'Types'), 'file', $kinds, 'kind', path: $path, filterValues: $kindValues),
			$this->altCard($assets),
		];
	}

	/**
	 * Summary cards for the files list, linking to its filters.
	 *
	 * @param VolumeInfo[] $volumes
	 * @param FileKindInfo[] $fileKinds
	 * @return array<int,array<string,mixed>>
	 */
	protected function filesSummary(array $volumes, array $fileKinds): array
	{
		$path = 'hub/data/assets';
		$assets = new AssetStatistics();
		$counts = [];
		$handles = [];

		foreach ($volumes as $info) {
			$assets->add($info->stats);
			$name = Craft::t('site', $info->volume->name);
			$counts[$name] = $info->stats->total;
			$handles[$name] = $info->volume->handle;
		}

		$kinds = [];
		$kindValues = [];
		foreach ($fileKinds as $info) {
			$kinds[$info->getLabel()] = $info->stats->total;
			$kindValues[$info->getLabel()] = $info->kind;
		}

		$assetsCard = $this->assetsCard($assets);
		$assetsCard['segments'][0]['url'] = $this->hubUrl($path, ['usage' => FileService::USAGE_USED]);
		$assetsCard['segments'][1]['url'] = $this->hubUrl($path, ['usage' => FileService::USAGE_UNUSED]);

		$altCard = $this->altCard($assets);
		$altCard['segments'][1]['url'] = $this->hubUrl($path, ['alt' => 1]);

		return [
			$assetsCard,
			$this->topCard(Craft::t('hub', 'Volumes'), 'folder-open', $counts, 'volume', path: $path, filterValues: $handles),
			$this->topCard(Craft::t('hub', 'Types'), 'file', $kinds, 'kind', path: $path, filterValues: $kindValues),
			$altCard,
		];
	}

	/**
	 * Summary cards for the users list, linking to its filters.
	 *
	 * @param array<int,array{group:UserGroup,users:int,permissions:int,requires2fa:bool}> $groups
	 * @return array<int,array<string,mixed>>
	 */
	protected function usersSummary(UserStatistics $stats, array $groups): array
	{
		$path = 'hub/data/users';
		$months = Hub::getInstance()->settings->idleUserThresholdMonths;

		$counts = [];
		$handles = [];
		foreach ($groups as $info) {
			$name = Craft::t('site', (string)$info['group']->name);
			$counts[$name] = $info['users'];
			$handles[$name] = (string)$info['group']->handle;
		}

		$cards = [
			[
				'label' => Craft::t('hub', 'Users'),
				'icon' => 'user',
				'url' => null,
				'total' => $stats->total,
				'segments' => [
					['label' => Craft::t('app', 'Active'), 'value' => $stats->active, 'color' => 'green', 'url' => $this->hubUrl($path, ['status' => User::STATUS_ACTIVE])],
					['label' => Craft::t('app', 'Pending'), 'value' => $stats->pending, 'color' => 'orange', 'url' => $this->hubUrl($path, ['status' => User::STATUS_PENDING])],
					['label' => Craft::t('app', 'Suspended'), 'value' => $stats->suspended, 'color' => 'red', 'url' => $this->hubUrl($path, ['status' => User::STATUS_SUSPENDED])],
					['label' => Craft::t('app', 'Inactive'), 'value' => $stats->inactive, 'color' => 'gray', 'url' => $this->hubUrl($path, ['status' => User::STATUS_INACTIVE])],
					// Locked users are active too, so they are listed but kept out of the bar.
					['label' => Craft::t('app', 'Locked'), 'value' => $stats->locked, 'color' => 'red', 'bar' => false, 'url' => $this->hubUrl($path, ['status' => User::STATUS_LOCKED])],
				],
				'note' => Craft::t('hub', '{count, plural, =0{No admins} =1{One admin} other{# admins}}', ['count' => $stats->admins]),
			],
		];

		if ($groups !== []) {
			$cards[] = $this->topCard(Craft::t('app', 'User Groups'), 'users', $counts, 'group', path: $path, filterValues: $handles, sum: true);
		}

		$cards[] = [
			'label' => Craft::t('hub', 'Sign-ins'),
			'icon' => 'clock',
			'url' => null,
			'total' => $stats->active,
			'segments' => [
				['label' => Craft::t('hub', 'Recently'), 'value' => $stats->recentLogin, 'color' => 'green', 'url' => $this->hubUrl($path, ['login' => UserFilter::LOGIN_RECENT])],
				['label' => Craft::t('hub', 'Idle'), 'value' => $stats->idle, 'color' => 'orange', 'url' => $this->hubUrl($path, ['login' => UserFilter::LOGIN_IDLE])],
				['label' => Craft::t('hub', 'Not yet'), 'value' => $stats->getNew(), 'color' => 'gray'],
			],
			'note' => Craft::t('hub', 'Active users only · Idle: not signed in for {months, plural, =1{one month} other{# months}}', ['months' => $months]),
		];

		$cards[] = [
			'label' => Craft::t('hub', 'Two-step verification'),
			'icon' => 'shield-halved',
			'url' => null,
			'total' => $stats->active,
			'segments' => [
				['label' => Craft::t('hub', 'Set up'), 'value' => $stats->twoFactor, 'color' => 'green', 'url' => $this->hubUrl($path, ['status' => User::STATUS_ACTIVE, 'twoFactor' => UserFilter::TWO_FACTOR_WITH])],
				['label' => Craft::t('hub', 'Not set up'), 'value' => $stats->active - $stats->twoFactor, 'color' => 'orange', 'url' => $this->hubUrl($path, ['status' => User::STATUS_ACTIVE, 'twoFactor' => UserFilter::TWO_FACTOR_WITHOUT])],
			],
			'note' => Craft::t('hub', 'Active users only · {count, plural, =0{No admin} =1{One admin} other{# admins}} without', ['count' => $stats->adminsWithoutTwoFactor]),
		];

		return $cards;
	}

	/**
	 * Summary card for volumes, split by whether they hold assets.
	 *
	 * @param VolumeInfo[] $volumes
	 * @return array<string,mixed>
	 */
	protected function volumesCard(array $volumes): array
	{
		$empty = count(array_filter($volumes, fn (VolumeInfo $info) => $info->isEmpty()));
		$withUrls = count(array_filter($volumes, fn (VolumeInfo $info) => $info->hasUrls));

		return [
			'label' => Craft::t('hub', 'Volumes'),
			'icon' => 'folder-open',
			'url' => $this->hubUrl('hub/structure/volumes'),
			'total' => count($volumes),
			'segments' => [
				['label' => Craft::t('hub', 'With assets'), 'value' => count($volumes) - $empty, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/structure/volumes', ['empty' => 1])],
			],
			'note' => Craft::t('hub', '{count, plural, =0{None} =1{One} other{#}} with URLs', ['count' => $withUrls]),
		];
	}

	/**
	 * Summary card for assets, split by whether they are referenced.
	 *
	 * @return array<string,mixed>
	 */
	protected function assetsCard(AssetStatistics $stats, ?string $note = null): array
	{
		$size = Craft::$app->getFormatter()->asShortSize($stats->size, 1);

		return [
			'label' => Craft::t('hub', 'Assets'),
			'icon' => 'image',
			'url' => null,
			'total' => $stats->total,
			'segments' => [
				['label' => Craft::t('hub', 'With references'), 'value' => $stats->used, 'color' => 'green'],
				['label' => Craft::t('hub', 'Without references'), 'value' => $stats->getUnused(), 'color' => 'orange'],
			],
			'note' => $note !== null ? $size . ' · ' . $note : $size,
		];
	}

	/**
	 * Summary card for images, split by whether they have alternative text.
	 *
	 * @return array<string,mixed>
	 */
	protected function altCard(AssetStatistics $stats): array
	{
		return [
			'label' => Craft::t('hub', 'Alternative Text'),
			'icon' => 'universal-access',
			'url' => null,
			'total' => $stats->images,
			'segments' => [
				['label' => Craft::t('hub', 'With alternative text'), 'value' => $stats->images - $stats->missingAlt, 'color' => 'green'],
				['label' => Craft::t('hub', 'Missing'), 'value' => $stats->missingAlt, 'color' => 'orange'],
			],
			'note' => Craft::t('hub', 'Images only'),
		];
	}

	/**
	 * Summary card for sections, split by whether they hold entries.
	 *
	 * @param SectionInfo[] $sections
	 * @return array<string,mixed>
	 */
	protected function sectionsCard(array $sections): array
	{
		$empty = count(array_filter($sections, fn (SectionInfo $info) => $info->isEmpty()));

		return [
			'label' => Craft::t('hub', 'Sections'),
			'icon' => 'layer-group',
			'url' => $this->hubUrl('hub/structure/sections'),
			'total' => count($sections),
			'segments' => [
				['label' => Craft::t('hub', 'With entries'), 'value' => count($sections) - $empty, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/structure/sections', ['empty' => 1])],
			],
		];
	}

	/**
	 * Summary card for entry types, split by whether they are used and hold entries.
	 *
	 * @param EntryTypeInfo[] $entryTypes
	 * @return array<string,mixed>
	 */
	protected function entryTypesCard(array $entryTypes): array
	{
		$unused = count(array_filter($entryTypes, fn (EntryTypeInfo $info) => $info->hasKind(UsageKind::Unused)));
		$empty = count(array_filter($entryTypes, fn (EntryTypeInfo $info) => $info->hasKind(UsageKind::Empty)));

		return [
			'label' => Craft::t('hub', 'Entry Types'),
			'icon' => 'shapes',
			'url' => $this->hubUrl('hub/structure/entry-types'),
			'total' => count($entryTypes),
			'segments' => [
				['label' => Craft::t('hub', 'In use'), 'value' => count($entryTypes) - $empty - $unused, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/structure/entry-types', ['kind' => UsageKind::Empty->value])],
				['label' => Craft::t('hub', 'Unused'), 'value' => $unused, 'color' => 'red', 'url' => $this->hubUrl('hub/structure/entry-types', ['kind' => UsageKind::Unused->value])],
			],
		];
	}

	/**
	 * Summary card for fields, split by whether they are placed and empty.
	 *
	 * @param FieldInfo[] $fields
	 * @return array<string,mixed>
	 */
	protected function fieldsCard(array $fields): array
	{
		$unused = count(array_filter($fields, fn (FieldInfo $info) => $info->isUnused()));
		$empty = count(array_filter($fields, fn (FieldInfo $info) => $info->isEmpty()));

		return [
			'label' => Craft::t('hub', 'Fields'),
			'icon' => 'pen-ruler',
			'url' => $this->hubUrl('hub/structure/fields'),
			'total' => count($fields),
			'segments' => [
				['label' => Craft::t('hub', 'In use'), 'value' => count($fields) - $empty - $unused, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/structure/fields', ['empty' => 1])],
				['label' => Craft::t('hub', 'Unused'), 'value' => $unused, 'color' => 'red', 'url' => $this->hubUrl('hub/structure/fields', ['unused' => 1])],
			],
		];
	}

	/**
	 * Summary card for entries, split by status.
	 *
	 * @return array<string,mixed>
	 */
	protected function entriesCard(EntryStatistics $stats, ?string $note = null): array
	{
		return [
			'label' => Craft::t('hub', 'Entries'),
			'icon' => 'file-lines',
			'url' => null,
			'total' => $stats->total,
			'segments' => [
				['label' => Craft::t('hub', 'Enabled'), 'value' => $stats->enabled, 'color' => 'green'],
				// Drafts are not part of the total, so they are listed but kept out of the bar.
				['label' => Craft::t('hub', 'Drafts'), 'value' => $stats->drafts, 'color' => 'amber', 'bar' => false],
				['label' => Craft::t('hub', 'Disabled'), 'value' => $stats->getDisabled(), 'color' => 'gray'],
			],
			'note' => $note,
		];
	}

	/**
	 * Summary card showing the largest groups of a list, with the rest combined.
	 *
	 * @param array<string,int> $counts group label => count
	 * @param string|null $filter query parameter that filters the list by a group's lowercased label
	 * @param string|null $path list page path for filter links, needed along with the filter
	 * @param array<string,string> $filterValues group label => filter value, for groups not filtered by their lowercased label
	 * @param bool $sum whether the total sums the counts, for cards naming what is counted, rather than counting the groups
	 * @return array<string,mixed>
	 */
	protected function topCard(string $label, string $icon, array $counts, ?string $filter = null, int $limit = 4, ?string $path = null, array $filterValues = [], bool $sum = false): array
	{
		arsort($counts);
		$top = array_slice($counts, 0, $limit, true);
		$colors = ['blue', 'violet', 'teal', 'amber'];
		$segments = [];

		foreach (array_keys($top) as $i => $group) {
			$segments[] = [
				'label' => (string)$group,
				'value' => $top[$group],
				'color' => $colors[$i % count($colors)],
				'url' => $filter !== null && $path !== null ? $this->hubUrl($path, [$filter => $filterValues[$group] ?? mb_strtolower((string)$group)]) : null,
			];
		}

		if (count($counts) > $limit) {
			$segments[] = [
				'label' => Craft::t('hub', '{count, number} more', ['count' => count($counts) - $limit]),
				'value' => array_sum($counts) - array_sum($top),
				'color' => 'gray',
				// Listed after the groups, however large it is.
				'last' => true,
			];
		}

		return [
			'label' => $label,
			'icon' => $icon,
			'url' => null,
			'total' => $sum ? array_sum($counts) : count($counts),
			'segments' => $segments,
		];
	}

	/**
	 * Summary card splitting items by how recently their entries were updated.
	 *
	 * @param array<int,EntryStatistics> $stats keyed like the list it summarizes
	 * @param string $path list page path for filter links
	 * @return array<string,mixed>
	 */
	protected function activityCard(array $stats, string $path): array
	{
		$months = Hub::getInstance()->settings->staleThresholdMonths;
		$counts = [EntryStatistics::ACTIVITY_RECENT => 0, EntryStatistics::ACTIVITY_STALE => 0, EntryStatistics::ACTIVITY_NONE => 0];

		foreach ($stats as $item) {
			$counts[$item->getActivity($months)]++;
		}

		return [
			'label' => Craft::t('hub', 'Activity'),
			'icon' => 'clock',
			'url' => null,
			'total' => count($stats),
			'segments' => [
				['label' => Craft::t('hub', 'Recently updated'), 'value' => $counts[EntryStatistics::ACTIVITY_RECENT], 'color' => 'green', 'url' => $this->hubUrl($path, ['activity' => EntryStatistics::ACTIVITY_RECENT])],
				['label' => Craft::t('hub', 'Stale'), 'value' => $counts[EntryStatistics::ACTIVITY_STALE], 'color' => 'orange', 'url' => $this->hubUrl($path, ['activity' => EntryStatistics::ACTIVITY_STALE])],
				['label' => Craft::t('hub', 'No entries'), 'value' => $counts[EntryStatistics::ACTIVITY_NONE], 'color' => 'gray', 'url' => $this->hubUrl($path, ['activity' => EntryStatistics::ACTIVITY_NONE])],
			],
			'note' => Craft::t('hub', 'Stale: not updated for {months, plural, =1{one month} other{# months}}', ['months' => $months]),
		];
	}

	/**
	 * Variables for the unreferenced/stale entry list shown on section and entry type pages.
	 *
	 * @param bool $unreferenced whether the unreferenced entries can be listed, or only the stale ones
	 * @return array<string,mixed>
	 */
	protected function reviewListVariables(?int $sectionId = null, ?int $typeId = null, ?int $fieldId = null, bool $unreferenced = true): array
	{
		$request = Craft::$app->getRequest();
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;

		$list = $request->getQueryParam('list') === self::LIST_STALE || !$unreferenced ? self::LIST_STALE : self::LIST_UNREFERENCED;
		$months = max(1, (int)$request->getQueryParam('months', Hub::getInstance()->settings->staleThresholdMonths));

		$query = $list === self::LIST_STALE
			? $review->getStaleQuery($months, $sectionId, $typeId, $fieldId)
			: $review->getUnreferencedQuery($sectionId, $typeId);

		return [
			'reviewList' => $list,
			'reviewUnreferenced' => $unreferenced,
			'reviewMonths' => $months,
			...$this->reviewPage($query),
		];
	}

	/**
	 * Variables for the unused/missing alt text/large asset list shown on volume pages.
	 *
	 * @return array<string,mixed>
	 */
	protected function assetReviewListVariables(?int $volumeId = null): array
	{
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;

		$list = Craft::$app->getRequest()->getQueryParam('list');

		[$list, $query] = match ($list) {
			self::LIST_MISSING_ALT => [$list, $review->getMissingAltQuery($volumeId)],
			self::LIST_LARGE => [$list, $review->getLargeFilesQuery($volumeId)],
			default => [self::LIST_UNUSED, $review->getUnusedAssetsQuery($volumeId)],
		};

		return ['reviewList' => $list, ...$this->reviewPage($query)];
	}

	/**
	 * The page of a review list, counted and loaded like the other lists, see {@see paginateList()}.
	 *
	 * @return array{reviewQuery:ElementQuery,reviewPageInfo:Paginate,reviewItems:array<int,mixed>}
	 */
	private function reviewPage(ElementQuery $query): array
	{
		[$pageInfo, $items] = $this->paginateList($query->limit(self::PAGE_SIZE));

		return ['reviewQuery' => $query, 'reviewPageInfo' => $pageInfo, 'reviewItems' => $items];
	}

	/**
	 * Summary cards of an index page on their own, loaded once their panel is opened.
	 *
	 * @param array<int,array<string,mixed>> $cards
	 */
	protected function renderSummary(array $cards): Response
	{
		return $this->renderTemplate('hub/_components/summary', ['cards' => $cards]);
	}

	/**
	 * Diagnostics tab of an index page, with the failed checks first, the most urgent first.
	 *
	 * @param string $area `data` or `structure`
	 * @param string $page path segment of the index page below the area
	 * @param array<int,array<string,mixed>> $tasks checks with the number of items failing them, linking to the matching lists
	 * @param bool $siteScoped whether the elements are scoped to the selected sites, which users aren't
	 * @param array<string,string> $extraTabs labels of the tabs between the list and the diagnostics, by their path segment
	 */
	protected function renderDiagnostics(string $area, string $page, string $title, array $tasks, bool $siteScoped = true, array $extraTabs = []): Response
	{
		usort($tasks, fn (array $a, array $b) => [$a['value'] === 0, $a['severity']->rank()] <=> [$b['value'] === 0, $b['severity']->rank()]);

		return $this->renderTemplate('hub/pages/diagnostics', [
			'area' => $area,
			'page' => $page,
			'title' => $title,
			'tasks' => $tasks,
			'siteScoped' => $siteScoped,
			'extraTabs' => $extraTabs,
		]);
	}

	/**
	 * A task per check of the items, counting the items failing it.
	 *
	 * @param array<int,Check[]> $diagnostics checks of each item
	 * @param string $path list of the items, linked by checks without a filter of their own
	 * @param array<string,array<string,mixed>> $filters query params of the list showing the items failing a check, by its heading
	 * @return array<int,array<string,mixed>>
	 */
	protected function checkTasks(array $diagnostics, string $path, array $filters = []): array
	{
		$tasks = [];

		foreach ($diagnostics as $checks) {
			foreach ($checks as $check) {
				$tasks[$check->heading] ??= [
					'label' => $check->heading,
					'description' => $check->description,
					'icon' => $check->icon,
					'severity' => $check->severity,
					'value' => 0,
					'url' => $this->hubUrl($path, $filters[$check->heading] ?? []),
				];

				if (!$check->passed) {
					$tasks[$check->heading]['value']++;
				}
			}
		}

		return array_values($tasks);
	}
}
