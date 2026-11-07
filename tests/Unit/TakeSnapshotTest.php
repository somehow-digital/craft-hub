<?php

declare(strict_types=1);

use SomehowDigital\Craft\Hub\jobs\TakeSnapshot;
use SomehowDigital\Craft\Hub\services\SnapshotService;

it('takes the snapshot of today', function (): void {
	$this->mockComponent('hub.snapshots', SnapshotService::class)->shouldReceive('take')->once()->withNoArgs();
	$job = new TakeSnapshot();

	$job->execute(Craft::$app->getQueue());

	expect($job->getDescription())->toBe('Taking a snapshot of the statistics');
});
