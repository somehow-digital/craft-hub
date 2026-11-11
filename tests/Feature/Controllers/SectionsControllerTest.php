<?php

declare(strict_types=1);

use craft\elements\Entry;
use craft\elements\User;
use craft\web\TemplateResponseBehavior;
use craft\web\twig\variables\Paginate;
use craft\web\View;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\models\FilterParams;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use SomehowDigital\Craft\Hub\Tests\TestCase;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function (): void {
	$this->createContentModel();
});

it('lists the sections', function (): void {
	$response = $this->runAction(SectionsController::class, 'index');
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/sections/index')
		->and(array_keys(arrayAt($variables, 'list', 'sections')))->toBe([4, 1, 2, 3])
		->and(at($variables, 'list', 'total'))->toBe(4)
		->and(objectAt(Paginate::class, $variables, 'list', 'pageInfo')->total)->toBe(4)
		->and(objectAt(Paginate::class, $variables, 'list', 'pageInfo')->totalPages)->toBe(1)
		->and(at($variables, 'list', 'activities'))->toBe([4 => 'none', 1 => 'recent', 2 => 'recent', 3 => 'stale'])
		->and(at($variables, 'list', 'sort'))->toBeNull()
		->and(at($variables, 'list', 'dir'))->toBeNull()
		->and($variables)->not->toHaveKey('summary')
		->and($variables['selectedSites'])->toBe([])
		->and($variables['siteIds'])->toBe([1, 2])
		->and($variables['hubSite'])->toBeNull();
});

it('summarizes the sections on their statistics tab, loading the cards as they are opened', function (): void {
	$response = $this->runAction(SectionsController::class, 'statistics');

	expect($response->template)->toBe('hub/pages/statistics')
		->and(array_column(arrayAt($response->variables, 'cards'), 'key'))->toBe(['sections', 'types', 'entries', 'activity'])
		->and(at($response->variables, 'cards', 0))->not->toHaveKey('total');
});

it('loads a card of the statistics tab alone', function (): void {
	$response = $this->runAction(SectionsController::class, 'statistics', query: ['card' => 'types']);

	expect($response->template)->toBe('hub/_components/statistics-card')
		->and(at($response->variables, 'card', 'key'))->toBe('types')
		->and(at($response->variables, 'card', 'total'))->toBe(4);
});

it('loads a card with its chart over the snapshots of the range asked for', function (): void {
	$this->db()->tables = [Tables::SNAPSHOTS];
	respondSnapshots($this, [[1, '2020-01-01']], metrics: ['sections' => [['type' => 'channel'], ['type' => 'single'], ['type' => 'channel']]]);

	$response = $this->runAction(SectionsController::class, 'statistics', query: ['card' => 'types', 'range' => '90']);

	expect(at($response->variables, 'card', 'chart', 'series', 0, 'values', 0))->toBe(3);
});

it('renders the chart of a card in its body, with the change in its header', function (): void {
	$html = Craft::$app->getView()->renderTemplate('hub/_components/statistics-card', ['card' => [
		'key' => 'entries',
		'label' => 'Entries',
		'icon' => 'file-lines',
		'total' => 12,
		'segments' => [],
		'chart' => [
			'range' => 30,
			'ranges' => [30, 90, 365, 0],
			'since' => new DateTime('2026-10-20'),
			'change' => 2,
			'dates' => ['2026-10-20', '2026-10-27'],
			'series' => [
				['key' => 'total', 'label' => 'Total', 'color' => null, 'dashed' => false, 'values' => [10, 12]],
				['key' => 'drafts', 'label' => 'Drafts', 'color' => 'amber', 'dashed' => true, 'values' => [null, 3]],
			],
		],
	]], View::TEMPLATE_MODE_CP);

	expect($html)->toContain('<span class="hub-trend" data-hub-trend><span title=')
		->and($html)->toContain('+2<span class="visually-hidden">')
		->and($html)->toContain('data-hub-chart="')
		->and($html)->toContain('data-hub-chart-plot')
		->and(substr_count($html, 'data-hub-chart-range="'))->toBe(4)
		->and($html)->toContain('range=365')
		->and($html)->toContain('data-hub-chart-series="drafts"')
		->and($html)->toContain('data-color="amber" data-dashed')
		->and($html)->toContain('<td class="hub-statistics__number">–</td>');
});

it('loads a card without a chart when it has no history', function (): void {
	$response = $this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'statistics'], ['card' => 'authors']);

	expect($response->variables['card'])->not->toHaveKey('chart');
});

it('counts the segments of a card in a snapshot by how the sections were set up then', function (string $card, array $expected): void {
	$metrics = ['sections' => [
		1 => ['type' => 'channel', 'entryTypes' => [], 'fields' => []],
		2 => ['type' => 'channel', 'entryTypes' => [], 'fields' => []],
		9 => ['type' => 'single', 'entryTypes' => [], 'fields' => []],
	]];
	$entries = [['sectionId' => '1', 'typeId' => '10', 'fieldId' => null, 'sites' => '1', 'enabledSites' => '1', 'total' => '2', 'enabled' => '2', 'pending' => '0', 'expired' => '0', 'drafts' => '0', 'lastUpdated' => '2026-10-01 10:00:00']];

	$this->db()->tables = [Tables::SNAPSHOTS];
	respondSnapshots($this, [[1, '2026-10-20']], metrics: $metrics);
	$this->db()->respond('FROM {{%hub_snapshot_entries}}', array_map(fn(array $row): array => ['snapshotId' => '1', ...$row], $entries));

	$response = $this->runAction(SectionsController::class, 'statistics', query: ['card' => $card]);
	// What the snapshot counted is the first value of each series, followed by the current one.
	$series = array_map(fn(mixed $values): mixed => arrayAt($values)[0], array_column(arrayAt($response->variables, 'card', 'chart', 'series'), 'values', 'label'));

	expect(at($response->variables, 'card', 'chart', 'series', 0, 'values', 0))->toBe(3)
		->and($series)->toMatchArray($expected);
})->with([
	['sections', ['With entries' => 1, 'Empty' => 2]],
	['types', ['Channel' => 2, 'Single' => 1, 'Structure' => 0]],
	['activity', ['Recently edited' => 1, 'Stale' => 0, 'No entries' => 2]],
]);

it('has no card of the statistics tab by an unknown key', function (): void {
	$this->runAction(SectionsController::class, 'statistics', query: ['card' => 'unknown']);
})->throws(NotFoundHttpException::class);

it("loads a card of a section's statistics tab alone", function (): void {
	$response = $this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'statistics'], ['card' => 'entries']);

	expect($response->template)->toBe('hub/_components/statistics-card')
		->and(at($response->variables, 'card', 'total'))->toBe(2)
		->and($response->variables)->not->toHaveKey('checks');
});

it('filters and sorts the sections by the query params', function (): void {
	$variables = $this->runAction(SectionsController::class, 'index', query: [
		'q' => ' new ',
		'type' => 'channel,unknown',
		'urls' => 'yes',
		'entriesMin' => '1.7',
		'referencesMax' => '-1',
		'activity' => 'recent',
		'empty' => '0',
		'sort' => 'entries',
		'dir' => 'asc',
	])->variables;

	expect(at($variables, 'list', 'filter', 'search'))->toBe('new')
		->and(at($variables, 'list', 'filter', 'types'))->toBe(['channel'])
		->and(at($variables, 'list', 'filter', 'urls'))->toBe('yes')
		->and(at($variables, 'list', 'filter', 'entriesMin'))->toBe(1)
		->and(at($variables, 'list', 'filter', 'referencesMax'))->toBeNull()
		->and(at($variables, 'list', 'filter', 'activities'))->toBe(['recent'])
		->and(at($variables, 'list', 'filter', 'empty'))->toBeFalse()
		->and(at($variables, 'list', 'sort'))->toBe('entries')
		->and(at($variables, 'list', 'dir'))->toBe('asc')
		->and(at($variables, 'list', 'sortHeaders', 'entries', 'direction'))->toBe('asc')
		->and(at($variables, 'list', 'sortHeaders', 'entries', 'url'))->not->toContain('sort=entries&dir')
		->and(at($variables, 'list', 'sortHeaders', 'name', 'url'))->not->toContain('sort=');
});

it('ignores unknown sort columns and directions', function (): void {
	$variables = $this->runAction(SectionsController::class, 'index', query: ['sort' => 'bogus', 'dir' => 'sideways'])->variables;

	expect(at($variables, 'list', 'sort'))->toBeNull()
		->and(at($variables, 'list', 'dir'))->toBeNull()
		->and(at($variables, 'list', 'sortHeaders', 'name', 'direction'))->toBe('asc')
		->and(at($variables, 'list', 'sortHeaders', 'name', 'url'))->toContain('dir=desc');
});

it('shows a section', function (): void {
	$response = $this->runAction(SectionsController::class, 'view', ['id' => 2]);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/sections/view')
		->and($variables['selectedTab'])->toBe('overview')
		->and(at($variables, 'info', 'section', 'handle'))->toBe('pages')
		->and($variables['checks'])->not->toBeEmpty()
		->and($variables)->not->toHaveKeys(['summary', 'entries', 'reviewList']);
});

it('summarizes a section on its statistics tab', function (): void {
	$variables = $this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'statistics'], ['hubSite' => 'en'])->variables;
	[$entries, $entryTypes, $references, $authors] = loadCards($variables['summary']);

	expect($variables['selectedTab'])->toBe('statistics')
		->and($entries['total'])->toBe(2)
		->and(at($entries, 'segments', 0, 'url'))->toContain('hub/structure/sections/2/entries')
		->and(at($entries, 'segments', 0, 'url'))->toContain('status=live')
		->and($authors['label'])->toBe('Authors')
		->and(array_column(arrayAt($entryTypes, 'segments'), 'value', 'label'))->toBe(['Page' => 2, 'Article' => 0])
		->and(at($entryTypes, 'segments', 0, 'url'))->toContain('section=pages')
		->and(at($entryTypes, 'segments', 0, 'url'))->toContain('type=page')
		->and($entryTypes['note'])->toBe('One entry type has no entries here')
		->and($references['note'])->toBe('No Entries field and one Link field can relate to entries from this section')
		->and($variables['checks'])->not->toBeEmpty()
		->and($variables['hubSite'])->toBe('en')
		->and($variables['siteIds'])->toBe([1])
		->and($variables)->not->toHaveKeys(['entries', 'reviewList']);
});

it('notes no entry types without entries in a section', function (): void {
	expect(at(loadCards(at($this->runAction(SectionsController::class, 'view', ['id' => 1, 'tab' => 'statistics'])->variables, 'summary')), 1))->not->toHaveKey('note');
});

it('remembers the filters of the entries tab', function (): void {
	$this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'entries'], ['q' => 'news']);
	$this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'entries']);

	expect(webResponse()->getHeaders()->get('Location'))->toContain('hub/structure/sections/2/entries&q=news');
});

it('lists the entries of a section on a tab of its own', function (): void {
	ElementQueries::respond(Entry::class, [new Entry(['id' => 1, 'siteId' => 1, 'title' => 'News', 'sectionId' => 1, 'typeId' => 10])]);

	$variables = $this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'entries'], query: ['field' => 'blocks'])->variables;

	expect($variables['selectedTab'])->toBe('entries')
		->and(at($variables, 'list', 'listFixed'))->toBe('section')
		// Nested entries of a field don't widen the list of a section.
		->and(at($variables, 'list', 'filter', 'fields'))->toBe([])
		->and(at($variables, 'list', 'listPath'))->toBe('hub/structure/sections/2/entries')
		->and(at($variables, 'list', 'entries'))->toHaveCount(1)
		->and(array_column(arrayAt($variables, 'list', 'columns'), 'key'))->not->toContain('origin')
		->and(array_column(arrayAt($variables, 'list', 'columns'), 'key'))->not->toContain('owner')
		->and($variables['checks'])->not->toBeEmpty()
		->and($variables)->not->toHaveKeys(['summary', 'reviewList']);
});

it('shows the checks of a section on its diagnostics tab', function (): void {
	$variables = $this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'diagnostics'])->variables;

	expect($variables['selectedTab'])->toBe('diagnostics')
		->and(array_map(fn(mixed $check): mixed => at($check, 'key'), arrayAt($variables, 'checks')))->toContain('sections.empty', 'entries.drafts')
		->and($variables)->not->toHaveKeys(['summary', 'entries', 'reviewList']);
});

it('fails for the statistics tab while statistics are disabled', function (): void {
	$this->hub()->settings->enableStatistics = false;

	try {
		$this->runAction(SectionsController::class, 'view', ['id' => 2, 'tab' => 'statistics']);
	} finally {
		$this->hub()->settings->enableStatistics = true;
	}
})->throws(NotFoundHttpException::class);

it('fails for tabs that sections do not have', function (): void {
	$this->runAction(SectionsController::class, 'view', ['id' => 1, 'tab' => 'assets']);
})->throws(NotFoundHttpException::class);

it('fails for sections that do not exist', function (): void {
	$this->runAction(SectionsController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);

it('requires access to Hub', function (): void {
	$this->actingAs(permissions: []);

	$this->runAction(SectionsController::class, 'index');
})->with([
	'without the structure' => [[Permission::AccessData->value, Page::Sections->viewPermission()]],
	'without the sections' => [[Permission::AccessStructure->value, Page::Fields->viewPermission()]],
])->throws(ForbiddenHttpException::class);

it('requires a CP request', function (): void {
	$controller = new SectionsController('sections', $this->hub());
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1]));
	$this->request(cp: false);

	$controller->runAction('index');
})->throws(BadRequestHttpException::class);

it('keeps the selected sites', function (): void {
	$this->site(2, 'de');

	expect(at(loadCards(at($this->runAction(SectionsController::class, 'statistics', query: [FilterParams::SITE_PARAM => 'de'])->variables, 'cards')), 0, 'url'))->toContain('hubSite=de');
});

it('sorts against the natural direction', function (): void {
	$variables = $this->runAction(SectionsController::class, 'index', query: ['dir' => 'desc'])->variables;

	expect(array_keys(arrayAt($variables, 'list', 'sections')))->toBe([3, 2, 1, 4])
		->and(at($variables, 'list', 'sort'))->toBe('name')
		->and(at($variables, 'list', 'dir'))->toBe('desc');
});

it('runs no action that a handler cancels', function (): void {
	Event::on(SectionsController::class, Controller::EVENT_BEFORE_ACTION, fn(ActionEvent $event): false => $event->isValid = false);
	webResponse()->detachBehavior(TemplateResponseBehavior::NAME);

	$controller = new SectionsController('sections', $this->hub());
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1]));
	$this->request();

	expect($controller->runAction('index'))->toBeNull()
		->and(webResponse()->getBehavior(TemplateResponseBehavior::NAME))->toBeNull();
});

/**
 * The link of the header of a column of the sections list, for the query params of the page.
 *
 * @param array<string,mixed> $query
 */
function sortUrl(TestCase $test, array $query, string $column): mixed
{
	return at($test->runAction(SectionsController::class, 'index', query: $query)->variables, 'list', 'sortHeaders', $column, 'url');
}

it('sorts by a column in its natural direction, then reversed, then resets the sorting', function (): void {
	expect(sortUrl($this, [], 'entries'))->toContain('sort=entries')->not->toContain('dir=')
		->and(sortUrl($this, ['sort' => 'entries'], 'entries'))->toContain('sort=entries&dir=asc')
		->and(sortUrl($this, ['sort' => 'entries', 'dir' => 'asc'], 'entries'))->toContain('sort=name')->not->toContain('dir=')
		->and(sortUrl($this, ['sort' => 'entries', 'dir' => 'asc', 'q' => 'news'], 'entries'))->toContain('q=news')->not->toContain('sort=');
});

it('reverses the default sort and back', function (): void {
	expect(sortUrl($this, [], 'name'))->toContain('dir=desc')
		->and(sortUrl($this, ['dir' => 'desc'], 'name'))->toContain('sort=name')->not->toContain('dir=')
		->and(sortUrl($this, ['dir' => 'desc', 'hubSite' => 'en'], 'name'))->toContain('sort=name')
		->and(sortUrl($this, ['dir' => 'desc', 'type' => 'channel'], 'name'))->not->toContain('sort=');
});

it('leaves out the data of hidden columns', function (): void {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_SECTIONS => ['order' => [], 'hidden' => ['updated']]]];

	$variables = $this->runAction(SectionsController::class, 'index')->variables;

	expect(at($variables, 'list', 'activities'))->toBe([])
		->and(at($variables, 'list', 'sections'))->not->toBeEmpty();
});

it('lists only the selected sections while reviewing them, whatever the filters', function (): void {
	$this->session['hub:bulk:selection:sections:hub/structure/sections'] = [1, 3, 99];

	$variables = $this->runAction(SectionsController::class, 'index', query: ['selection' => '1', 'type' => 'structure'])->variables;

	// Selected sections that no longer exist are unselected.
	expect(array_keys(arrayAt($variables, 'list', 'sections')))->toBe([1, 3])
		->and(at($variables, 'list', 'filter', 'types'))->toBe([])
		->and(at($variables, 'list', 'bulkSelection', 'review'))->toBeTrue()
		->and(at($variables, 'list', 'bulkSelection', 'ids'))->toBe([1, 3])
		->and(at($variables, 'list', 'bulkSelection', 'outside'))->toBe(0)
		->and($this->session['hub:bulk:selection:sections:hub/structure/sections'])->toBe([1, 3])
		->and($this->session)->not->toHaveKey('hub:bulk:filter:sections:hub/structure/sections');
});

it('counts the selected sections the filters leave out, keeping the filters to select all matching ones', function (): void {
	$this->session['hub:bulk:selection:sections:hub/structure/sections'] = [1, 2];

	$variables = $this->runAction(SectionsController::class, 'index', query: ['type' => 'channel'])->variables;

	expect(at($variables, 'list', 'bulkSelection', 'review'))->toBeFalse()
		->and(at($variables, 'list', 'bulkSelection', 'ids'))->toBe([1, 2])
		->and(at($variables, 'list', 'bulkSelection', 'outside'))->toBe(1)
		->and(at($variables, 'list', 'bulkSelection', 'limit'))->toBe(1000)
		->and(at($variables, 'list', 'bulkSelection', 'listUrl'))->toContain('hub/structure/sections')
		->and(at($variables, 'list', 'bulkSelection', 'reviewUrl'))->toContain('selection=1')
		->and(at($this->session['hub:bulk:filter:sections:hub/structure/sections'], 'types'))->toBe(['channel']);
});

it('offers no selection to users who may not label sections', function (): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessStructure->value, Page::Sections->viewPermission(), Page::Sections->bulkEditPermission()]);

	$variables = $this->runAction(SectionsController::class, 'index', query: ['selection' => '1'])->variables;

	expect(at($variables, 'list', 'bulkActions'))->toBe([])
		->and(at($variables, 'list', 'bulkSelection'))->toBeNull()
		->and(array_keys(arrayAt($variables, 'list', 'sections')))->toBe([4, 1, 2, 3]);
});

it('requires the permissions to access the structure and view the sections', function (array $permissions): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', ...strings($permissions)]);

	$this->runAction(SectionsController::class, 'index');
})->with([
	'without the structure' => [[Permission::AccessData->value, Page::Sections->viewPermission()]],
	'without the sections' => [[Permission::AccessStructure->value, Page::Fields->viewPermission()]],
])->throws(ForbiddenHttpException::class);

it('requires the permission to view the entries for the entries tab', function (): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessStructure->value, Page::Sections->viewPermission()]);

	$this->runAction(SectionsController::class, 'view', ['id' => 1, 'tab' => 'entries']);
})->throws(ForbiddenHttpException::class);
