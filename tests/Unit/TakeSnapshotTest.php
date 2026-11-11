<?php

declare(strict_types=1);

use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\jobs\TakeSnapshotJob;

it('takes the snapshot of today', function (): void {
	$job = new TakeSnapshotJob();

	$job->execute(Craft::$app->getQueue());

	expect($job->getDescription())->toBe('Taking a snapshot of the statistics')
		->and($this->db()->queriesMatching(sqlInsertInto(Tables::SNAPSHOTS)))->toHaveCount(1);
});
