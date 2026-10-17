<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Suspends or unsuspends users, leaving out the current one, so users can't lock themselves out.
 */
class SetSuspended extends BulkAction
{
	public function __construct(string $list, private readonly bool $suspended)
	{
		parent::__construct($list);
	}

	public function definition(): array
	{
		return $this->suspended
			? ['label' => Craft::t('app', 'Suspend'), 'icon' => 'user-lock', 'unless' => 'suspended', 'confirm' => Craft::t('hub', 'Are you sure you want to suspend the selected users?')]
			: ['label' => Craft::t('app', 'Unsuspend'), 'icon' => 'user-check', 'unless' => 'active'];
	}

	public function isAvailable(): bool
	{
		return $this->hub()->access->canAct(Page::Users, Hub::PERMISSION_SUSPEND_USERS) && Craft::$app->getUser()->getIdentity()?->can('moderateUsers') === true;
	}

	public function apply(array $ids, array $params): array
	{
		$service = Craft::$app->getUsers();
		$current = Craft::$app->getUser()->getIdentity();

		return $this->succeeded($this->hub()->bulk->getElementsOf($this->list, $ids, User::class), function (User $user) use ($service, $current) {
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
