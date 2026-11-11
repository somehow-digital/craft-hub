<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\User;
use craft\services\Elements;
use craft\services\Entries;
use craft\web\User as WebUser;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\models\Paginator;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\UserService;

/**
 * Deletes users as far as the user may delete them (see UserService::getDeletableUsers()), listing them along with the
 * entries they're an author of, transferring their content to the user picked (`inheritorId`), as Craft does, who isn't
 * deleted then.
 *
 * @internal
 */
final class DeleteUsers extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly UserService $users,
		private readonly Elements $craftElements,
		private readonly Entries $craftEntries,
		private readonly ?WebUser $user,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return [
			'label' => Craft::t('app', 'Delete'),
			'icon' => 'user-xmark',
			'confirm' => Craft::t('hub', 'Are you sure you want to delete the selected users?'),
			'preview' => true,
			'titles' => [
				'one' => Craft::t('hub', 'Delete “{name}”?'),
				'some' => Craft::t('hub', 'Delete {count} selected users?'),
			],
			'loading' => Craft::t('hub', 'Checking which users are deleted…'),
		];
	}

	public function isAvailable(): bool
	{
		return $this->access->canAct(Page::Users, Permission::DeleteUsers->value) && $this->user?->getIdentity()?->can('deleteUsers') === true;
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$inheritorId = $this->intParam($params, 'inheritorId');
		$inheritor = $this->activeUser($inheritorId);

		if ($inheritorId > 0 && !$inheritor instanceof User) {
			return [];
		}

		$elements = $this->craftElements;
		$users = $this->bulk->getElementsOf($this->list, $ids, User::class);

		return $this->succeeded($this->users->getDeletableUsers(array_map(fn(User $user): int => (int) $user->id, $users)), function (User $user) use ($elements, $inheritor): bool {
			if ($inheritor instanceof User && $user->id === $inheritor->id) {
				return false;
			}

			// Reassigned before deleting, as the property Craft does it by is deprecated.
			if ($inheritor instanceof User) {
				$this->craftEntries->reassignEntries((int) $user->id, (int) $inheritor->id);
			}

			return $elements->deleteElement($user);
		});
	}

	public function preview(array $ids, int $page): BulkPreview
	{
		$hubUsers = $this->users;
		$users = $hubUsers->getDeletableUsers($ids);
		$userIds = array_map(fn(User $user): int => (int) $user->id, $users);
		$counts = $hubUsers->getEntryCounts($userIds);
		[$pageInfo, $shown] = (new Paginator($page))->items($users);

		return new BulkPreview('user-deletion-preview', count($users), [
			'items' => array_map(fn(User $user): array => ['element' => $user, 'meta' => $user->email, 'count' => $counts[(int) $user->id] ?? 0], $shown),
			'pageInfo' => $pageInfo,
			'entries' => array_sum($counts),
			'inheritors' => $hubUsers->getInheritors($userIds),
		]);
	}
}
