<?php

declare(strict_types=1);

use craft\db\QueryBatcher;
use SomehowDigital\Craft\Hub\jobs\IndexReferencesJob;

it('indexes the references of the elements a batch at a time', function (): void {
	$job = new IndexReferencesJob();

	(function (): void {
		expect($this->loadData())->toBeInstanceOf(QueryBatcher::class)
			->and($this->defaultDescription())->toBe('Indexing references in content');

		foreach ([[['id' => '3'], ['id' => '4']], [['id' => '5']]] as $batch) {
			$this->beforeBatch();
			array_map($this->processItem(...), $batch);
			$this->afterBatch();
		}
	})->call($job);

	$lookups = $this->db()->queriesMatching('FROM `elements_sites`');

	expect($lookups)->toHaveCount(2)
		->and($lookups[0])->toContain('`elements_sites`.`elementId` IN (3, 4)')
		->and($lookups[1])->toContain('`elements_sites`.`elementId`=5');
});
