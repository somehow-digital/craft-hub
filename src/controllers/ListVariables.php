<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Volume;
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
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\services\UserService;

/**
 * What the pages of Hub's lists are rendered with: the items filtered, sorted and paged by the request, their labels and
 * saved filters.
 */
trait ListVariables
{
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
		return Hub::getInstance()->listFilters->createListFilter($kind, Craft::$app->getRequest()->getQueryParamsWithoutPath());
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
		$entries = $hub->entries;
		$months = $hub->filters->getThreshold(CheckKind::Entries, 'stale');
		$entryTypes = $hub->structure->getEntryTypes();
		$nestedFields = array_filter($hub->structure->getFields(), fn (FieldInfo $info) => $info->isNested);

		// While reviewing the selection, only the list's own limits apply.
		$selection = ListSelection::fromRequest(ColumnService::LIST_ENTRIES, $path);
		/** @var EntryFilter $filter */
		$filter = $selection->review ? new EntryFilter() : $this->listFilterFromRequest(CheckKind::Entries);
		// Sections and fields widen each other, so on the page of one the other isn't taken from the request.
		$filter->sections = $section !== null ? [$section->handle] : ($field !== null ? [] : $filter->sections);
		$filter->types = $entryType !== null ? [$entryType->handle] : $filter->types;
		$filter->fields = $field !== null ? [$field->handle] : ($section !== null ? [] : $filter->fields);
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

		// All entries of a section or field have the same origin, and section entries no owner. On a user's page, they are the author.
		$columns = $hub->columns->getColumns(ColumnService::LIST_ENTRIES, [
			...match (true) {
				$section !== null => ['origin', 'owner'],
				$field !== null => ['origin'],
				$fieldIds === [] => ['owner'],
				default => [],
			},
			...($author !== null ? ['creator'] : []),
		]);
		$shown = $hub->columns->getShownKeys($columns);

		$sort = ListSort::fromRequest($entries->getSortColumns(), EntryService::SORT_UPDATED);

		$query = $entries->getQuery($filter, $sort->column, $sort->direction);
		$selection->limitQuery($query);

		// The owners of nested entries and the authors are shown with them, and the maintainers of nested entries are those of
		// their owners too.
		$with = array_keys(array_intersect(['owner' => 'owner', 'authors' => 'creator'], $shown));
		if (in_array('maintainers', $shown, true) && !in_array('owner', $with, true)) {
			$with[] = 'owner';
		}
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
			'canDownload' => $hub->access->canAct(Page::Assets, Hub::PERMISSION_DOWNLOAD_ASSETS),
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
}
