<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\models\UserGroup;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\BulkService;

/**
 * Adds users to and removes them from the groups picked, by the groups' IDs (`groups`, see BulkService::GROUP_*), as far as the
 * user may assign those (see UserService::getAssignableGroups()) and save the users, leaving out themselves. Users already in
 * the groups they're added to and not in those they're removed from are left as they are.
 */
class ChangeGroups extends BulkAction
{
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
		return $this->hub()->access->canAct(Page::Users, Hub::PERMISSION_ASSIGN_USER_GROUPS) && Craft::$app->edition !== CmsEdition::Solo;
	}

	public function apply(array $ids, array $params): array
	{
		$assignable = $this->hub()->users->getAssignableGroups();
		$picked = is_array($params['groups'] ?? null) ? $params['groups'] : [];
		$add = [];
		$remove = [];

		foreach ($picked as $id => $change) {
			if (isset($assignable[(int)$id]) && in_array($change, [BulkService::GROUP_ADD, BulkService::GROUP_REMOVE], true)) {
				$change === BulkService::GROUP_ADD ? $add[] = (int)$id : $remove[] = (int)$id;
			}
		}

		if ($add === [] && $remove === []) {
			return [];
		}

		$service = Craft::$app->getUsers();

		return $this->succeeded($this->users($ids), function (User $user) use ($service, $add, $remove) {
			$groupIds = array_map(fn (UserGroup $group) => (int)$group->id, $user->getGroups());
			$changed = array_values(array_diff(array_unique([...$groupIds, ...$add]), $remove));
			sort($groupIds);
			sort($changed);

			return $changed === $groupIds || $service->assignUserToGroups((int)$user->id, $changed);
		});
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		$users = $this->users($ids);
		$groups = $this->hub()->users->getAssignableGroups();

		return new BulkPreview('groups-preview', $groups !== [] ? count($users) : 0, [
			'groups' => $groups,
			'counts' => $this->hub()->users->getGroupCounts(array_map(fn (User $user) => (int)$user->id, $users)),
		]);
	}

	/**
	 * Users with the IDs whose groups the current user may change: others they may save.
	 *
	 * @param int[] $ids
	 * @return User[]
	 */
	private function users(array $ids): array
	{
		$current = Craft::$app->getUser()->getIdentity();
		$service = Craft::$app->getElements();

		return array_values(array_filter(
			$this->hub()->bulk->getElementsOf($this->list, $ids, User::class),
			fn (User $user) => $current !== null && $user->id !== $current->id && $service->canSave($user),
		));
	}
}
