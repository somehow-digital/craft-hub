<?php

use craft\queue\Queue;
use SomehowDigital\Craft\Hub\console\controllers\ReferencesController;
use SomehowDigital\Craft\Hub\console\controllers\SnapshotsController;
use SomehowDigital\Craft\Hub\jobs\IndexReferences;
use SomehowDigital\Craft\Hub\jobs\TakeSnapshot;
use SomehowDigital\Craft\Hub\services\SnapshotService;
use yii\console\ExitCode;

/**
 * Console controllers without the output they write.
 */
function quietly(string $class): object
{
	$controller = Mockery::mock("{$class}[stdout]", ['hub', Craft::$app]);
	$controller->shouldReceive('stdout')->andReturn(0);

	return $controller;
}

function fakeQueue(): Mockery\MockInterface
{
	$queue = Mockery::mock(Queue::class);
	$queue->shouldReceive('attachBehavior', 'priority', 'delay', 'ttr')->andReturnSelf();
	Craft::$app->set('queue', $queue);

	return $queue;
}

it('queues indexing the references', function () {
	fakeQueue()->shouldReceive('push')->once()->with(Mockery::type(IndexReferences::class));

	$controller = quietly(ReferencesController::class);
	$controller->queue = true;

	expect($controller->actionIndex())->toBe(ExitCode::OK);
});

it('queues taking the snapshot', function () {
	fakeQueue()->shouldReceive('push')->once()->with(Mockery::type(TakeSnapshot::class));

	$controller = quietly(SnapshotsController::class);
	$controller->queue = true;

	expect($controller->actionIndex())->toBe(ExitCode::OK);
});

it('takes the snapshot of today, forced or not', function (bool $force, bool $taken) {
	$snapshots = Mockery::mock(SnapshotService::class);
	$snapshots->shouldReceive('take')->once()->with($force)->andReturn($taken);
	$this->hub()->set('snapshots', $snapshots);

	$controller = quietly(SnapshotsController::class);
	$controller->force = $force;

	expect($controller->actionIndex())->toBe(ExitCode::OK);
})->with([[false, true], [false, false], [true, true]]);

it('offers its options', function () {
	expect(quietly(ReferencesController::class)->options('index'))->toContain('queue')
		->and(quietly(SnapshotsController::class)->options('index'))->toContain('force', 'queue');
});
