<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\App;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\models\UserGroupFilter;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use yii\base\Component;

/**
 * Actions applied to the items selected in a list, by the list's name (see ColumnService::LIST_*).
 * Each action needs a permission of the list's page (see Page::actionPermissions()), and items are changed as far as
 * Craft lets the user change each of them: entries and files as far as they may save or delete each, users as far as
 * they may suspend each. Sections, entry types, fields, volumes and user groups, the structure, are never deleted from
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

	/** most items selected at once, as the actions are applied to all of them within a single request */
	public const SELECTION_LIMIT = 1000;

	/**
	 * Actions offered for the selected items of a list, in the order of its menu; destructive ones ask for confirmation.
	 * Deleting (`preview`) shows what is deleted along with the items first, see DeletionService::getPreview(), and asks
	 * for confirmation (`confirm`) only if the preview can't be loaded; other actions ask for it right away.
	 * Labeling (`labels`) picks the labels to add and remove first, and keeps the items selected (`keep`).
	 * Downloading (`file`) sends the files, so it's submitted rather than applied in place from the context menu of an item;
	 * it shows the files sent first (`preview`), see BulkController::actionPreview(), as deleting the transforms of files
	 * shows the transforms deleted. The dialog of a preview is titled after the action, or by its own `titles`, naming the
	 * item (`one`) or the number of items (`some`).
	 * The context menu of an item leaves out the actions that wouldn't change it, as it's already in their state (`unless`,
	 * see the `bulkItem` macro).
	 * Labeling needs the permission to label the items of the list's page, the other actions a permission of their own,
	 * and all of them the permission to view the page.
	 *
	 * @return array<string,array{label:string,icon:string,confirm?:string,preview?:bool,titles?:array{one:string,some:string},labels?:bool,keep?:bool,file?:bool,unless?:string}>
	 */
	public function getActions(string $list): array
	{
		$hub = Hub::getInstance();
		$user = Craft::$app->getUser()->getIdentity();
		$page = Page::fromList($list);

		if ($user === null || $page === null || !$hub->canView($page)) {
			return [];
		}

		$delete = fn (string $confirm) => [self::ACTION_DELETE => ['label' => Craft::t('app', 'Delete'), 'icon' => 'trash', 'confirm' => $confirm, 'preview' => true]];
		/** @var LabelService $labels */
		$labels = Hub::getInstance()->labels;
		$label = $labels->canApply($list) && ($labels->getAllLabels() !== [] || $labels->canManage())
			? [self::ACTION_LABELS => ['label' => Craft::t('hub', 'Labels'), 'icon' => 'tags', 'labels' => true, 'keep' => true]]
			: [];

		// The actions of each permission, in the order of the menu.
		$can = fn (string $permission) => $hub->canAct($page, $permission);

		return [...$label, ...match ($list) {
			ColumnService::LIST_ENTRIES => [
				...($can(Hub::PERMISSION_ENABLE_ENTRIES) ? [
					self::ACTION_ENABLE => ['label' => Craft::t('app', 'Enable'), 'icon' => 'toggle-on', 'unless' => 'enabled'],
					self::ACTION_DISABLE => ['label' => Craft::t('app', 'Disable'), 'icon' => 'toggle-off', 'unless' => 'disabled'],
				] : []),
				...($can(Hub::PERMISSION_DELETE_ENTRIES) ? $delete(Craft::t('hub', 'Are you sure you want to delete the selected entries?')) : []),
			],
			ColumnService::LIST_FILES => [
				...($can(Hub::PERMISSION_DOWNLOAD_ASSETS) ? [
					self::ACTION_DOWNLOAD => ['label' => Craft::t('app', 'Download'), 'icon' => 'download', 'file' => true, 'preview' => true],
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
			ColumnService::LIST_USERS => $can(Hub::PERMISSION_SUSPEND_USERS) && $user->can('moderateUsers') ? [
				self::ACTION_SUSPEND => ['label' => Craft::t('app', 'Suspend'), 'icon' => 'user-lock', 'unless' => 'suspended', 'confirm' => Craft::t('hub', 'Are you sure you want to suspend the selected users?')],
				self::ACTION_UNSUSPEND => ['label' => Craft::t('app', 'Unsuspend'), 'icon' => 'user-check', 'unless' => 'active'],
			] : [],
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
	 * Downloading changes nothing, so it's left to getDownloadableFiles(), and labeling is left to applyLabels(). What the
	 * action changed is recorded in the activity log as a batch, named like the action.
	 *
	 * @param int[] $ids
	 * @return int[] IDs of the items the action was applied to
	 */
	public function apply(string $list, string $action, array $ids): array
	{
		if ($ids === [] || in_array($action, [self::ACTION_DOWNLOAD, self::ACTION_LABELS], true) || !isset($this->getActions($list)[$action])) {
			return [];
		}

		App::maxPowerCaptain();

		/** @var ActivityService $activity */
		$activity = Hub::getInstance()->activity;
		$activity->beginBatch(ActivityAction::Bulk, ActivitySubject::fromList($list) ?? ActivitySubject::Element, $this->getActions($list)[$action]['label']);

		try {
			return $this->applyTo($list, $action, $ids);
		} finally {
			$activity->endBatch();
		}
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function applyTo(string $list, string $action, array $ids): array
	{
		return match ($list) {
			ColumnService::LIST_ENTRIES => $this->applyToElements(Entry::find()->id($ids)->site('*')->unique()->status(null)->all(), $action),
			ColumnService::LIST_FILES => $this->applyToFiles(Asset::find()->id($ids)->site('*')->unique()->all(), $action),
			ColumnService::LIST_USERS => $this->applyToUsers(User::find()->id($ids)->status(null)->all(), $action),
			default => [],
		};
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
	 * Deletes files, or their generated transforms, which Craft generates again when they're next requested.
	 *
	 * @param Asset[] $assets
	 * @return int[]
	 */
	private function applyToFiles(array $assets, string $action): array
	{
		if ($action !== self::ACTION_DELETE_TRANSFORMS) {
			return $this->applyToElements($assets, $action);
		}

		/** @var FileService $files */
		$files = Hub::getInstance()->files;
		$service = Craft::$app->getElements();
		$transforms = $files->getTransformsOf($assets);

		return $this->succeeded($assets, fn (Asset $asset) => $service->canSave($asset)
			&& $files->deleteTransforms($asset, array_column($transforms[$asset->id] ?? [], 'id')) > 0);
	}

	/**
	 * Suspends or unsuspends users, leaving out the current one, so users can't lock themselves out.
	 *
	 * @param User[] $users
	 * @return int[]
	 */
	private function applyToUsers(array $users, string $action): array
	{
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
