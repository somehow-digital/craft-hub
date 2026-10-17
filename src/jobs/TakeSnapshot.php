<?php

namespace SomehowDigital\Craft\Hub\jobs;

use Craft;
use craft\queue\BaseJob;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Takes the snapshot of the statistics of today, unless there is one already, see SnapshotService.
 */
class TakeSnapshot extends BaseJob
{
	public function execute($queue): void
	{
		Hub::getInstance()->snapshots->take();
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('hub', 'Taking a snapshot of the statistics');
	}
}
