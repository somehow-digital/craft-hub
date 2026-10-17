<?php

use SomehowDigital\Craft\Hub\controllers\OverviewController;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\services\ReviewService;

beforeEach(function () {
	$this->createContentModel();

	$this->review = Mockery::mock(ReviewService::class)->makePartial();
	$this->review->shouldReceive('countUnreferenced')->andReturn(0)->byDefault();
	$this->review->shouldReceive('countStale')->andReturn(0)->byDefault();
	$this->review->shouldReceive('countLargeFiles')->andReturn(0)->byDefault();
	$this->review->shouldReceive('countLargeImages')->andReturn(0)->byDefault();
	$this->hub()->set('review', $this->review);
});

it('shows the diagnostics of sections, entry types, fields and volumes', function () {
	$response = $this->runAction(OverviewController::class, 'index');
	[$sections, $entryTypes, $fields, $volumes] = $response->variables['diagnostics'];

	expect($response->template)->toBe('hub/pages/overview')
		->and($sections['total'])->toBe(4)
		->and(array_column($sections['segments'], 'value', 'label'))->toBe(['Clean up' => 0, 'Needs attention' => 1, 'Worth a look' => 2, 'Passed' => 1])
		->and($sections['segments'][1]['url'])->toContain('diagnosis=warning')
		->and($sections['note'])->toBe('Most common issue: Entries (1)')
		->and($entryTypes['total'])->toBe(5)
		->and($entryTypes['segments'][0]['value'])->toBe(1)
		->and($fields['total'])->toBe(9)
		->and($volumes['total'])->toBe(1)
		->and($volumes['url'])->toContain('hub/assets/volumes')
		->and($response->variables['reviewCount'])->toBe(array_sum(array_map(fn (array $card) => $card['total'] - $card['passed'], $response->variables['diagnostics'])));
});

it('notes when all checks pass', function () {
	$this->sections = [];
	$this->entryTypes = [];
	$this->fields = [];
	$this->volumes = [];
	$this->layouts = [];

	$diagnostics = $this->runAction(OverviewController::class, 'index')->variables['diagnostics'];

	expect($diagnostics[0]['note'])->toBe('All checks passed')
		->and($diagnostics[0]['total'])->toBe(0);
});

it('lists the tasks, the ones to do first', function () {
	$this->review->shouldReceive('countUnreferenced')->andReturn(2);
	$this->review->shouldReceive('countLargeImages')->andReturn(1);

	$variables = $this->runAction(OverviewController::class, 'index')->variables;
	[$entries, $assets, $structure] = $variables['tasks'];
	$values = fn (array $group) => array_column($group['items'], 'value', 'label');

	expect($values($entries))->toBe(['Unreferenced entries' => 2, 'Drafts' => 1, 'Disabled entries' => 1, 'Stale entries' => 0])
		->and($entries['items'][0]['severity'])->toBe(Severity::Warning)
		->and($values($assets))->toBe(['Oversized images' => 1, 'Assets with no relations' => 0, 'Missing alt text' => 0, 'Large files' => 0])
		->and($values($structure))->toBe([
			'Unused entry types' => 1,
			'Unused fields' => 3,
			'Empty sections' => 1,
			'Empty entry types' => 1,
			'Empty fields' => 1,
			'Empty volumes' => 1,
		])
		->and($variables['taskCount'])->toBe(10);
});

it('shows the inventory', function () {
	[$entries, $assets, $sections, $entryTypes, $fields, $volumes] = $this->runAction(OverviewController::class, 'index')->variables['inventory'];

	expect($entries['total'])->toBe(8)
		->and($entries['url'])->toContain('hub/entries/entries')
		->and($assets['url'])->toContain('hub/assets/files')
		->and($sections['segments'][1]['value'])->toBe(1)
		->and(array_column($entryTypes['segments'], 'value'))->toBe([3, 1, 1])
		->and(array_column($fields['segments'], 'value'))->toBe([5, 1, 3])
		->and($volumes['note'])->toBe('One with URLs');
});
