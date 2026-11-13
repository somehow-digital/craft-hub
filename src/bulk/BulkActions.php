<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Closure;
use craft\enums\CmsEdition;
use craft\helpers\App;
use craft\services\Assets;
use craft\services\Elements;
use craft\services\Entries;
use craft\services\Path;
use craft\services\Users;
use craft\web\User as WebUser;
use SomehowDigital\Craft\Hub\base\SendsFileInterface;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\ListType;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\models\ListSelection;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\DeletionService;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\services\SelectionService;
use SomehowDigital\Craft\Hub\services\TrashService;
use SomehowDigital\Craft\Hub\services\UserService;
use yii\base\Application;
use yii\base\Component;

/**
 * Actions applied to the items selected in a list, by the list's name (see ListType), each a BaseBulkAction.
 * Each action needs a permission of the list's page (see Page::actionPermissions()), and items are changed as far as
 * Craft lets the user change each of them: entries and files as far as they may save, move or delete each, users as far
 * as they may suspend, save or delete each. Entries, files and users, in the trash too, are exported by Craft's
 * exporters of their element type, as far as the user may view each. Trashed entries and files are restored or deleted
 * permanently by users who may view the trash and restore or delete them permanently from the Hub, as far as Craft lets
 * them save or delete each. Sections, entry types, fields, volumes and user groups, the structure, are never deleted
 * from the Hub, only labeled. Items are labeled by users who may label those of the page, while labels are enabled and
 * there are labels or they can create some. Several items are selected at once only by users who may bulk-edit the
 * page; others apply the actions to one item at a time.
 *
 * @internal
 */
final class BulkActions extends Component
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
	 * @param Closure(): CmsEdition $edition
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly AccessService $access,
		private readonly ActivityService $activity,
		private readonly BulkService $bulk,
		private readonly DeletionService $deletion,
		private readonly EntryService $entries,
		private readonly FileService $files,
		private readonly UserService $users,
		private readonly TrashService $trash,
		private readonly LabelService $labels,
		private readonly SelectionService $selection,
		private readonly Elements $craftElements,
		private readonly Users $craftUsers,
		private readonly Assets $craftAssets,
		private readonly Entries $craftEntries,
		private readonly Path $craftPath,
		private readonly Application $craftApp,
		private readonly Closure $edition,
		private readonly ?WebUser $user = null,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * The selection of a list on a page, reviewed as the query params of the request ask for it, along with the actions
	 * offered for it.
	 *
	 * @param string $path page the list is on
	 */
	public function selectionFor(string $list, string $path): ListSelection
	{
		$actions = $this->getActions($list);
		$multiple = $actions !== [] && $this->canSelect($list);

		return new ListSelection(
			$list,
			$path,
			$actions,
			$multiple,
			$multiple ? $this->selection->getSelection($list, $path) : [],
			$multiple && $this->selection->isRequested(),
		);
	}

	/**
	 * Actions offered for the selected items of a list, in the order of its menu, as BaseBulkAction::definition() describes them:
	 * those the user has the permission of, as they may view the list's page, or its trash for the trashed items.
	 *
	 * @return array<string,array{label:string,icon:string,confirm?:string,preview?:bool,titles?:array{one:string,some:string},loading?:string,labels?:bool,keep?:bool,file?:bool,fields?:bool,unless?:string}>
	 */
	public function getActions(string $list): array
	{
		return array_map(fn(BaseBulkAction $action): array => $action->definition(), $this->available($list));
	}

	/**
	 * Actions the user may apply to the items of a list.
	 *
	 * @return array<string,BaseBulkAction>
	 */
	private function available(string $list): array
	{
		if ($this->user?->getIdentity() === null || !Page::fromList($list) instanceof Page || !$this->access->canViewList($list)) {
			return [];
		}

		return array_filter($this->actionsOf($list), fn(BaseBulkAction $action): bool => $action->isAvailable());
	}

	/**
	 * Actions of a list, in the order of its menu. Sections, entry types, fields, volumes and user groups are only labeled.
	 *
	 * @return array<string,BaseBulkAction>
	 */
	private function actionsOf(string $list): array
	{
		$trash = [
			self::ACTION_EXPORT => new Export($list, $this->access, $this->bulk, $this->activity, $this->craftApp),
			self::ACTION_RESTORE => new Restore($list, $this->access, $this->bulk, $this->craftElements),
			self::ACTION_RESTORE_WITH_RELATIONS => new RestoreWithRelations($list, $this->access, $this->bulk, $this->trash, $this->craftElements),
			self::ACTION_DELETE_PERMANENTLY => new DeletePermanently($list, $this->access, $this->bulk, $this->craftElements),
		];

		return [self::ACTION_LABELS => new Labels($list, $this->access, $this->labels), ...match ($list) {
			ListType::Entries->value => [
				self::ACTION_EXPORT => new Export($list, $this->access, $this->bulk, $this->activity, $this->craftApp),
				self::ACTION_ENABLE => new SetEnabled($list, $this->access, $this->bulk, $this->craftElements, true),
				self::ACTION_DISABLE => new SetEnabled($list, $this->access, $this->bulk, $this->craftElements, false),
				self::ACTION_CHANGE_AUTHOR => new ChangeAuthor($list, $this->access, $this->bulk, $this->entries, $this->craftElements),
				self::ACTION_MOVE_TO_SECTION => new MoveToSection($list, $this->access, $this->bulk, $this->entries, $this->craftEntries),
				self::ACTION_DELETE_DRAFTS => new DeleteDrafts($list, $this->access, $this->bulk, $this->entries, $this->craftElements),
				self::ACTION_DELETE => new Delete($list, $this->access, $this->bulk, $this->deletion, $this->craftElements),
			],
			ListType::Files->value => [
				self::ACTION_EXPORT => new Export($list, $this->access, $this->bulk, $this->activity, $this->craftApp),
				self::ACTION_DOWNLOAD => new Download($list, $this->access, $this->bulk, $this->activity, $this->craftPath),
				self::ACTION_MOVE_TO_FOLDER => new MoveToFolder($list, $this->access, $this->bulk, $this->files, $this->craftElements, $this->craftAssets),
				self::ACTION_GENERATE_TRANSFORMS => new GenerateTransforms($list, $this->access, $this->bulk, $this->files),
				self::ACTION_DELETE_TRANSFORMS => new DeleteTransforms($list, $this->access, $this->bulk, $this->files, $this->craftElements),
				self::ACTION_DELETE => new Delete($list, $this->access, $this->bulk, $this->deletion, $this->craftElements),
			],
			ListType::TrashedEntries->value, ListType::TrashedFiles->value => $trash,
			ListType::Users->value => [
				self::ACTION_EXPORT => new Export($list, $this->access, $this->bulk, $this->activity, $this->craftApp),
				self::ACTION_GROUPS => new ChangeGroups($list, $this->access, $this->bulk, $this->users, $this->craftElements, $this->craftUsers, $this->edition, $this->user),
				self::ACTION_SUSPEND => new SetSuspended($list, $this->access, $this->bulk, $this->craftUsers, $this->user, true),
				self::ACTION_UNSUSPEND => new SetSuspended($list, $this->access, $this->bulk, $this->craftUsers, $this->user, false),
				self::ACTION_DELETE => new DeleteUsers($list, $this->access, $this->bulk, $this->users, $this->craftElements, $this->craftEntries, $this->user),
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

		return $page instanceof Page && $this->access->canBulkEdit($page) && $this->getActions($list) !== [];
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

		if ($ids === [] || $bulkAction === null || $bulkAction instanceof SendsFileInterface) {
			return [];
		}

		App::maxPowerCaptain();

		$activity = $this->activity;
		$activity->beginBatch(ActivityAction::Bulk, ActivitySubject::fromList($list) ?? ActivitySubject::Element, $bulkAction->definition()['label']);

		try {
			return $bulkAction->apply($ids, $params);
		} finally {
			$activity->endBatch();
		}
	}

	/**
	 * The action of a list that sends a file, if the user may use it and it does.
	 */
	public function getFileAction(string $list, string $action): ?SendsFileInterface
	{
		$bulkAction = $this->available($list)[$action] ?? null;

		return $bulkAction instanceof SendsFileInterface ? $bulkAction : null;
	}

	/**
	 * Preview of deleting the given transforms of a file, which its own page picks, rather than all its transforms.
	 *
	 * @param int[] $ids IDs of the files
	 * @param int[] $transformIds
	 */
	public function previewTransforms(array $ids, int $page, array $transformIds): BulkPreview
	{
		return (new DeleteTransforms(ListType::Files->value, $this->access, $this->bulk, $this->files, $this->craftElements))->previewOf($ids, $page, $transformIds);
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
}
