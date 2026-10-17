<?php

use SomehowDigital\Craft\Hub\controllers\EntriesController;
use SomehowDigital\Craft\Hub\controllers\FieldsController;
use SomehowDigital\Craft\Hub\controllers\FilesController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\controllers\TypesController;
use SomehowDigital\Craft\Hub\controllers\VolumesController;
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

function taskValues(array $variables): array
{
	return array_column($variables['tasks'], 'value', 'label');
}

it('renders the diagnostics tab of each index page', function (string $class, string $area, string $page, string $title) {
	$response = $this->runAction($class, 'diagnostics');

	expect($response->template)->toBe('hub/pages/diagnostics')
		->and($response->variables['area'])->toBe($area)
		->and($response->variables['page'])->toBe($page)
		->and($response->variables['title'])->toBe($title);
})->with([
	[EntriesController::class, 'data', 'entries', 'Entries'],
	[FilesController::class, 'data', 'assets', 'Assets'],
	[TypesController::class, 'structure', 'entry-types', 'Entry Types'],
	[SectionsController::class, 'structure', 'sections', 'Sections'],
	[FieldsController::class, 'structure', 'fields', 'Fields'],
	[VolumesController::class, 'structure', 'volumes', 'Volumes'],
]);

it('lists the entries to do, the ones to do first', function () {
	$this->review->shouldReceive('countUnreferenced')->andReturn(2);

	$variables = $this->runAction(EntriesController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Unreferenced entries' => 2, 'Drafts' => 1, 'Disabled entries' => 1, 'Stale entries' => 0])
		->and($variables['tasks'][0]['severity'])->toBe(Severity::Warning)
		->and($variables['tasks'][1]['url'])->toContain('hub/data/entries')
		->and($variables['card'])->toBeNull();
});

it('lists the assets to do, the ones to do first', function () {
	$this->review->shouldReceive('countLargeImages')->andReturn(1);

	$variables = $this->runAction(FilesController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Oversized images' => 1, 'Assets with no references' => 0, 'Missing alt text' => 0, 'Large files' => 0])
		->and($variables['tasks'][0]['url'])->toContain('hub/data/assets')
		->and($variables['card'])->toBeNull();
});

it('summarizes the checks of the sections', function () {
	$variables = $this->runAction(SectionsController::class, 'diagnostics')->variables;
	$card = $variables['card'];

	expect($card['total'])->toBe(4)
		->and(array_column($card['segments'], 'value', 'label'))->toBe(['Clean up' => 0, 'Needs attention' => 1, 'Worth a look' => 2, 'Passed' => 1])
		->and($card['segments'][1]['url'])->toContain('hub/structure/sections')->toContain('diagnosis=warning')
		->and($card['note'])->toBe('Most common issue: Entries (1)')
		->and(taskValues($variables))->toBe(['Empty sections' => 1]);
});

it('summarizes the checks of the entry types', function () {
	$variables = $this->runAction(TypesController::class, 'diagnostics')->variables;

	expect($variables['card']['total'])->toBe(5)
		->and($variables['card']['segments'][0]['value'])->toBe(1)
		->and(taskValues($variables))->toBe(['Unused entry types' => 1, 'Empty entry types' => 1]);
});

it('summarizes the checks of the fields', function () {
	$variables = $this->runAction(FieldsController::class, 'diagnostics')->variables;

	expect($variables['card']['total'])->toBe(9)
		->and(taskValues($variables))->toBe(['Unused fields' => 3, 'Empty fields' => 1]);
});

it('summarizes the checks of the volumes', function () {
	$variables = $this->runAction(VolumesController::class, 'diagnostics')->variables;

	expect($variables['card']['total'])->toBe(1)
		->and($variables['card']['url'])->toContain('hub/structure/volumes')
		->and(taskValues($variables))->toBe(['Empty volumes' => 1]);
});

it('notes when all checks pass', function () {
	$this->sections = [];

	$card = $this->runAction(SectionsController::class, 'diagnostics')->variables['card'];

	expect($card['note'])->toBe('All checks passed')
		->and($card['total'])->toBe(0);
});
