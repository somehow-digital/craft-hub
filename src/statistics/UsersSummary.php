<?php

namespace SomehowDigital\Craft\Hub\statistics;

use Closure;
use Craft;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\models\UserStatistics;

/**
 * Statistics of the users list: its summary cards, linking to its filters.
 */
final class UsersSummary extends Summary
{
	/**
	 * Summary cards for the users list, linking to its filters.
	 *
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function index(): array
	{
		$users = Hub::getInstance()->users;
		$path = 'hub/data/users';
		$months = Hub::getInstance()->filters->getThreshold(CheckKind::Users, 'idle');

		$cards = [
			$this->lazyCard(Craft::t('app', 'Users'), 'user', fn () => $this->usersCard($users->getStatistics(), $path), $this->usersHistory(fn (UserStatistics $stats) => $this->usersCard($stats, $path))),
		];

		// Whether there are any groups is known without counting their users.
		if (Craft::$app->getUserGroups()->getAllGroups() !== []) {
			$cards[] = $this->lazyCard(Craft::t('app', 'User Groups'), 'users', function () use ($users, $path) {
				$counts = [];
				$handles = [];
				$cells = [];
				foreach ($users->getGroups() as $info) {
					$name = Craft::t('site', (string)$info['group']->name);
					$counts[$name] = $info['users'];
					$handles[$name] = (string)$info['group']->handle;
					$cells[$name] = [$info['permissions'], $info['requires2fa'] ? Craft::t('app', 'Yes') : Craft::t('app', 'No')];
				}

				return $this->withColumns(
					$this->topCard(Craft::t('app', 'User Groups'), 'users', $counts, 'group', path: $path, filterValues: $handles, sum: true),
					Craft::t('app', 'Users'),
					[
						['label' => Craft::t('app', 'Permissions'), 'type' => 'number'],
						['label' => Craft::t('hub', 'Two-step verification required'), 'type' => 'text'],
					],
					$cells,
				);
			}, fn (Snapshot $snapshot) => $this->labeledTopHistory(
				array_map(fn (array $info) => Craft::t('site', (string)$info['group']->name), $users->getGroups()),
				$snapshot->getUserGroups(),
				true,
			));
		}

		$cards[] = $this->lazyCard(Craft::t('hub', 'Sign-ins'), 'clock', fn () => $this->signInsCard($users->getStatistics(), $path, $months), $this->usersHistory(fn (UserStatistics $stats) => $this->signInsCard($stats, $path, $months)));

		$cards[] = $this->lazyCard(Craft::t('hub', 'Two-step verification'), 'shield-halved', fn () => $this->twoFactorCard($users->getStatistics(), $path), $this->usersHistory(fn (UserStatistics $stats) => $this->twoFactorCard($stats, $path)));

		return $cards;
	}

	/**
	 * Summary card for users, split by their status.
	 *
	 * @return array<string,mixed>
	 */
	private function usersCard(UserStatistics $stats, string $path): array
	{
		return [
			'label' => Craft::t('app', 'Users'),
			'icon' => 'user',
			'url' => null,
			'total' => $stats->total,
			'segments' => [
				['label' => Craft::t('app', 'Active'), 'value' => $stats->active, 'color' => 'green', 'url' => $this->hubUrl($path, ['status' => User::STATUS_ACTIVE])],
				['label' => Craft::t('app', 'Pending'), 'value' => $stats->pending, 'color' => 'orange', 'url' => $this->hubUrl($path, ['status' => User::STATUS_PENDING])],
				['label' => Craft::t('app', 'Suspended'), 'value' => $stats->suspended, 'color' => 'red', 'url' => $this->hubUrl($path, ['status' => User::STATUS_SUSPENDED])],
				['label' => Craft::t('app', 'Inactive'), 'value' => $stats->inactive, 'color' => 'gray', 'url' => $this->hubUrl($path, ['status' => User::STATUS_INACTIVE])],
				// Locked users are active too, so they are listed but kept out of the bar.
				['label' => Craft::t('app', 'Locked'), 'value' => $stats->locked, 'color' => 'red', 'bar' => false, 'url' => $this->hubUrl($path, ['status' => User::STATUS_LOCKED])],
			],
			'details' => [
				['label' => Craft::t('hub', 'Admins'), 'value' => $stats->admins, 'url' => $this->hubUrl($path, ['admin' => 1])],
				['label' => Craft::t('hub', 'Unverified email addresses'), 'value' => $stats->unverifiedEmail, 'url' => $this->hubUrl($path, ['unverified' => 1])],
			],
		];
	}

	/**
	 * Summary card for when active users last signed in.
	 *
	 * @return array<string,mixed>
	 */
	private function signInsCard(UserStatistics $stats, string $path, int $months): array
	{
		return [
			'label' => Craft::t('hub', 'Sign-ins'),
			'icon' => 'clock',
			'url' => null,
			'total' => $stats->active,
			'segments' => [
				['label' => Craft::t('hub', 'Recently'), 'value' => $stats->recentLogin, 'color' => 'green', 'url' => $this->hubUrl($path, ['login' => UserFilter::LOGIN_RECENT])],
				['label' => Craft::t('hub', 'Idle'), 'value' => $stats->idle, 'color' => 'orange', 'url' => $this->hubUrl($path, ['login' => UserFilter::LOGIN_IDLE])],
				['label' => Craft::t('hub', 'Not yet'), 'value' => $stats->getNew(), 'color' => 'gray'],
			],
			'note' => Craft::t('hub', 'Active users only · Idle: not signed in for {months, plural, =1{one month} other{# months}}', ['months' => $months]),
		];
	}

	/**
	 * Summary card for active users, split by whether they set up two-step verification.
	 *
	 * @return array<string,mixed>
	 */
	private function twoFactorCard(UserStatistics $stats, string $path): array
	{
		return [
			'label' => Craft::t('hub', 'Two-step verification'),
			'icon' => 'shield-halved',
			'url' => null,
			'total' => $stats->active,
			'segments' => [
				['label' => Craft::t('hub', 'Set up'), 'value' => $stats->twoFactor, 'color' => 'green', 'url' => $this->hubUrl($path, ['status' => User::STATUS_ACTIVE, 'twoFactor' => UserFilter::TWO_FACTOR_WITH])],
				['label' => Craft::t('hub', 'Not set up'), 'value' => $stats->active - $stats->twoFactor, 'color' => 'orange', 'url' => $this->hubUrl($path, ['status' => User::STATUS_ACTIVE, 'twoFactor' => UserFilter::TWO_FACTOR_WITHOUT])],
			],
			'note' => Craft::t('hub', 'Active users only'),
			'details' => [
				['label' => Craft::t('hub', 'Admins without two-step verification'), 'value' => $stats->adminsWithoutTwoFactor, 'url' => $this->hubUrl($path, ['admin' => 1, 'twoFactor' => UserFilter::TWO_FACTOR_WITHOUT])],
			],
		];
	}

	/**
	 * A card of the users in a snapshot, see lazyCard().
	 *
	 * @param Closure(UserStatistics):array<string,mixed> $card
	 * @return Closure(Snapshot):array<string,mixed>
	 */
	private function usersHistory(Closure $card): Closure
	{
		return fn (Snapshot $snapshot) => $card($snapshot->getUsers());
	}
}
