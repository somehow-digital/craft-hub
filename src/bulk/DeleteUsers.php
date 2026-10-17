<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Deletes users as far as the user may delete them (see UserService::getDeletableUsers()), listing them along with the entries
 * they're an author of, transferring their content to the user picked (`inheritorId`), as Craft does, who isn't deleted then.
 */
class DeleteUsers extends BulkAction
{
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
		return $this->hub()->access->canAct(Page::Users, Hub::PERMISSION_DELETE_USERS) && Craft::$app->getUser()->getIdentity()?->can('deleteUsers') === true;
	}

	public function apply(array $ids, array $params): array
	{
		$inheritorId = $this->intParam($params, 'inheritorId');
		$inheritor = $inheritorId > 0 ? User::find()->id($inheritorId)->status(User::STATUS_ACTIVE)->one() : null;

		if ($inheritorId > 0 && $inheritor === null) {
			return [];
		}

		$elements = Craft::$app->getElements();
		$users = $this->hub()->bulk->getElementsOf($this->list, $ids, User::class);

		return $this->succeeded($this->hub()->users->getDeletableUsers(array_map(fn (User $user) => (int)$user->id, $users)), function (User $user) use ($elements, $inheritor) {
			if ($inheritor !== null && $user->id === $inheritor->id) {
				return false;
			}

			$user->inheritorOnDelete = $inheritor;

			return $elements->deleteElement($user);
		});
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		$hubUsers = $this->hub()->users;
		$users = $hubUsers->getDeletableUsers($ids);
		$userIds = array_map(fn (User $user) => (int)$user->id, $users);
		$counts = $hubUsers->getEntryCounts($userIds);
		[$pageInfo, $shown] = (new Paginator($page))->items($users);

		return new BulkPreview('user-deletion-preview', count($users), [
			'items' => array_map(fn (User $user) => ['element' => $user, 'meta' => $user->email, 'count' => $counts[$user->id] ?? 0], $shown),
			'pageInfo' => $pageInfo,
			'entries' => array_sum($counts),
			'inheritors' => $hubUsers->getInheritors($userIds),
		]);
	}
}
