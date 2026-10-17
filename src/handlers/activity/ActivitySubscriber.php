<?php

namespace SomehowDigital\Craft\Hub\handlers\activity;

use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ActivityService;

/**
 * Records one kind of what users do in the activity log, as Craft's events tell it.
 */
abstract class ActivitySubscriber
{
	abstract public function register(): void;

	/**
	 * @param string[]|null $before permissions before, unless unknown
	 * @param string[] $after
	 */
	protected function permissionsChanged(ActivitySubject $subject, ?int $id, ?string $title, ?array $before, array $after): void
	{
		$granted = $before !== null ? array_values(array_diff($after, $before)) : $after;
		$revoked = $before !== null ? array_values(array_diff($before, $after)) : [];

		if ($granted !== [] || $revoked !== []) {
			$this->activity()->record(ActivityAction::PermissionsChanged, $subject, $id, $title, array_filter(['granted' => $granted, 'revoked' => $revoked]));
		}
	}

	protected function activity(): ActivityService
	{
		return Hub::getInstance()->activity;
	}
}
