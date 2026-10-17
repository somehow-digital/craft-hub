<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\Element;
use craft\base\ElementExporterInterface;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\helpers\App;
use craft\models\UserGroup;
use craft\web\Response;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\TrashedEntryFilter;
use SomehowDigital\Craft\Hub\models\TrashedFileFilter;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\models\UserGroupFilter;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use Throwable;
use yii\base\Component;

/**
 * Actions applied to the items selected in a list, by the list's name (see ColumnService::LIST_*).
 * Each action needs a permission of the list's page (see Page::actionPermissions()), and items are changed as far as
 * Craft lets the user change each of them: entries and files as far as they may save, move or delete each, users as far
 * as they may suspend, save or delete each. Entries, files and users, in the trash too, are exported by Craft's exporters of their element
 * type, as far as the user may view each. Trashed entries and files are restored or deleted permanently by users who may view the trash and
 * restore or delete them permanently from the Hub, as far as Craft lets them save or delete each. Sections, entry types, fields, volumes and user groups, the structure, are never deleted from
 * the Hub, only labeled. Items are labeled by users who may label those of the page, while labels are enabled and there
 * are labels or they can create some. Several items are selected at once only by users who may bulk-edit the page;
 * others apply the actions to one item at a time.
 *
 * The selected items are kept in the session, per list and the page it is on (its path), so the selection survives
 * filtering, sorting and paging through the list, until an action is applied to it or it is cleared.
 */
class BulkService extends Component
{
	public const ACTION_ENABLE = 'enable';
	public const ACTION_DISABLE = 'disable';
	public const ACTION_DELETE = 'delete';
	public const ACTION_DELETE_TRANSFORMS = 'deleteTransforms';
	public const ACTION_DOWNLOAD = 'download';
	public const ACTION_SUSPEND = 'suspend';
	public const ACTION_UNSUSPEND = 'unsuspend';
	public const ACTION_LABELS = 'labels';
	public const ACTION_RESTORE = 'restore';
	public const ACTION_DELETE_PERMANENTLY = 'deletePermanently';
	public const ACTION_EXPORT = 'export';
	public const ACTION_CHANGE_AUTHOR = 'changeAuthor';
	public const ACTION_MOVE_TO_SECTION = 'moveToSection';
	public const ACTION_DELETE_DRAFTS = 'deleteDrafts';
	public const ACTION_MOVE_TO_FOLDER = 'moveToFolder';
	public const ACTION_GENERATE_TRANSFORMS = 'generateTransforms';
	public const ACTION_GROUPS = 'groups';
	public const ACTION_RESTORE_WITH_RELATIONS = 'restoreWithRelations';

	/** how changing authors treats the authors an entry has: replacing them with the one picked, or adding it to them */
	public const AUTHORS_REPLACE = 'replace';
	public const AUTHORS_ADD = 'add';

	/** what changing the groups of users does with each group: adding them to it, or removing them from it */
	public const GROUP_ADD = 'add';
	public const GROUP_REMOVE = 'remove';

	/** most items selected at once, as the actions are applied to all of them within a single request */
	public const SELECTION_LIMIT = 1000;

	/**
	 * Actions offered for the selected items of a list, in the order of its menu; destructive ones ask for confirmation.
	 * Deleting (`preview`) shows what is deleted along with the items first, see DeletionService::getPreview(), and asks
	 * for confirmation (`confirm`) only if the preview can't be loaded; other actions ask for it right away.
	 * Labeling (`labels`) picks the labels to add and remove first, and keeps the items selected (`keep`).
	 * Downloading (`file`) sends the files, so it's submitted rather than applied in place from the context menu of an item;
	 * it shows the files sent first (`preview`), see BulkController::actionPreview(), as deleting the transforms of files
	 * shows the transforms deleted. Exporting is sent as a file too, after picking the exporter and format in its preview,
	 * see getExporters() and getExportFormats(). Changing the authors of entries and moving them to a section, moving files to
	 * a folder and generating their transforms, and changing the groups of users pick what they're changed to in their preview
	 * too (`fields`), so they're only applied from it; deleting users picks who their content is transferred to there, and
	 * restoring trashed items with their relations shows the trashed elements restored along with them. The dialog of a
	 * preview is titled after the action, or by its own `titles`, naming the item (`one`) or the number of items (`some`).
	 * The context menu of an item leaves out the actions that wouldn't change it, as it's already in their state (`unless`,
	 * see the `bulkItem` macro).
	 * Labeling needs the permission to label the items of the list's page, the other actions a permission of their own,
	 * and all of them the permission to view the page, or its trash for the trashed items.
	 *
	 * @return array<string,array{label:string,icon:string,confirm?:string,preview?:bool,titles?:array{one:string,some:string},loading?:string,labels?:bool,keep?:bool,file?:bool,fields?:bool,unless?:string}>
	 */
	public function getActions(string $list): array
	{
		$hub = Hub::getInstance();
		$user = Craft::$app->getUser()->getIdentity();
		$page = Page::fromList($list);

		if ($user === null || $page === null || !$hub->canViewList($list)) {
			return [];
		}

		// The actions of each permission, in the order of the menu.
		$can = fn (string $permission) => $hub->canAct($page, $permission);
		$delete = fn (string $confirm) => [self::ACTION_DELETE => ['label' => Craft::t('app', 'Delete'), 'icon' => 'trash', 'confirm' => $confirm, 'preview' => true]];
		$export = fn (string $permission) => $can($permission) ? [self::ACTION_EXPORT => [
			'label' => Craft::t('app', 'Export'),
			'icon' => 'file-export',
			'file' => true,
			'preview' => true,
			'titles' => [
				'one' => Craft::t('hub', 'Export “{name}”?'),
				'some' => Craft::t('hub', 'Export {count} selected items?'),
			],
			'loading' => Craft::t('hub', 'Checking which items are exported…'),
		]] : [];
		// Picking what an action changes the items to in its preview, which is titled after it.
		$picked = fn (string $label, string $icon, string $one, string $some, string $loading, array $more = []) => [
			'label' => $label,
			'icon' => $icon,
			'preview' => true,
			'fields' => true,
			'titles' => ['one' => $one, 'some' => $some],
			'loading' => $loading,
			...$more,
		];
		$trash = fn (array $export, string $restore, string $purge, string $confirm) => [
			...$export,
			...($can($restore) ? [
				self::ACTION_RESTORE => ['label' => Craft::t('app', 'Restore'), 'icon' => 'trash-arrow-up', 'unless' => 'unrestorable'],
				self::ACTION_RESTORE_WITH_RELATIONS => [
					'label' => Craft::t('hub', 'Restore with relations'),
					'icon' => 'diagram-project',
					'unless' => 'unrestorable',
					'preview' => true,
					'titles' => [
						'one' => Craft::t('hub', 'Restore “{name}” with its relations?'),
						'some' => Craft::t('hub', 'Restore {count} selected items with their relations?'),
					],
					'loading' => Craft::t('hub', 'Checking what is restored along with them…'),
				],
			] : []),
			...($can($purge) ? [self::ACTION_DELETE_PERMANENTLY => ['label' => Craft::t('app', 'Delete permanently'), 'icon' => 'trash', 'confirm' => $confirm]] : []),
		];
		/** @var LabelService $labels */
		$labels = Hub::getInstance()->labels;
		$label = $labels->canApply($list) && ($labels->getAllLabels() !== [] || $labels->canManage())
			? [self::ACTION_LABELS => ['label' => Craft::t('hub', 'Labels'), 'icon' => 'tags', 'labels' => true, 'keep' => true]]
			: [];


		return [...$label, ...match ($list) {
			ColumnService::LIST_ENTRIES => [
				...$export(Hub::PERMISSION_EXPORT_ENTRIES),
				...($can(Hub::PERMISSION_ENABLE_ENTRIES) ? [
					self::ACTION_ENABLE => ['label' => Craft::t('app', 'Enable'), 'icon' => 'toggle-on', 'unless' => 'enabled'],
					self::ACTION_DISABLE => ['label' => Craft::t('app', 'Disable'), 'icon' => 'toggle-off', 'unless' => 'disabled'],
				] : []),
				...($can(Hub::PERMISSION_CHANGE_AUTHORS) ? [
					self::ACTION_CHANGE_AUTHOR => $picked(
						Craft::t('hub', 'Change author'),
						'user-pen',
						Craft::t('hub', 'Change the author of “{name}”?'),
						Craft::t('hub', 'Change the author of {count} selected entries?'),
						Craft::t('hub', 'Checking whose authors can be changed…'),
					),
				] : []),
				...($can(Hub::PERMISSION_MOVE_ENTRIES) ? [
					self::ACTION_MOVE_TO_SECTION => $picked(
						Craft::t('hub', 'Move to section'),
						'arrow-right-arrow-left',
						Craft::t('hub', 'Move “{name}” to another section?'),
						Craft::t('hub', 'Move {count} selected entries to another section?'),
						Craft::t('hub', 'Checking which sections they can be moved to…'),
					),
				] : []),
				...($can(Hub::PERMISSION_DELETE_DRAFTS) ? [
					self::ACTION_DELETE_DRAFTS => [
						'label' => Craft::t('hub', 'Delete drafts'),
						'icon' => 'eraser',
						'confirm' => Craft::t('hub', 'Are you sure you want to delete the drafts of the selected entries? This can’t be undone.'),
						'preview' => true,
						'titles' => [
							'one' => Craft::t('hub', 'Delete the drafts of “{name}”?'),
							'some' => Craft::t('hub', 'Delete the drafts of {count} selected entries?'),
						],
						'loading' => Craft::t('hub', 'Checking which drafts are deleted…'),
					],
				] : []),
				...($can(Hub::PERMISSION_DELETE_ENTRIES) ? $delete(Craft::t('hub', 'Are you sure you want to delete the selected entries?')) : []),
			],
			ColumnService::LIST_FILES => [
				...$export(Hub::PERMISSION_EXPORT_ASSETS),
				...($can(Hub::PERMISSION_DOWNLOAD_ASSETS) ? [
					self::ACTION_DOWNLOAD => ['label' => Craft::t('app', 'Download'), 'icon' => 'download', 'file' => true, 'preview' => true],
				] : []),
				...($can(Hub::PERMISSION_MOVE_ASSETS) ? [
					self::ACTION_MOVE_TO_FOLDER => $picked(
						Craft::t('hub', 'Move to folder'),
						'folder-open',
						Craft::t('hub', 'Move “{name}” to another folder?'),
						Craft::t('hub', 'Move {count} selected files to another folder?'),
						Craft::t('hub', 'Checking which folders they can be moved to…'),
					),
				] : []),
				...($can(Hub::PERMISSION_GENERATE_TRANSFORMS) ? [
					self::ACTION_GENERATE_TRANSFORMS => $picked(
						Craft::t('hub', 'Generate transforms'),
						'wand-magic-sparkles',
						Craft::t('hub', 'Generate the transforms of “{name}”?'),
						Craft::t('hub', 'Generate the transforms of {count} selected files?'),
						Craft::t('hub', 'Checking which files can be transformed…'),
					),
				] : []),
				...($can(Hub::PERMISSION_DELETE_TRANSFORMS) ? [
					self::ACTION_DELETE_TRANSFORMS => [
						'label' => Craft::t('hub', 'Delete transforms'),
						'icon' => 'crop',
						'confirm' => Craft::t('hub', 'Are you sure you want to delete the transforms of the selected files? They are generated again when they are next requested.'),
						'preview' => true,
						'titles' => [
							'one' => Craft::t('hub', 'Delete the transforms of “{name}”?'),
							'some' => Craft::t('hub', 'Delete the transforms of {count} selected files?'),
						],
					],
				] : []),
				...($can(Hub::PERMISSION_DELETE_ASSETS) ? $delete(Craft::t('hub', 'Are you sure you want to delete the selected files?')) : []),
			],
			ColumnService::LIST_TRASHED_ENTRIES => $trash(
				$export(Hub::PERMISSION_EXPORT_TRASHED_ENTRIES),
				Hub::PERMISSION_RESTORE_ENTRIES,
				Hub::PERMISSION_PURGE_ENTRIES,
				Craft::t('hub', 'Are you sure you want to permanently delete the selected entries? This can’t be undone.'),
			),
			ColumnService::LIST_TRASHED_FILES => $trash(
				$export(Hub::PERMISSION_EXPORT_TRASHED_ASSETS),
				Hub::PERMISSION_RESTORE_ASSETS,
				Hub::PERMISSION_PURGE_ASSETS,
				Craft::t('hub', 'Are you sure you want to permanently delete the selected files? This can’t be undone.'),
			),
			ColumnService::LIST_USERS => [
				...$export(Hub::PERMISSION_EXPORT_USERS),
				...($can(Hub::PERMISSION_ASSIGN_USER_GROUPS) && Craft::$app->edition !== CmsEdition::Solo ? [
					self::ACTION_GROUPS => $picked(
						Craft::t('hub', 'Change groups'),
						'users',
						Craft::t('hub', 'Change the groups of “{name}”?'),
						Craft::t('hub', 'Change the groups of {count} selected users?'),
						Craft::t('hub', 'Checking which groups can be changed…'),
					),
				] : []),
				...($can(Hub::PERMISSION_SUSPEND_USERS) && $user->can('moderateUsers') ? [
					self::ACTION_SUSPEND => ['label' => Craft::t('app', 'Suspend'), 'icon' => 'user-lock', 'unless' => 'suspended', 'confirm' => Craft::t('hub', 'Are you sure you want to suspend the selected users?')],
					self::ACTION_UNSUSPEND => ['label' => Craft::t('app', 'Unsuspend'), 'icon' => 'user-check', 'unless' => 'active'],
				] : []),
				...($can(Hub::PERMISSION_DELETE_USERS) && $user->can('deleteUsers') ? [
					self::ACTION_DELETE => [
						'label' => Craft::t('app', 'Delete'),
						'icon' => 'user-xmark',
						'confirm' => Craft::t('hub', 'Are you sure you want to delete the selected users?'),
						'preview' => true,
						'titles' => [
							'one' => Craft::t('hub', 'Delete “{name}”?'),
							'some' => Craft::t('hub', 'Delete {count} selected users?'),
						],
						'loading' => Craft::t('hub', 'Checking which users are deleted…'),
					],
				] : []),
			],
			default => [],
		}];
	}

	/**
	 * Whether the current user may select several items of a list at once, to apply its actions to all of them, as they may
	 * bulk-edit the items of its page and apply any action to them.
	 */
	public function canSelect(string $list): bool
	{
		$page = Page::fromList($list);

		return $page !== null && Hub::getInstance()->canBulkEdit($page) && $this->getActions($list) !== [];
	}

	/**
	 * Applies an action to the items of a list with the IDs, skipping those the user may not change or that fail to save.
	 * Downloading and exporting change nothing, so they're left to getDownloadableFiles() and getExportQuery(), and labeling is left to applyLabels(). What the
	 * action changed is recorded in the activity log as a batch, named like the action.
	 *
	 * The actions picking what they change the items to in their preview take it from the params: changing authors the
	 * user (`authorId`) and whether it replaces the authors or is added to them (`authors`, see AUTHORS_*), moving entries
	 * the section (`sectionId`), moving files the folder (`folderId`), generating transforms the handles of the transforms
	 * (`transforms`), and changing groups what's done with each group by its ID (`groups`, see GROUP_*). Deleting users
	 * transfers their content to the user picked (`inheritorId`), if any.
	 *
	 * @param int[] $ids
	 * @param array<string,mixed> $params
	 * @return int[] IDs of the items the action was applied to
	 */
	public function apply(string $list, string $action, array $ids, array $params = []): array
	{
		if ($ids === [] || in_array($action, [self::ACTION_DOWNLOAD, self::ACTION_EXPORT, self::ACTION_LABELS], true) || !isset($this->getActions($list)[$action])) {
			return [];
		}

		App::maxPowerCaptain();

		/** @var ActivityService $activity */
		$activity = Hub::getInstance()->activity;
		$activity->beginBatch(ActivityAction::Bulk, ActivitySubject::fromList($list) ?? ActivitySubject::Element, $this->getActions($list)[$action]['label']);

		try {
			return $this->applyTo($list, $action, $ids, $params);
		} finally {
			$activity->endBatch();
		}
	}

	/**
	 * @param int[] $ids
	 * @param array<string,mixed> $params
	 * @return int[]
	 */
	private function applyTo(string $list, string $action, array $ids, array $params): array
	{
		return match ($list) {
			ColumnService::LIST_ENTRIES => $this->applyToEntries($this->getElementsOf($list, $ids, Entry::class), $action, $params),
			ColumnService::LIST_FILES => $this->applyToFiles($this->getElementsOf($list, $ids, Asset::class), $action, $params),
			ColumnService::LIST_USERS => $this->applyToUsers($this->getElementsOf($list, $ids, User::class), $action, $params),
			ColumnService::LIST_TRASHED_ENTRIES, ColumnService::LIST_TRASHED_FILES => $this->applyToTrashed($this->getElements($list, $ids), $action),
			default => [],
		};
	}

	/**
	 * Items of a list of elements with the IDs that are of the class, see getElements().
	 *
	 * @template T of ElementInterface
	 * @param int[] $ids
	 * @param class-string<T> $class
	 * @return T[]
	 */
	public function getElementsOf(string $list, array $ids, string $class): array
	{
		return array_values(array_filter($this->getElements($list, $ids), fn (ElementInterface $element) => $element instanceof $class));
	}

	/**
	 * Items of a list of elements with the IDs, whatever their status, on any site, in the trash for the trashed ones.
	 *
	 * @param int[] $ids
	 * @return ElementInterface[]
	 */
	public function getElements(string $list, array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		return match ($list) {
			ColumnService::LIST_ENTRIES => Entry::find()->id($ids)->site('*')->unique()->status(null)->all(),
			ColumnService::LIST_FILES => Asset::find()->id($ids)->site('*')->unique()->all(),
			ColumnService::LIST_USERS => User::find()->id($ids)->status(null)->all(),
			ColumnService::LIST_TRASHED_ENTRIES => Entry::find()->id($ids)->site('*')->unique()->status(null)->trashed()->all(),
			ColumnService::LIST_TRASHED_FILES => Asset::find()->id($ids)->site('*')->unique()->trashed()->all(),
			default => [],
		};
	}

	/**
	 * Trashed entries and files restored along with each of the trashed items, by the item's ID (see
	 * TrashService::getRelatedTrashed()): those the user may restore from the trash of their own page and save.
	 *
	 * @param int[] $ids
	 * @return array<int,array<int,Entry|Asset>>
	 */
	public function getRestoredRelations(array $ids): array
	{
		$hub = Hub::getInstance();
		$service = Craft::$app->getElements();
		$canEntries = $hub->canAct(Page::Entries, Hub::PERMISSION_RESTORE_ENTRIES);
		$canFiles = $hub->canAct(Page::Assets, Hub::PERMISSION_RESTORE_ASSETS);

		return array_filter(array_map(
			fn (array $elements) => array_filter($elements, fn (ElementInterface $element) => ($element instanceof Asset ? $canFiles : $canEntries) && $service->canSave($element)),
			$hub->trash->getRelatedTrashed($ids),
		));
	}

	/**
	 * Adds the labels with the IDs `$add` to the items of a list with the IDs, and removes those with the IDs `$remove`,
	 * skipping items that no longer exist. The items labeled are recorded in the activity log as a batch, like apply().
	 *
	 * @param int[] $ids
	 * @param int[] $add
	 * @param int[] $remove
	 * @return int[] IDs of the items labeled
	 */
	public function applyLabels(string $list, array $ids, array $add, array $remove): array
	{
		if ($ids === [] || !isset($this->getActions($list)[self::ACTION_LABELS])) {
			return [];
		}

		/** @var LabelService $labels */
		$labels = Hub::getInstance()->labels;
		$ids = $labels->existingIds($list, $ids);

		/** @var ActivityService $activity */
		$activity = Hub::getInstance()->activity;
		$activity->beginBatch(ActivityAction::Bulk, ActivitySubject::fromList($list) ?? ActivitySubject::Element, $this->getActions($list)[self::ACTION_LABELS]['label']);

		try {
			$labels->changeLabels($list, $ids, $add, $remove);
		} finally {
			$activity->endBatch();
		}

		return $ids;
	}

	/**
	 * IDs of the items selected in a list on a page, in the order they were selected.
	 *
	 * @return int[]
	 */
	public function getSelection(string $list, string $path): array
	{
		$ids = Craft::$app->getSession()->get($this->sessionKey('selection', $list, $path));

		return is_array($ids) ? array_values(array_filter($ids, 'is_int')) : [];
	}

	/**
	 * Keeps the IDs as the selection of a list on a page, without duplicates; an empty selection is dropped.
	 *
	 * @param int[] $ids
	 * @return int[] the kept selection
	 */
	public function setSelection(string $list, string $path, array $ids): array
	{
		$session = Craft::$app->getSession();
		$key = $this->sessionKey('selection', $list, $path);
		$ids = array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));

		$ids !== [] ? $session->set($key, $ids) : $session->remove($key);

		return $ids;
	}

	/**
	 * Keeps the filters a list on a page was last shown with, so all items matching them can be selected.
	 */
	public function rememberFilter(string $list, string $path, object $filter): void
	{
		Craft::$app->getSession()->set($this->sessionKey('filter', $list, $path), $filter);
	}

	/**
	 * Filters a list on a page was last shown with, if any.
	 */
	public function getRememberedFilter(string $list, string $path): ?object
	{
		$filter = Craft::$app->getSession()->get($this->sessionKey('filter', $list, $path));

		return is_object($filter) ? $filter : null;
	}

	/**
	 * IDs of the items of a list matching its filters, limited to the given IDs, or to a number of them.
	 * Filters of another list match nothing.
	 *
	 * @param int[]|null $ids
	 * @return int[]
	 */
	public function getMatchingIds(string $list, object $filter, ?array $ids = null, ?int $limit = null): array
	{
		$hub = Hub::getInstance();

		if ($ids === []) {
			return [];
		}

		$query = match (true) {
			$list === ColumnService::LIST_ENTRIES && $filter instanceof EntryFilter => $hub->entries->getQuery($filter),
			$list === ColumnService::LIST_FILES && $filter instanceof FileFilter => $hub->files->getQuery($filter),
			$list === ColumnService::LIST_USERS && $filter instanceof UserFilter => $hub->users->getQuery($filter),
			$list === ColumnService::LIST_TRASHED_ENTRIES && $filter instanceof TrashedEntryFilter => $hub->trash->getEntryQuery($filter),
			$list === ColumnService::LIST_TRASHED_FILES && $filter instanceof TrashedFileFilter => $hub->trash->getFileQuery($filter),
			default => null,
		};

		if ($query instanceof ElementQuery) {
			$query->orderBy(null)->limit($limit);

			if ($ids !== null) {
				$query->id($ids);
			}

			return array_map('intval', $query->ids());
		}

		$items = match (true) {
			$list === ColumnService::LIST_SECTIONS && $filter instanceof SectionFilter => $hub->lists->getSections($filter),
			$list === ColumnService::LIST_ENTRY_TYPES && $filter instanceof EntryTypeFilter => $hub->lists->getEntryTypes($filter),
			$list === ColumnService::LIST_FIELDS && $filter instanceof FieldFilter => $hub->lists->getFields($filter),
			$list === ColumnService::LIST_VOLUMES && $filter instanceof VolumeFilter => $hub->lists->getVolumes($filter),
			$list === ColumnService::LIST_USER_GROUPS && $filter instanceof UserGroupFilter => $hub->lists->getUserGroups($hub->users->getGroups(), $filter),
			default => [],
		};

		// The lists are keyed by the IDs of their items.
		$found = array_keys($items);
		$found = $ids !== null ? array_values(array_intersect($found, $ids)) : $found;

		return $limit !== null ? array_slice($found, 0, $limit) : $found;
	}

	/**
	 * Files with the IDs that the user may view, and so download.
	 *
	 * @param int[] $ids
	 * @return Asset[]
	 */
	public function getDownloadableFiles(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$service = Craft::$app->getElements();

		return array_values(array_filter(
			Asset::find()->id($ids)->site('*')->unique()->all(),
			fn (Asset $asset) => $service->canView($asset),
		));
	}

	/**
	 * Craft's exporters of the element type of a list, by their class, as its element indexes offer them: its own, and those
	 * registered for it by plugins (see Element::EVENT_REGISTER_EXPORTERS), for all of its sources, as Hub's lists span
	 * them. Lists of other items have none.
	 *
	 * @return array<class-string<ElementExporterInterface>,ElementExporterInterface>
	 */
	public function getExporters(string $list): array
	{
		$elementType = $this->elementType($list);

		if ($elementType === null) {
			return [];
		}

		$exporters = [];

		foreach ($elementType::exporters('*') as $exporter) {
			if ($exporter instanceof ElementExporterInterface) {
				$exporter->setElementType($elementType);
			} else {
				$exporter = Craft::$app->getElements()->createExporter([
					...(is_string($exporter) ? ['type' => $exporter] : $exporter),
					'elementType' => $elementType,
				]);
			}

			$exporters[$exporter::class] = $exporter;
		}

		return $exporters;
	}

	/**
	 * Formats the data of formattable exporters is sent in, by Craft's response formatters, as its element indexes offer them.
	 *
	 * @return array<string,string> labels by format
	 */
	public function getExportFormats(): array
	{
		return [
			Response::FORMAT_CSV => 'CSV',
			Response::FORMAT_XLSX => 'XLSX',
			Response::FORMAT_JSON => 'JSON',
			Response::FORMAT_XML => 'XML',
			Response::FORMAT_YAML => 'YAML',
		];
	}

	/**
	 * Items of a list with the IDs that the user may view, and so export, in the order of the IDs.
	 *
	 * @param int[] $ids
	 * @return ElementInterface[]
	 */
	public function getExportableElements(string $list, array $ids): array
	{
		$query = $ids !== [] ? $this->exportQuery($list) : null;

		if ($query === null) {
			return [];
		}

		$service = Craft::$app->getElements();

		return array_values(array_filter(
			$query->id($ids)->fixedOrder()->all(),
			fn (ElementInterface $element) => $service->canView($element),
		));
	}

	/**
	 * Query of exportable items of a list (see getExportableElements()), for an exporter to export, in their order.
	 *
	 * @param ElementInterface[] $elements
	 */
	public function getExportQuery(string $list, array $elements): ?ElementQuery
	{
		return $this->exportQuery($list)?->id(array_map(fn (ElementInterface $element) => (int)$element->id, $elements))->fixedOrder();
	}

	/**
	 * Query of the items of a list, on the counted sites, whatever their status, in the trash for the trashed ones.
	 */
	private function exportQuery(string $list): ?ElementQuery
	{
		$elementType = $this->elementType($list);

		if ($elementType === null) {
			return null;
		}

		/** @var ElementQuery $query */
		$query = $elementType::find()->status(null);

		if ($elementType !== User::class) {
			$query->siteId(Hub::getInstance()->getSiteIds())->unique();
		}

		if (in_array($list, [ColumnService::LIST_TRASHED_ENTRIES, ColumnService::LIST_TRASHED_FILES], true)) {
			$query->trashed();
		}

		return $query;
	}

	/**
	 * Element type of the items of a list, for the lists of elements.
	 *
	 * @return class-string<ElementInterface>|null
	 */
	private function elementType(string $list): ?string
	{
		return match ($list) {
			ColumnService::LIST_ENTRIES, ColumnService::LIST_TRASHED_ENTRIES => Entry::class,
			ColumnService::LIST_FILES, ColumnService::LIST_TRASHED_FILES => Asset::class,
			ColumnService::LIST_USERS => User::class,
			default => null,
		};
	}

	/**
	 * Changes the authors of entries to the user picked, see changeAuthors(), moves them to the section picked, like Craft's
	 * own moving, see EntryService::canMoveTo(), or deletes their drafts the user may delete, skipping entries without any.
	 * Other actions are applied as to any element.
	 *
	 * @param Entry[] $entries
	 * @param array<string,mixed> $params
	 * @return int[]
	 */
	private function applyToEntries(array $entries, string $action, array $params): array
	{
		/** @var EntryService $hubEntries */
		$hubEntries = Hub::getInstance()->entries;

		if ($action === self::ACTION_CHANGE_AUTHOR) {
			$authorId = $this->intParam($params, 'authorId');
			$author = $authorId > 0 ? User::find()->id($authorId)->status(User::STATUS_ACTIVE)->one() : null;
			$add = ($params['authors'] ?? null) === self::AUTHORS_ADD;

			return $author === null ? [] : $this->succeeded($entries, fn (Entry $entry) => $this->changeAuthors($entry, $author, $add));
		}

		if ($action === self::ACTION_MOVE_TO_SECTION) {
			$section = Craft::$app->getEntries()->getSectionById($this->intParam($params, 'sectionId'));
			$service = Craft::$app->getEntries();

			return $section === null ? [] : $this->succeeded($entries, function (Entry $entry) use ($hubEntries, $service, $section) {
				if (!$hubEntries->canMoveTo($entry, $section)) {
					return false;
				}

				try {
					return $service->moveEntryToSection($entry, $section);
				} catch (Throwable $e) {
					Craft::warning("Couldn’t move entry $entry->id to section $section->id: {$e->getMessage()}", __METHOD__);

					return false;
				}
			});
		}

		if ($action === self::ACTION_DELETE_DRAFTS) {
			[$withDrafts, $drafts] = $hubEntries->getDeletableDrafts(array_map(fn (Entry $entry) => (int)$entry->id, $entries));
			$service = Craft::$app->getElements();

			return $this->succeeded($withDrafts, fn (Entry $entry) => array_filter(array_map(fn (Entry $draft) => $service->deleteElement($draft, true), $drafts[$entry->id])) !== []);
		}

		return $this->applyToElements($entries, $action);
	}

	/**
	 * Replaces the authors of the entry with the user, or adds the user to them, as far as the current user may change
	 * them (see EntryService::canChangeAuthors()) and the user may be an author of it: they may view the entries of its
	 * section, as Craft requires. Entries with those authors already are left as they are.
	 */
	private function changeAuthors(Entry $entry, User $author, bool $add): bool
	{
		/** @var EntryService $hubEntries */
		$hubEntries = Hub::getInstance()->entries;
		$section = $entry->getSection();

		if ($section === null || !$hubEntries->canChangeAuthors($entry) || !$author->can("viewEntries:$section->uid")) {
			return false;
		}

		$authorIds = $entry->getAuthorIds();
		$changed = $add ? array_values(array_unique([...$authorIds, (int)$author->id])) : [(int)$author->id];

		if ($changed === $authorIds) {
			return true;
		}

		$entry->setAuthorIds($changed);

		return Craft::$app->getElements()->saveElement($entry);
	}

	/**
	 * Adds the users to the groups and removes them from the groups as picked, by the groups' IDs (see GROUP_*), as far as
	 * the current user may assign those (see UserService::getAssignableGroups()), and as far as they may save the users.
	 * Users already in the groups they're added to and not in those they're removed from are left as they are.
	 *
	 * @param User[] $users
	 * @param array<mixed,mixed> $picked
	 * @return int[]
	 */
	private function changeGroups(array $users, array $picked): array
	{
		/** @var UserService $hubUsers */
		$hubUsers = Hub::getInstance()->users;
		$assignable = $hubUsers->getAssignableGroups();
		$add = [];
		$remove = [];

		foreach ($picked as $id => $change) {
			if (isset($assignable[(int)$id]) && in_array($change, [self::GROUP_ADD, self::GROUP_REMOVE], true)) {
				$change === self::GROUP_ADD ? $add[] = (int)$id : $remove[] = (int)$id;
			}
		}

		$current = Craft::$app->getUser()->getIdentity();

		if ($current === null || ($add === [] && $remove === [])) {
			return [];
		}

		$elements = Craft::$app->getElements();
		$service = Craft::$app->getUsers();

		return $this->succeeded($users, function (User $user) use ($current, $elements, $service, $add, $remove) {
			if ($user->id === $current->id || !$elements->canSave($user)) {
				return false;
			}

			$groupIds = array_map(fn (UserGroup $group) => (int)$group->id, $user->getGroups());
			$changed = array_values(array_diff(array_unique([...$groupIds, ...$add]), $remove));
			sort($groupIds);
			sort($changed);

			return $changed === $groupIds || $service->assignUserToGroups((int)$user->id, $changed);
		});
	}

	/**
	 * Positive integer of a param, or 0.
	 *
	 * @param array<string,mixed> $params
	 */
	private function intParam(array $params, string $name): int
	{
		$value = $params[$name] ?? null;

		return is_numeric($value) && (int)$value > 0 ? (int)$value : 0;
	}

	/**
	 * Enables, disables or deletes elements like Craft's element actions: enabling enables them for the site they're
	 * loaded in and validates them as live, disabling disables them on all sites, and deleting moves them to the trash.
	 *
	 * @param ElementInterface[] $elements
	 * @return int[]
	 */
	private function applyToElements(array $elements, string $action): array
	{
		$service = Craft::$app->getElements();

		return $this->succeeded($elements, function (ElementInterface $element) use ($service, $action) {
			if ($action === self::ACTION_DELETE) {
				return $service->canDelete($element) && $service->deleteElement($element);
			}

			if (!$service->canSave($element)) {
				return false;
			}

			/** @var Element $element */
			if ($action === self::ACTION_ENABLE) {
				$element->enabled = true;
				$element->setEnabledForSite(true);
				$element->setScenario(Element::SCENARIO_LIVE);
			} else {
				$element->enabled = false;
			}

			return $service->saveElement($element);
		});
	}

	/**
	 * Deletes files, or their generated transforms, which Craft generates again when they're next requested, moves them to
	 * the folder picked, like Craft's own moving, skipping those whose filename the folder has already, or queues
	 * generating the transforms picked, see FileService::generateTransforms(), skipping files with all of them generated.
	 *
	 * @param Asset[] $assets
	 * @param array<string,mixed> $params
	 * @return int[]
	 */
	private function applyToFiles(array $assets, string $action, array $params): array
	{
		/** @var FileService $files */
		$files = Hub::getInstance()->files;

		if ($action === self::ACTION_MOVE_TO_FOLDER) {
			$folder = $files->getFolderTargets()[$this->intParam($params, 'folderId')] ?? null;
			$service = Craft::$app->getAssets();

			return $folder === null ? [] : $this->succeeded($assets, fn (Asset $asset) => $files->canMoveTo($asset, $folder) && $service->moveAsset($asset, $folder));
		}

		if ($action === self::ACTION_GENERATE_TRANSFORMS) {
			$handles = is_array($params['transforms'] ?? null) ? array_map('strval', $params['transforms']) : [];
			$transforms = array_values(array_intersect_key($files->getNamedTransforms(), array_flip($handles)));
			$transformable = $files->getTransformableFiles(array_map(fn (Asset $asset) => (int)$asset->id, $assets));

			return $transforms === [] ? [] : $this->succeeded($transformable, fn (Asset $asset) => $files->generateTransforms($asset, $transforms) > 0);
		}

		if ($action !== self::ACTION_DELETE_TRANSFORMS) {
			return $this->applyToElements($assets, $action);
		}

		$service = Craft::$app->getElements();
		$transforms = $files->getTransformsOf($assets);

		return $this->succeeded($assets, fn (Asset $asset) => $service->canSave($asset)
			&& $files->deleteTransforms($asset, array_column($transforms[$asset->id] ?? [], 'id')) > 0);
	}

	/**
	 * Restores trashed elements like Craft's restore action, as far as the user may save them, along with the trashed
	 * elements related to them when restoring them with their relations (see getRestoredRelations()), or deletes them
	 * permanently, as far as they may delete them. Files whose file wasn't kept can't be restored, see Asset::beforeRestore().
	 *
	 * @param ElementInterface[] $elements
	 * @return int[]
	 */
	private function applyToTrashed(array $elements, string $action): array
	{
		$service = Craft::$app->getElements();

		if ($action === self::ACTION_DELETE_PERMANENTLY) {
			return $this->succeeded($elements, fn (ElementInterface $element) => $service->canDelete($element) && $service->deleteElement($element, true));
		}

		$relations = $action === self::ACTION_RESTORE_WITH_RELATIONS ? $this->getRestoredRelations(array_map(fn (ElementInterface $element) => (int)$element->id, $elements)) : [];
		$restored = [];

		return $this->succeeded($elements, function (ElementInterface $element) use ($service, $relations, &$restored) {
			if (!$service->canSave($element) || !$service->restoreElement($element)) {
				return false;
			}

			// An element related to several items is restored once.
			foreach ($relations[$element->id] ?? [] as $id => $related) {
				if (!isset($restored[$id])) {
					$restored[$id] = $service->restoreElement($related);
				}
			}

			return true;
		});
	}

	/**
	 * Suspends or unsuspends users, changes their groups or deletes them, leaving out the current one, so users can't lock
	 * themselves out. Their groups are changed as far as the user may assign them (see UserService::getAssignableGroups()),
	 * and users are deleted as far as the user may delete them (see UserService::getDeletableUsers()), transferring their
	 * content to the user picked, as Craft does, who isn't deleted then.
	 *
	 * @param User[] $users
	 * @param array<string,mixed> $params
	 * @return int[]
	 */
	private function applyToUsers(array $users, string $action, array $params): array
	{
		/** @var UserService $hubUsers */
		$hubUsers = Hub::getInstance()->users;
		$ids = array_map(fn (User $user) => (int)$user->id, $users);

		if ($action === self::ACTION_DELETE) {
			$inheritorId = $this->intParam($params, 'inheritorId');
			$inheritor = $inheritorId > 0 ? User::find()->id($inheritorId)->status(User::STATUS_ACTIVE)->one() : null;

			if ($inheritorId > 0 && $inheritor === null) {
				return [];
			}

			$elements = Craft::$app->getElements();

			return $this->succeeded($hubUsers->getDeletableUsers($ids), function (User $user) use ($elements, $inheritor) {
				if ($inheritor !== null && $user->id === $inheritor->id) {
					return false;
				}

				$user->inheritorOnDelete = $inheritor;

				return $elements->deleteElement($user);
			});
		}

		if ($action === self::ACTION_GROUPS) {
			return $this->changeGroups($users, is_array($params['groups'] ?? null) ? $params['groups'] : []);
		}

		$service = Craft::$app->getUsers();
		$current = Craft::$app->getUser()->getIdentity();

		return $this->succeeded($users, function (User $user) use ($service, $current, $action) {
			if ($current === null || $user->id === $current->id || !$service->canSuspend($current, $user)) {
				return false;
			}

			if ($action === self::ACTION_SUSPEND && !$user->suspended) {
				$service->suspendUser($user);
			} elseif ($action === self::ACTION_UNSUSPEND && $user->suspended) {
				$service->unsuspendUser($user);
			}

			return true;
		});
	}

	/**
	 * IDs of the elements the callback succeeded for.
	 *
	 * @template T of ElementInterface
	 * @param T[] $items
	 * @param callable(T):bool $callback
	 * @return int[]
	 */
	private function succeeded(array $items, callable $callback): array
	{
		return array_values(array_map(
			fn (ElementInterface $item) => (int)$item->id,
			array_filter($items, fn (mixed $item) => $callback($item)),
		));
	}

	private function sessionKey(string $kind, string $list, string $path): string
	{
		return "hub:bulk:$kind:$list:$path";
	}
}
