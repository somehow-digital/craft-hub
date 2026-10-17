<?php

use craft\elements\db\ElementQuery;
use SomehowDigital\Craft\Hub\controllers\EntriesController;
use SomehowDigital\Craft\Hub\controllers\FieldsController;
use SomehowDigital\Craft\Hub\controllers\FilesController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\controllers\TypesController;
use SomehowDigital\Craft\Hub\controllers\UsersController;
use SomehowDigital\Craft\Hub\controllers\VolumesController;
use SomehowDigital\Craft\Hub\enums\Severity;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->createContentModel();
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

it('lists a row per check of the entries, counting the entries its filter matches, the ones to do first', function () {
	$this->db()->respondToCounts(fn (ElementQuery $query) => $query->status === ['disabled'] ? [['sectionId' => 1, 'typeId' => 10, 'total' => 2]] : []);

	$variables = $this->runAction(EntriesController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Disabled entries' => 2, 'Unreferenced entries' => 0, 'Stale entries' => 0, 'Entries with drafts' => 0])
		->and($variables['tasks'][0]['severity'])->toBe(Severity::Notice)
		->and($variables['tasks'][0]['url'])->toContain('hub/data/entries')->toContain('status=disabled');
});

it('lists a row per check of the assets', function () {
	$variables = $this->runAction(FilesController::class, 'diagnostics')->variables;

	expect(array_keys(taskValues($variables)))->toBe(['Unreferenced assets', 'Images without alternative text', 'Large files', 'Oversized images'])
		->and($variables['tasks'][0]['url'])->toContain('hub/data/assets');
});

it('lists a row per check of the sections, counting the sections its filter matches', function () {
	$variables = $this->runAction(SectionsController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Empty sections' => 1])
		->and($variables['tasks'][0]['severity'])->toBe(Severity::Warning)
		->and($variables['tasks'][0]['url'])->toContain('hub/structure/sections')->toContain('empty=1');
});

it('lists a row per check of the entry types', function () {
	$variables = $this->runAction(TypesController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Unused entry types' => 1, 'Empty entry types' => 1])
		->and($variables['tasks'][0]['url'])->toContain('hub/structure/entry-types');
});

it('lists a row per check of the fields', function () {
	$variables = $this->runAction(FieldsController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Unused fields' => 3, 'Empty fields' => 1])
		->and($variables['tasks'][1]['url'])->toContain('empty=1');
});

it('lists a row per check of the volumes', function () {
	$variables = $this->runAction(VolumesController::class, 'diagnostics')->variables;

	expect(taskValues($variables))->toBe(['Empty volumes' => 1])
		->and($variables['tasks'][0]['url'])->toContain('hub/structure/volumes');
});

it('flags nothing without elements', function () {
	$this->sections = [];

	expect(taskValues($this->runAction(SectionsController::class, 'diagnostics')->variables))->toBe(['Empty sections' => 0]);
});

it('has no diagnostics tabs while diagnostics are disabled', function (string $class, array $params) {
	$this->hub()->getSettings()->enableDiagnostics = false;

	try {
		$this->runAction($class, ...$params);
	} finally {
		$this->hub()->getSettings()->enableDiagnostics = true;
	}
})->with([
	'index page' => [SectionsController::class, ['diagnostics']],
	'detail page' => [SectionsController::class, ['view', ['id' => 2, 'tab' => 'diagnostics']]],
])->throws(NotFoundHttpException::class);

it('tells the templates whether diagnostics are enabled', function () {
	$this->hub()->getSettings()->enableDiagnostics = false;

	try {
		$variables = $this->runAction(SectionsController::class, 'view', ['id' => 2])->variables;
	} finally {
		$this->hub()->getSettings()->enableDiagnostics = true;
	}

	expect($variables['diagnosticsEnabled'])->toBeFalse()
		->and($this->runAction(SectionsController::class, 'index')->variables['diagnosticsEnabled'])->toBeTrue();
});

it('lists the custom checks of entries, counting the entries their filters match, and leaves out disabled checks', function () {
	$this->db()->respond('FROM {{%hub_checks}}', [
		['id' => 1, 'kind' => 'entries', 'handle' => 'stale', 'filter' => null, 'severity' => null, 'enabled' => 0, 'sortOrder' => 1, 'uid' => 'stale'],
		[
			'id' => 2, 'kind' => 'entries', 'handle' => null, 'filter' => 'drafts', 'name' => 'Unfinished', 'description' => 'Drafts.', 'passedDescription' => null,
			'icon' => 'pen', 'severity' => 'warning', 'enabled' => 1, 'sortOrder' => 2, 'uid' => 'unfinished',
		],
	]);
	$this->db()->respondToCounts(fn (ElementQuery $query) => $query->status === ['disabled'] ? [] : [['sectionId' => 1, 'typeId' => 10, 'total' => 3]]);

	$variables = $this->runAction(EntriesController::class, 'diagnostics')->variables;
	$tasks = array_column($variables['tasks'], null, 'label');

	expect(array_keys($tasks))->toContain('Unfinished')->not->toContain('Stale entries')
		->and($tasks['Unfinished']['value'])->toBe(3)
		->and($tasks['Unfinished']['severity'])->toBe(Severity::Warning)
		->and($tasks['Unfinished']['url'])->toContain('hub/data/entries')->toContain('drafts=1');
});

it('counts the sections a custom check of sections flags', function () {
	$this->db()->respond('FROM {{%hub_checks}}', [[
		'id' => 2, 'kind' => 'sections', 'handle' => null, 'filter' => 'withoutUrls', 'name' => 'Hidden', 'description' => 'No URLs.', 'passedDescription' => null,
		'icon' => null, 'severity' => null, 'enabled' => 1, 'sortOrder' => 1, 'uid' => 'hidden',
	]]);

	$tasks = array_column($this->runAction(SectionsController::class, 'diagnostics')->variables['tasks'], null, 'label');

	expect($tasks['Hidden']['value'])->toBe(2)
		->and($tasks['Hidden']['url'])->toContain('hub/structure/sections')->toContain('urls=');
});
