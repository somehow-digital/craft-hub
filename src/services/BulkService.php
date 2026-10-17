<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\Hub;
use yii\base\Component;

/**
 * Actions applied to the items selected in a list, by the list's name (see ColumnService::LIST_*).
 * Entries and files are changed as far as the user may change each of them; users as far as they may suspend each.
 * Sections, entry types, fields, volumes and user groups are deleted like on Craft's settings pages, so only by admins
 * where admin changes are allowed.
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

	/**
	 * Actions offered for the selected items of a list, in the order of its menu; destructive ones ask for confirmation.
	 *
	 * @return array<string,array{label:string,icon:string,confirm?:string}>
	 */
	public function getActions(string $list): array
	{
		$user = Craft::$app->getUser()->getIdentity();

		if ($user === null) {
			return [];
		}

		$settings = $user->admin && Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
		$delete = fn (string $confirm) => [self::ACTION_DELETE => ['label' => Craft::t('app', 'Delete'), 'icon' => 'trash', 'confirm' => $confirm]];

		return match ($list) {
			ColumnService::LIST_ENTRIES => [
				self::ACTION_ENABLE => ['label' => Craft::t('app', 'Enable'), 'icon' => 'toggle-on'],
				self::ACTION_DISABLE => ['label' => Craft::t('app', 'Disable'), 'icon' => 'toggle-off'],
				...$delete(Craft::t('hub', 'Are you sure you want to delete the selected entries?')),
			],
			ColumnService::LIST_FILES => [
				self::ACTION_DOWNLOAD => ['label' => Craft::t('app', 'Download'), 'icon' => 'download'],
				self::ACTION_DELETE_TRANSFORMS => [
					'label' => Craft::t('hub', 'Delete transforms'),
					'icon' => 'crop',
					'confirm' => Craft::t('hub', 'Are you sure you want to delete the transforms of the selected files? They are generated again when they are next requested.'),
				],
				...$delete(Craft::t('hub', 'Are you sure you want to delete the selected files?')),
			],
			ColumnService::LIST_USERS => $user->can('moderateUsers') ? [
				self::ACTION_SUSPEND => ['label' => Craft::t('app', 'Suspend'), 'icon' => 'user-lock', 'confirm' => Craft::t('hub', 'Are you sure you want to suspend the selected users?')],
				self::ACTION_UNSUSPEND => ['label' => Craft::t('app', 'Unsuspend'), 'icon' => 'user-check'],
			] : [],
			ColumnService::LIST_SECTIONS => $settings ? $delete(Craft::t('hub', 'Are you sure you want to delete the selected sections and all of their entries?')) : [],
			ColumnService::LIST_ENTRY_TYPES => $settings ? $delete(Craft::t('hub', 'Are you sure you want to delete the selected entry types and all entries of them?')) : [],
			ColumnService::LIST_FIELDS => $settings ? $delete(Craft::t('hub', 'Are you sure you want to delete the selected fields and their content?')) : [],
			ColumnService::LIST_VOLUMES => $settings ? $delete(Craft::t('hub', 'Are you sure you want to delete the selected volumes and all of their assets?')) : [],
			ColumnService::LIST_USER_GROUPS => $settings ? $delete(Craft::t('hub', 'Are you sure you want to delete the selected user groups? Their users are kept.')) : [],
			default => [],
		};
	}

	/**
	 * Applies an action to the items of a list with the IDs, skipping those the user may not change or that fail to save.
	 * Downloading changes nothing, so it's left to getDownloadableFiles().
	 *
	 * @param int[] $ids
	 * @return int number of items the action was applied to
	 */
	public function apply(string $list, string $action, array $ids): int
	{
		if ($ids === [] || $action === self::ACTION_DOWNLOAD || !isset($this->getActions($list)[$action])) {
			return 0;
		}

		return match ($list) {
			ColumnService::LIST_ENTRIES => $this->applyToElements(Entry::find()->id($ids)->site('*')->unique()->status(null)->all(), $action),
			ColumnService::LIST_FILES => $this->applyToFiles(Asset::find()->id($ids)->site('*')->unique()->all(), $action),
			ColumnService::LIST_USERS => $this->applyToUsers(User::find()->id($ids)->status(null)->all(), $action),
			ColumnService::LIST_SECTIONS => $this->count($ids, fn (int $id) => Craft::$app->getEntries()->deleteSectionById($id)),
			ColumnService::LIST_ENTRY_TYPES => $this->count($ids, fn (int $id) => Craft::$app->getEntries()->deleteEntryTypeById($id)),
			ColumnService::LIST_FIELDS => $this->count($ids, fn (int $id) => Craft::$app->getFields()->deleteFieldById($id)),
			ColumnService::LIST_VOLUMES => $this->count($ids, fn (int $id) => Craft::$app->getVolumes()->deleteVolumeById($id)),
			ColumnService::LIST_USER_GROUPS => $this->count($ids, fn (int $id) => Craft::$app->getUserGroups()->deleteGroupById($id)),
			default => 0,
		};
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
	 */
	private function applyToElements(array $elements, string $action): int
	{
		$service = Craft::$app->getElements();

		return $this->count($elements, function (ElementInterface $element) use ($service, $action) {
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
	 */
	private function applyToFiles(array $assets, string $action): int
	{
		if ($action !== self::ACTION_DELETE_TRANSFORMS) {
			return $this->applyToElements($assets, $action);
		}

		/** @var FileService $files */
		$files = Hub::getInstance()->files;
		$service = Craft::$app->getElements();
		$transforms = $files->getTransformsOf($assets);

		return $this->count($assets, fn (Asset $asset) => $service->canSave($asset)
			&& $files->deleteTransforms($asset, array_column($transforms[$asset->id] ?? [], 'id')) > 0);
	}

	/**
	 * Suspends or unsuspends users, leaving out the current one, so users can't lock themselves out.
	 *
	 * @param User[] $users
	 */
	private function applyToUsers(array $users, string $action): int
	{
		$service = Craft::$app->getUsers();
		$current = Craft::$app->getUser()->getIdentity();

		return $this->count($users, function (User $user) use ($service, $current, $action) {
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
	 * Number of items the callback succeeded for.
	 *
	 * @template T
	 * @param T[] $items
	 * @param callable(T):bool $callback
	 */
	private function count(array $items, callable $callback): int
	{
		return count(array_filter($items, fn (mixed $item) => $callback($item)));
	}
}
