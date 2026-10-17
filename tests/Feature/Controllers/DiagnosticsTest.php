<?php

use SomehowDigital\Craft\Hub\controllers\EntriesController;
use SomehowDigital\Craft\Hub\controllers\FieldsController;
use SomehowDigital\Craft\Hub\controllers\FilesController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\controllers\TypesController;
use SomehowDigital\Craft\Hub\controllers\UsersController;
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
	[UsersController::class, 'data', 'users', 'Users'],
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
		->and($variables['tasks'][1]['url'])->toContain('hub/data/entries');
});

it('lists the assets to do, the ones to do first', function () {
	$this->review->shouldReceive('countLargeImages')->andReturn(1);

	$variables = $this->runAction(FilesController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Oversized images' => 1, 'Assets with no references' => 0, 'Missing alt text' => 0, 'Large files' => 0])
		->and($variables['tasks'][0]['url'])->toContain('hub/data/assets');
});

it('lists a row per check of the sections, counting the sections failing it', function () {
	$variables = $this->runAction(SectionsController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Entries' => 1, 'Entry types' => 1, 'Disabled entries' => 1, 'Drafts' => 1, 'References' => 0, 'Reachability' => 0, 'Activity' => 0])
		->and($variables['tasks'][0]['severity'])->toBe(Severity::Warning)
		->and($variables['tasks'][0]['url'])->toContain('hub/structure/sections')->toContain('empty=1');
});

it('lists a row per check of the entry types', function () {
	$variables = $this->runAction(TypesController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Usage' => 1, 'Entries' => 1, 'Assignments' => 1, 'Fields' => 1, 'Disabled entries' => 1, 'Drafts' => 1, 'References' => 0, 'Activity' => 0])
		->and($variables['tasks'][0]['url'])->toContain('kind=unused');
});

it('lists a row per check of the fields', function () {
	$variables = $this->runAction(FieldsController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Usage' => 3, 'Content' => 1, 'Instructions' => 5, 'Similar fields' => 2, 'Sources' => 1, 'Entry types' => 0])
		->and($variables['tasks'][1]['url'])->toContain('empty=1');
});

it('lists a row per check of the volumes', function () {
	$variables = $this->runAction(VolumesController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Assets' => 1, 'Usage' => 0, 'Alternative text' => 0, 'File size' => 0, 'Relations' => 0, 'Image dimensions' => 0])
		->and($variables['tasks'][0]['url'])->toContain('hub/structure/volumes');
});

it('lists no checks without elements', function () {
	$this->sections = [];

	expect($this->runAction(SectionsController::class, 'diagnostics')->variables['tasks'])->toBe([]);
});
