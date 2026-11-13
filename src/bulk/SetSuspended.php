<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\User;
use craft\services\Users;
use craft\web\User as WebUser;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;

/**
 * Suspends or unsuspends users, leaving out the current one, so users can't lock themselves out.
 *
 * @internal
 */
final class SetSuspended extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly Users $craftUsers,
		private readonly ?WebUser $user,
		private readonly bool $suspended,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return $this->suspended
			? ['label' => Craft::t('app', 'Suspend'), 'icon' => 'user-lock', 'unless' => 'suspended', 'confirm' => Craft::t('hub', 'Are you sure you want to suspend the selected users?')]
			: ['label' => Craft::t('app', 'Unsuspend'), 'icon' => 'user-check', 'unless' => 'active'];
	}

	public function isAvailable(): bool
	{
		return $this->access->canAct(Page::Users, Permission::SuspendUsers->value) && $this->user?->getIdentity()?->can('moderateUsers') === true;
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$service = $this->craftUsers;
		$current = $this->user?->getIdentity();

		return $this->succeeded($this->bulk->getElementsOf($this->list, $ids, User::class), function (User $user) use ($service, $current): bool {
			if ($current === null || $user->id === $current->id || !$service->canSuspend($current, $user)) {
				return false;
			}

			if ($this->suspended && !$user->suspended) {
				$service->suspendUser($user);
			} elseif (!$this->suspended && $user->suspended) {
				$service->unsuspendUser($user);
			}

			return true;
		});
	}
}
