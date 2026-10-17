<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\ElementExporterInterface;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\App;
use craft\web\Response;
use SomehowDigital\Craft\Hub\bulk\BulkAction;
use SomehowDigital\Craft\Hub\bulk\BulkPreview;
use SomehowDigital\Craft\Hub\bulk\ChangeAuthor;
use SomehowDigital\Craft\Hub\bulk\ChangeGroups;
use SomehowDigital\Craft\Hub\bulk\Delete;
use SomehowDigital\Craft\Hub\bulk\DeleteDrafts;
use SomehowDigital\Craft\Hub\bulk\DeletePermanently;
use SomehowDigital\Craft\Hub\bulk\DeleteTransforms;
use SomehowDigital\Craft\Hub\bulk\DeleteUsers;
use SomehowDigital\Craft\Hub\bulk\Download;
use SomehowDigital\Craft\Hub\bulk\Export;
use SomehowDigital\Craft\Hub\bulk\GenerateTransforms;
use SomehowDigital\Craft\Hub\bulk\Labels;
use SomehowDigital\Craft\Hub\bulk\MoveToFolder;
use SomehowDigital\Craft\Hub\bulk\MoveToSection;
use SomehowDigital\Craft\Hub\bulk\Restore;
use SomehowDigital\Craft\Hub\bulk\RestoreWithRelations;
use SomehowDigital\Craft\Hub\bulk\SendsFile;
use SomehowDigital\Craft\Hub\bulk\SetEnabled;
use SomehowDigital\Craft\Hub\bulk\SetSuspended;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\ListType;
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
use yii\base\Component;

/**
 * Actions applied to the items selected in a list, by the list's name (see ColumnService::LIST_*), each a BulkAction.
 * Each action needs a permission of the list's page (see Page::actionPermissions()), and items are changed as far as
 * Craft lets the user change each of them: entries and files as far as they may save, move or delete each, users as far
 * as they may suspend, save or delete each. Entries, files and users, in the trash too, are exported by Craft's
 * exporters of their element type, as far as the user may view each. Trashed entries and files are restored or deleted
 * permanently by users who may view the trash and restore or delete them permanently from the Hub, as far as Craft lets
 * them save or delete each. Sections, entry types, fields, volumes and user groups, the structure, are never deleted
 * from the Hub, only labeled. Items are labeled by users who may label those of the page, while labels are enabled and
 * there are labels or they can create some. Several items are selected at once only by users who may bulk-edit the
 * page; others apply the actions to one item at a time.
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

	/**
	 * Actions offered for the selected items of a list, in the order of its menu, as BulkAction::definition() describes them:
	 * those the user has the permission of, as they may view the list's page, or its trash for the trashed items.
	 *
	 * @return array<string,array{label:string,icon:string,confirm?:string,preview?:bool,titles?:array{one:string,some:string},loading?:string,labels?:bool,keep?:bool,file?:bool,fields?:bool,unless?:string}>
	 */
	public function getActions(string $list): array
	{
		return array_map(fn (BulkAction $action) => $action->definition(), $this->available($list));
	}

	/**
	 * Actions the user may apply to the items of a list.
	 *
	 * @return array<string,BulkAction>
	 */
	private function available(string $list): array
	{
		if (Craft::$app->getUser()->getIdentity() === null || Page::fromList($list) === null || !Hub::getInstance()->access->canViewList($list)) {
			return [];
		}

		return array_filter($this->actionsOf($list), fn (BulkAction $action) => $action->isAvailable());
	}

	/**
	 * Actions of a list, in the order of its menu. Sections, entry types, fields, volumes and user groups are only labeled.
	 *
	 * @return array<string,BulkAction>
	 */
	private function actionsOf(string $list): array
	{
		$trash = [
			self::ACTION_EXPORT => new Export($list),
			self::ACTION_RESTORE => new Restore($list),
			self::ACTION_RESTORE_WITH_RELATIONS => new RestoreWithRelations($list),
			self::ACTION_DELETE_PERMANENTLY => new DeletePermanently($list),
		];

		return [self::ACTION_LABELS => new Labels($list), ...match ($list) {
			ColumnService::LIST_ENTRIES => [
				self::ACTION_EXPORT => new Export($list),
				self::ACTION_ENABLE => new SetEnabled($list, true),
				self::ACTION_DISABLE => new SetEnabled($list, false),
				self::ACTION_CHANGE_AUTHOR => new ChangeAuthor($list),
				self::ACTION_MOVE_TO_SECTION => new MoveToSection($list),
				self::ACTION_DELETE_DRAFTS => new DeleteDrafts($list),
				self::ACTION_DELETE => new Delete($list),
			],
			ColumnService::LIST_FILES => [
				self::ACTION_EXPORT => new Export($list),
				self::ACTION_DOWNLOAD => new Download($list),
				self::ACTION_MOVE_TO_FOLDER => new MoveToFolder($list),
				self::ACTION_GENERATE_TRANSFORMS => new GenerateTransforms($list),
				self::ACTION_DELETE_TRANSFORMS => new DeleteTransforms($list),
				self::ACTION_DELETE => new Delete($list),
			],
			ColumnService::LIST_TRASHED_ENTRIES, ColumnService::LIST_TRASHED_FILES => $trash,
			ColumnService::LIST_USERS => [
				self::ACTION_EXPORT => new Export($list),
				self::ACTION_GROUPS => new ChangeGroups($list),
				self::ACTION_SUSPEND => new SetSuspended($list, true),
				self::ACTION_UNSUSPEND => new SetSuspended($list, false),
				self::ACTION_DELETE => new DeleteUsers($list),
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

		return $page !== null && Hub::getInstance()->access->canBulkEdit($page) && $this->getActions($list) !== [];
	}

	/**
	 * Applies an action to the items of a list with the IDs, skipping those the user may not change or that fail to save.
	 * What it changed is recorded in the activity log as a batch, named like the action. Actions sending a file are left
	 * to send().
	 *
	 * @param int[] $ids
	 * @param array<string,mixed> $params what was picked in its preview
	 * @return int[] IDs of the items the action was applied to
	 */
	public function apply(string $list, string $action, array $ids, array $params = []): array
	{
		$bulkAction = $this->available($list)[$action] ?? null;

		if ($ids === [] || $bulkAction === null || $bulkAction instanceof SendsFile) {
			return [];
		}

		App::maxPowerCaptain();

		$activity = Hub::getInstance()->activity;
		$activity->beginBatch(ActivityAction::Bulk, ActivitySubject::fromList($list) ?? ActivitySubject::Element, $bulkAction->definition()['label']);

		try {
			return $bulkAction->apply($ids, $params);
		} finally {
			$activity->endBatch();
		}
	}

	/**
	 * Sends the file of an action for the items of a list with the IDs, or null if there's nothing to send.
	 *
	 * @param int[] $ids
	 * @param array<string,mixed> $params what was picked in its preview
	 */
	public function send(string $list, string $action, Response $response, array $ids, array $params = []): ?Response
	{
		$bulkAction = $this->available($list)[$action] ?? null;

		return $bulkAction instanceof SendsFile ? $bulkAction->send($response, $ids, $params) : null;
	}

	/**
	 * Preview of an action for the items of a list with the IDs, listed by the page, or null for actions without one.
	 *
	 * @param int[] $ids
	 */
	public function preview(string $list, string $action, array $ids, int $page): ?BulkPreview
	{
		return ($this->available($list)[$action] ?? null)?->preview($ids, $page);
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
	 * Items of a list of elements with the IDs, whatever their status, on the counted sites (see
	 * SiteScopeService::inSiteScope()), in the trash for the trashed ones.
	 *
	 * @param int[] $ids
	 * @return ElementInterface[]
	 */
	public function getElements(string $list, array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$scoped = fn (ElementQuery $query) => Hub::getInstance()->siteScope->inSiteScope($query)->id($ids);

		return match ($list) {
			ColumnService::LIST_ENTRIES => $scoped(Entry::find())->status(null)->all(),
			ColumnService::LIST_FILES => $scoped(Asset::find())->all(),
			ColumnService::LIST_USERS => User::find()->id($ids)->status(null)->all(),
			ColumnService::LIST_TRASHED_ENTRIES => $scoped(Entry::find())->status(null)->trashed()->all(),
			ColumnService::LIST_TRASHED_FILES => $scoped(Asset::find())->trashed()->all(),
			default => [],
		};
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
			Hub::getInstance()->siteScope->inSiteScope(Asset::find())->id($ids)->all(),
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
		$elementType = ListType::tryFrom($list)?->elementType();

		if ($elementType === null) {
			return [];
		}

		$exporters = [];

		foreach ($elementType::exporters('*') as $exporter) {
			if ($exporter instanceof ElementExporterInterface) {
				$exporter->setElementType($elementType);
			} else {
				/** @var array{type:class-string<ElementExporterInterface>} $config */
				$config = is_string($exporter) ? ['type' => $exporter] : $exporter;
				$exporter = Craft::$app->getElements()->createExporter([...$config, 'elementType' => $elementType]);
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
		$elementType = ListType::tryFrom($list)?->elementType();

		if ($elementType === null) {
			return null;
		}

		/** @var ElementQuery $query */
		$query = $elementType::find()->status(null);

		if ($elementType !== User::class) {
			$query->siteId(Hub::getInstance()->siteScope->getSiteIds())->unique();
		}

		if (ListType::from($list)->isTrash()) {
			$query->trashed();
		}

		return $query;
	}
}
