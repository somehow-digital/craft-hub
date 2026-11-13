<?php

declare(strict_types=1);

use craft\queue\Queue;
use Mockery\MockInterface;
use SomehowDigital\Craft\Hub\console\controllers\ReferencesController;
use SomehowDigital\Craft\Hub\console\controllers\SnapshotsController;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\jobs\IndexReferencesJob;
use SomehowDigital\Craft\Hub\jobs\TakeSnapshotJob;
use yii\console\ExitCode;

/**
 * Console controllers without the output they write.
 *
 * @template T of ReferencesController|SnapshotsController
 * @param class-string<T> $class
 * @return T
 */
function quietly(string $class): ReferencesController|SnapshotsController
{
	/** @var T&MockInterface $controller */
	$controller = Mockery::mock("{$class}[stdout]", ['hub', Craft::$app]);
	$controller->shouldReceive('stdout')->andReturn(0);

	return $controller;
}

function fakeQueue(): MockInterface
{
	$queue = Mockery::mock(Queue::class);
	$queue->shouldReceive('attachBehavior', 'priority', 'delay', 'ttr')->andReturnSelf();
	Craft::$app->set('queue', $queue);

	return $queue;
}

it('queues indexing the references', function (): void {
	fakeQueue()->shouldReceive('push')->once()->with(Mockery::type(IndexReferencesJob::class));

	$controller = quietly(ReferencesController::class);
	$controller->queue = true;

	expect($controller->actionIndex())->toBe(ExitCode::OK);
});

it('queues taking the snapshot', function (): void {
	fakeQueue()->shouldReceive('push')->once()->with(Mockery::type(TakeSnapshotJob::class));

	$controller = quietly(SnapshotsController::class);
	$controller->queue = true;

	expect($controller->actionIndex())->toBe(ExitCode::OK);
});

it('takes the snapshot of today, forced or not', function (bool $force, bool $existing, int $taken): void {
	$this->db()->respond('FROM {{%hub_snapshots}}', $existing ? [['id' => '3']] : []);

	$controller = quietly(SnapshotsController::class);
	$controller->force = $force;

	expect($controller->actionIndex())->toBe(ExitCode::OK)
		->and($this->db()->queriesMatching(sqlInsertInto(Tables::SNAPSHOTS)))->toHaveCount($taken);
})->with([
	'none yet' => [false, false, 1],
	'taken already' => [false, true, 0],
	'replaced' => [true, true, 1],
]);

it('offers its options', function (): void {
	expect(quietly(ReferencesController::class)->options('index'))->toContain('queue')
		->and(quietly(SnapshotsController::class)->options('index'))->toContain('force', 'queue');
});
