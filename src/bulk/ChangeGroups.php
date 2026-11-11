<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Closure;
use Craft;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\models\UserGroup;
use craft\services\Elements;
use craft\services\Users;
use craft\web\User as WebUser;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\UserService;

/**
 * Adds users to and removes them from the groups picked, by the groups' IDs (`groups`, see BulkActions::GROUP_*), as
 * far as the user may assign those (see UserService::getAssignableGroups()) and save the users, leaving out themselves.
 * Users already in the groups they're added to and not in those they're removed from are left as they are.
 *
 * @internal
 */
final class ChangeGroups extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly UserService $users,
		private readonly Elements $craftElements,
		private readonly Users $craftUsers,
		private readonly Closure $edition,
		private readonly ?WebUser $user,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return $this->picked(
			Craft::t('hub', 'Change groups'),
			'users',
			Craft::t('hub', 'Change the groups of “{name}”?'),
			Craft::t('hub', 'Change the groups of {count} selected users?'),
			Craft::t('hub', 'Checking which groups can be changed…'),
		);
	}

	public function isAvailable(): bool
	{
		return $this->access->canAct(Page::Users, Permission::AssignUserGroups->value) && ($this->edition)() !== CmsEdition::Solo;
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		[$add, $remove] = $this->changes($params);

		if ($add === [] && $remove === []) {
			return [];
		}

		$service = $this->craftUsers;

		return $this->succeeded($this->users($ids), function (User $user) use ($service, $add, $remove): bool {
			$groupIds = array_map(fn(UserGroup $group): int => (int) $group->id, $user->getGroups());
			$changed = array_values(array_diff(array_unique([...$groupIds, ...$add]), $remove));
			sort($groupIds);
			sort($changed);

			return $changed === $groupIds || $service->assignUserToGroups((int) $user->id, $changed);
		});
	}

	/**
	 * IDs of the groups to add users to and to remove them from, among those the user may assign.
	 *
	 * @param array<string,mixed> $params
	 * @return array{0:list<int>,1:list<int>}
	 */
	private function changes(array $params): array
	{
		$assignable = $this->users->getAssignableGroups();
		$picked = is_array($params['groups'] ?? null) ? $params['groups'] : [];
		$add = [];
		$remove = [];

		foreach ($picked as $id => $change) {
			if (isset($assignable[(int) $id]) && in_array($change, [BulkActions::GROUP_ADD, BulkActions::GROUP_REMOVE], true)) {
				$change === BulkActions::GROUP_ADD ? $add[] = (int) $id : $remove[] = (int) $id;
			}
		}

		return [$add, $remove];
	}

	public function preview(array $ids, int $page): BulkPreview
	{
		$users = $this->users($ids);
		$groups = $this->users->getAssignableGroups();

		return new BulkPreview('groups-preview', $groups !== [] ? count($users) : 0, [
			'groups' => $groups,
			'counts' => $this->users->getGroupCounts(array_map(fn(User $user): int => (int) $user->id, $users)),
		]);
	}

	/**
	 * Users with the IDs whose groups the current user may change: others they may save.
	 *
	 * @param int[] $ids
	 * @return list<User>
	 */
	private function users(array $ids): array
	{
		$current = $this->user?->getIdentity();
		$service = $this->craftElements;

		return array_values(array_filter(
			$this->bulk->getElementsOf($this->list, $ids, User::class),
			fn(User $user): bool => $current !== null && $user->id !== $current->id && $service->canSave($user),
		));
	}
}
