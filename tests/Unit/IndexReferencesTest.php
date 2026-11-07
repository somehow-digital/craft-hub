<?php

declare(strict_types=1);

use craft\db\Query;
use craft\db\QueryBatcher;
use SomehowDigital\Craft\Hub\jobs\IndexReferences;
use SomehowDigital\Craft\Hub\services\ReferenceService;

it('indexes the references of the elements a batch at a time', function (): void {
	$references = $this->mockComponent('hub.references', ReferenceService::class);
	$references->shouldReceive('indexableQuery')->andReturn(new Query());
	$references->shouldReceive('index')->once()->with([3, 4]);
	$references->shouldReceive('index')->once()->with([5]);
	$job = new IndexReferences();

	(function (): void {
		expect($this->loadData())->toBeInstanceOf(QueryBatcher::class)
			->and($this->defaultDescription())->toBe('Indexing references in content');

		foreach ([[['id' => '3'], ['id' => '4']], [['id' => '5']]] as $batch) {
			$this->beforeBatch();
			array_map($this->processItem(...), $batch);
			$this->afterBatch();
		}
	})->call($job);
});
