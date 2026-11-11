<?php

declare(strict_types=1);

use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\services\Tokens;
use craft\web\twig\variables\Paginate;
use SomehowDigital\Craft\Hub\controllers\EntriesController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\TrashedEntryFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use SomehowDigital\Craft\Hub\Tests\TestCase;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function (): void {
	$this->createContentModel();
});

/**
 * Entries with the given IDs, one stale.
 *
 * @return Entry[]
 */
function listedEntries(int ...$ids): array
{
	return array_map(fn(int $id): Entry => new Entry([
		'id' => $id,
		'siteId' => 1,
		'sectionId' => 1,
		'typeId' => 10,
		'dateUpdated' => new DateTime($id === 1 ? '-2 years' : '-1 day'),
	]), $ids);
}

it('lists the entries', function (): void {
	$entries = listedEntries(1, 2);
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->asArray ? [] : $entries);
	ElementQueries::respond(User::class, []);

	$response = $this->runAction(EntriesController::class, 'index');
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/entries/index')
		->and(at($variables, 'list', 'entries'))->toBe($entries)
		->and(objectAt(Paginate::class, $variables, 'list', 'pageInfo')->total)->toBe(2)
		->and(array_keys(arrayAt($variables, 'list', 'statuses')))->toBe(['live', 'pending', 'expired', 'disabled'])
		->and(at($variables, 'list', 'staleMonths'))->toBe(12)
		->and(array_map(fn(SectionInfo $info): ?string => $info->section->handle, objectsAt(SectionInfo::class, $variables, 'list', 'sections')))->toBe(['archive', 'news', 'pages', 'settings'])
		->and(array_map(fn(EntryTypeInfo $info): ?string => $info->entryType->handle, objectsAt(EntryTypeInfo::class, $variables, 'list', 'entryTypes')))->toBe(['article', 'block', 'empty', 'page'])
		->and(array_keys(arrayAt($variables, 'list', 'nestedFields')))->toBe([2])
		->and(array_column(arrayAt($variables, 'list', 'columns'), 'key'))->toContain('origin', 'owner')
		->and(at($variables, 'list', 'listFixed'))->toBeNull()
		->and(at($variables, 'list', 'listPath'))->toBe('hub/data/entries')
		->and(at($variables, 'list', 'sort'))->toBeNull()
		// The options of the user filters are loaded as their dropdowns open.
		->and(at($variables, 'list', 'authorOptions'))->toBe([])
		->and(at($variables, 'list', 'editorOptions'))->toBe([])
		->and(at($variables, 'list', 'optionsUrls', 'author'))->toContain('hub/options/index', 'filter=author')
		->and($this->db()->queriesMatching('entries_authors'))->toBe([]);
});

it('shows the users picked in its filters', function (): void {
	ElementQueries::respond(Entry::class, []);
	ElementQueries::respond(User::class, [new User(['id' => 4, 'siteId' => 1, 'username' => 'jane'])]);

	$variables = $this->runAction(EntriesController::class, 'index', query: ['author' => 'me,4', 'editor' => 'me'])->variables;

	expect(array_keys(arrayAt($variables, 'list', 'authorOptions')))->toBe(['me', 4])
		->and(array_keys(arrayAt($variables, 'list', 'editorOptions')))->toBe(['me']);
});

it('reads the filters of the entries list', function (): void {
	$variables = $this->runAction(EntriesController::class, 'index', query: [
		'q' => 'term',
		'section' => 'news,bogus',
		'type' => 'article',
		'origin' => 'nested',
		'field' => 'blocks,legacy,bogus',
		'status' => 'live,bogus',
		'author' => '3,x,-1',
		'editor' => 'me,5',
		'usage' => 'unreferenced',
		'referencesMin' => '1',
		'postedFrom' => '2024-01-01',
		'postedTo' => '2024-13-45x',
		'updatedTo' => '2024-02-01',
		'stale' => '1',
		'drafts' => '1',
		'myDrafts' => '1',
		'sort' => 'title',
	])->variables;
	$filter = objectAt(EntryFilter::class, $variables, 'list', 'filter');

	expect($filter->search)->toBe('term')
		->and($filter->sections)->toBe(['news'])
		->and($filter->types)->toBe(['article'])
		->and($filter->origin)->toBe('nested')
		// Only fields holding nested entries can be filtered by.
		->and($filter->fields)->toBe(['blocks'])
		->and($filter->statuses)->toBe(['live'])
		->and($filter->authors)->toBe([3])
		->and($filter->editors)->toBe(['me', 5])
		->and($filter->usage)->toBe('unreferenced')
		->and($filter->referencesMin)->toBe(1)
		->and($filter->postedFrom?->format('Y-m-d'))->toBe('2024-01-01')
		->and($filter->postedTo)->toBeNull()
		->and($filter->updatedTo?->format('Y-m-d'))->toBe('2024-02-01')
		->and($filter->staleMonths)->toBe(12)
		->and($filter->drafts)->toBeTrue()
		->and($filter->myDrafts)->toBeTrue()
		->and(at($variables, 'list', 'sort'))->toBe('title')
		->and(at($variables, 'list', 'sortHeaders', 'title', 'url'))->toContain('section=news')
		->and(at($variables, 'list', 'sortHeaders', 'title', 'url'))->toContain('dir=desc');
});

it('ignores unknown usage values and origins', function (): void {
	/** @var EntryFilter $filter */
	$filter = at($this->runAction(EntriesController::class, 'index', query: ['usage' => 'any', 'origin' => 'any'])->variables, 'list', 'filter');

	expect($filter->usage)->toBeNull()
		->and($filter->origin)->toBeNull();
});

it('summarizes the entries', function (): void {
	$this->db()->respondToCounts(fn(): array => [['sectionId' => null, 'typeId' => null, 'total' => 3]]);
	$this->db()->respond('FROM {{%entries_authors}}', [['authorId' => '3', 'count' => '4'], ['authorId' => '4', 'count' => '1']]);
	ElementQueries::respond(User::class, [new User(['id' => 3, 'username' => 'ann', 'fullName' => 'Ann']), new User(['id' => 4, 'username' => 'ann2', 'fullName' => 'Ann'])]);

	[$entries, $sections, $types, $authors, $activity] = loadCards(at($this->runAction(EntriesController::class, 'statistics')->variables, 'cards'));
	$statuses = array_column(arrayAt($entries, 'segments'), null, 'label');

	// Like the list, the summary counts nested entries too, and splits the enabled ones into Craft's statuses.
	expect($entries['total'])->toBe(15)
		->and(array_column(arrayAt($entries, 'segments'), 'value', 'label'))->toBe(['Live' => 12, 'Pending' => 1, 'Expired' => 1, 'Disabled' => 1, 'Drafts' => 1])
		->and(at($statuses, 'Disabled', 'url'))->toContain('status=disabled')
		->and(at($statuses, 'Pending', 'url'))->toContain('status=pending')
		->and(at($statuses, 'Drafts', 'url'))->toContain('drafts=1')
		// Authors sharing a name are told apart by their username.
		->and(array_column(arrayAt($authors, 'segments'), 'value', 'label'))->toBe(['Ann' => 4, 'Ann (ann2)' => 1])
		->and(at($authors, 'segments', 1, 'url'))->toContain('author=4')
		->and(at($authors, 'table', 'unit'))->toBe('Entries')
		->and(array_column(arrayAt($sections, 'segments'), 'value', 'label'))->toBe(['News' => 5, 'Pages' => 2, 'Settings' => 1, 'Archive' => 0])
		->and(at($sections, 'segments', 0, 'url'))->toContain('section=news')
		->and(array_column(arrayAt($types, 'segments'), 'value', 'label'))->toBe(['Block' => 7, 'Article' => 5, 'Page' => 3])
		->and(at($types, 'segments', 2, 'url'))->toContain('type=page')
		->and(array_column(arrayAt($activity, 'segments'), 'value'))->toBe([12, 3])
		->and(at($activity, 'segments', 1, 'url'))->toContain('stale=1');
});

it('paginates lists on detail pages by the page of the URL', function (): void {
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->asArray ? [] : listedEntries(...range(1, 60)));
	$this->request();
	setPageNumber(2);

	$controller = Craft::createObject(SectionsController::class, ['test', $this->hub()]);
	$variables = (fn() => $this->entryListVariables('hub/structure/sections/1/entries'))->call($controller);

	expect(objectAt(Paginate::class, $variables, 'list', 'pageInfo')->currentPage)->toBe(2)
		->and(objectAt(Paginate::class, $variables, 'list', 'pageInfo')->totalPages)->toBe(2)
		->and(objectAt(Paginate::class, $variables, 'list', 'pageInfo')->total)->toBe(60);
});

it('shows an entry', function (): void {
	$entry = listedEntries(1)[0];
	$variant = new Entry(['id' => 1, 'siteId' => 2]);
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => match (true) {
		$query->asArray => [],
		$query->unique => [$entry],
		default => [$entry, $variant],
	});

	$response = $this->runAction(EntriesController::class, 'view', ['id' => 1]);
	$variables = $response->variables;

	expect($response->template)->toBe('hub/pages/entries/view')
		->and($variables['entry'])->toBe($entry)
		->and($variables['referenceCount'])->toBe(0)
		->and($variables['referencedCount'])->toBe(0)
		->and($variables['siteCount'])->toBe(2)
		->and($variables)->not->toHaveKeys(['references', 'referenced', 'sites'])
		->and($variables['stale'])->toBeTrue()
		->and($variables['checks'])->not->toBeEmpty();
});

it('loads a panel of an entry alone, with what it lists', function (): void {
	$entry = listedEntries(1)[0];
	$variant = new Entry(['id' => 1, 'siteId' => 2]);
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => match (true) {
		$query->asArray => [],
		$query->unique => [$entry],
		default => [$entry, $variant],
	});

	$response = $this->runAction(EntriesController::class, 'view', ['id' => 1], ['panel' => 'sites']);

	expect($response->template)->toBe('hub/_panels/entries/sites')
		->and($response->variables['sites'])->toBe([$entry, $variant])
		->and($response->variables['loaded'])->toBeTrue()
		->and($response->variables)->not->toHaveKeys(['checks', 'lastEdit']);
});

it('loads the checks of an entry on its diagnostics tab', function (): void {
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->asArray ? [] : listedEntries(1));

	$response = $this->runAction(EntriesController::class, 'view', ['id' => 1, 'tab' => 'diagnostics'], ['panel' => 'checks']);

	expect($response->template)->toBe('hub/_panels/checks')
		->and($response->variables['checks'])->not->toBeEmpty();
});

it('has no panels it does not know', function (): void {
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->asArray ? [] : listedEntries(1));

	$this->runAction(EntriesController::class, 'view', ['id' => 1], ['panel' => 'unknown']);
})->throws(NotFoundHttpException::class);

it('shows the history of an entry', function (): void {
	$entry = listedEntries(1)[0];
	$revisions = [new Entry(['id' => 3, 'siteId' => 1]), new Entry(['id' => 2, 'siteId' => 1])];
	$draft = new Entry(['id' => 4, 'siteId' => 1]);
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => match (true) {
		$query->asArray => [],
		$query->revisions => $revisions,
		$query->drafts === true => [$draft],
		default => [$entry],
	});

	$variables = $this->runAction(EntriesController::class, 'view', ['id' => 1, 'tab' => 'history'])->variables;

	expect($variables['selectedTab'])->toBe('history')
		->and($variables['hasHistory'])->toBeTrue()
		->and($variables['revisionCount'])->toBe(2)
		->and($variables['draftCount'])->toBe(1)
		->and($variables)->not->toHaveKeys(['revisions', 'drafts']);

	$revisionsPanel = $this->runAction(EntriesController::class, 'view', ['id' => 1, 'tab' => 'history'], ['panel' => 'revisions']);
	$draftsPanel = $this->runAction(EntriesController::class, 'view', ['id' => 1, 'tab' => 'history'], ['panel' => 'drafts']);

	expect($revisionsPanel->template)->toBe('hub/_panels/entries/revisions')
		->and($revisionsPanel->variables['revisions'])->toBe($revisions)
		->and(objectAt(Paginate::class, $revisionsPanel->variables, 'pageInfo')->total)->toBe(2)
		->and($draftsPanel->template)->toBe('hub/_panels/entries/drafts')
		->and($draftsPanel->variables['drafts'])->toBe([$draft]);
});

it('loads the history only on its tab', function (): void {
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->asArray || $query->revisions === true ? [] : listedEntries(1));

	$variables = $this->runAction(EntriesController::class, 'view', ['id' => 1])->variables;

	expect($variables)->not->toHaveKeys(['revisions', 'drafts'])
		->and(array_filter(ElementQueries::executed(Entry::class), fn(ElementQuery $query): bool => $query->revisions === true))->toBeEmpty();
});

it('has no history tab for entries without revisions or drafts', function (): void {
	// Nested entries of fields without versioning have no revisions.
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->asArray || $query->drafts === true ? [] : [new Entry(['id' => 1, 'siteId' => 1, 'fieldId' => 2, 'typeId' => 12])]);

	$this->runAction(EntriesController::class, 'view', ['id' => 1, 'tab' => 'history']);
})->throws(NotFoundHttpException::class);

it('shows the drafts of entries without versioning on the history tab', function (): void {
	$draft = new Entry(['id' => 4, 'siteId' => 1]);
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => match (true) {
		$query->asArray => [],
		$query->drafts === true => [$draft],
		default => [new Entry(['id' => 1, 'siteId' => 1, 'fieldId' => 2, 'typeId' => 12])],
	});

	$variables = $this->runAction(EntriesController::class, 'view', ['id' => 1, 'tab' => 'history'])->variables;

	expect($variables['hasHistory'])->toBeTrue()
		->and($variables['hasRevisions'])->toBeFalse()
		->and($variables['draftCount'])->toBe(1)
		->and($variables['revisionCount'])->toBe(0)
		->and(array_filter(ElementQueries::executed(Entry::class), fn(ElementQuery $query): bool => $query->revisions === true))->toBeEmpty();
});

/**
 * Opens revision 3 of entry 1 in the news section with the given preview targets, returning the URL it redirects to.
 *
 * @param array<int,array<string,string>> $previewTargets
 */
function openRevision(TestCase $test, array $previewTargets): ?string
{
	(function () use ($previewTargets): void {
		$this->sections[1]['previewTargets'] = $previewTargets;
	})->call($test);
	$entry = new Entry(['id' => 1, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'uri' => 'news/article']);
	$revision = new Entry(['id' => 3, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 10, 'uri' => 'news/article', 'canonicalId' => 1, 'revisionId' => 7]);
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->revisions === true ? [$revision] : [$entry]);
	$tokens = Mockery::mock(Tokens::class);
	$tokens->shouldReceive('createPreviewToken')->once()->with(['preview/preview', [
		'elementType' => Entry::class,
		'canonicalId' => 1,
		'siteId' => 1,
		'revisionId' => 7,
		'userId' => 1,
	]])->andReturn('abc');
	Craft::$app->set('tokens', $tokens);

	(fn() => $this->request())->call($test);
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));
	Craft::createObject(EntriesController::class, ['test', Craft::$app->getModule('hub')])->runAction('view-revision', ['id' => 1, 'revisionId' => 3]);

	return webResponse()->getHeaders()->get('Location');
}

it('opens a revision on its first preview target with a preview token', function (): void {
	$location = openRevision($this, [['label' => 'Preview', 'url' => 'preview/article'], ['label' => 'Other', 'url' => 'other']]);
	$revisionQuery = array_values(array_filter(ElementQueries::executed(Entry::class), fn(ElementQuery $query): bool => $query->revisions === true))[0];

	expect($location)->toContain('preview/article')
		->and($location)->toContain('token=abc')
		->and($revisionQuery->id)->toBe(3);
});

it('opens a revision on its URL without preview targets', function (): void {
	expect(openRevision($this, []))->toContain('news/article')->toContain('token=abc');
});

it('fails for revisions not of the entry', function (): void {
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->revisions === true ? [] : listedEntries(1));

	$this->runAction(EntriesController::class, 'view-revision', ['id' => 1, 'revisionId' => 99]);
})->throws(NotFoundHttpException::class);

it('fails for entries that do not exist', function (): void {
	$this->runAction(EntriesController::class, 'view', ['id' => 99]);
})->throws(NotFoundHttpException::class);

/**
 * The query listing the entries, rather than counting them.
 *
 * @return EntryQuery<array-key,Entry>
 */
function listingQuery(): EntryQuery
{
	return array_values(array_filter(ElementQueries::$executed, fn(ElementQuery $query): bool => $query instanceof EntryQuery && !$query->asArray))[0];
}

it('loads the owners and authors of the entries with their columns', function (): void {
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->asArray ? [] : listedEntries(1, 2));

	$this->runAction(EntriesController::class, 'index');

	expect(listingQuery()->with)->toBe(['owner', 'authors', $this->hub()->users->photoPlan('authors.photo')]);
});

it('leaves out the data of hidden columns', function (): void {
	$this->preferences[1] = [ColumnService::PREFERENCE => [ColumnService::LIST_ENTRIES => ['order' => [], 'hidden' => ['origin', 'owner', 'creator', 'references', 'maintainers']]]];
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->asArray ? [] : listedEntries(1, 2));

	$variables = $this->runAction(EntriesController::class, 'index')->variables;

	expect(listingQuery()->with)->toBeEmpty()
		->and(at($variables, 'list', 'referenceCounts'))->toBe([])
		->and($this->db()->queriesMatching('{{%relations}}'))->toBe([])
		->and(at($variables, 'list', 'entries'))->toHaveCount(2);
});

it('lists only the selected entries while reviewing them, whatever the filters', function (): void {
	$this->session['hub:bulk:selection:entries:hub/data/entries'] = [5, 6];
	$entry = new Entry(['id' => 5, 'siteId' => 1, 'sectionId' => 1, 'typeId' => 1]);
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->asArray ? [] : [$entry]);
	ElementQueries::respond(User::class, []);

	$variables = $this->runAction(EntriesController::class, 'index', query: ['selection' => '1', 'status' => 'live'])->variables;
	$ids = array_map(fn(ElementQuery $query): mixed => $query->id, ElementQueries::executed(Entry::class));

	expect(at($variables, 'list', 'filter', 'statuses'))->toBe([])
		->and($ids)->toContain([5, 6])
		->and(at($variables, 'list', 'bulkSelection', 'ids'))->toBe([5])
		->and($this->session['hub:bulk:selection:entries:hub/data/entries'])->toBe([5]);
});

it('lists no entries while reviewing an empty selection', function (): void {
	ElementQueries::respond(Entry::class, []);
	ElementQueries::respond(User::class, []);

	$this->runAction(EntriesController::class, 'index', query: ['selection' => '1']);

	expect(array_map(fn(ElementQuery $query): mixed => $query->id, ElementQueries::executed(Entry::class)))->toContain([0]);
});

it('offers the trash as a tab of the entries page', function (string $action): void {
	ElementQueries::respond(Entry::class, []);
	ElementQueries::respond(User::class, []);

	expect(at($this->runAction(EntriesController::class, $action)->variables, 'extraTabs'))->toBe(['trash' => 'Trash']);
})->with(['index', 'statistics', 'diagnostics', 'trash']);

it('leaves out the trash tab while the trash is disabled', function (string $action): void {
	ElementQueries::respond(Entry::class, []);
	ElementQueries::respond(User::class, []);
	$this->hub()->settings->enableTrash = false;

	expect(at($this->runAction(EntriesController::class, $action)->variables, 'extraTabs'))->toBe([]);
})->with(['index', 'statistics', 'diagnostics']);

it('has no trash tab while the trash is disabled', function (): void {
	$this->hub()->settings->enableTrash = false;

	$this->runAction(EntriesController::class, 'trash');
})->throws(NotFoundHttpException::class);

it('only shows the trash to users who may view it', function (): void {
	ElementQueries::respond(Entry::class, []);
	ElementQueries::respond(User::class, []);
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Page::Entries->viewPermission(), Permission::DeleteEntries->value]);

	expect(at($this->runAction(EntriesController::class, 'index')->variables, 'extraTabs'))->toBe([])
		->and(fn() => $this->runAction(EntriesController::class, 'trash'))->toThrow(ForbiddenHttpException::class);
});

it('lists the trashed entries with who deleted them', function (): void {
	$entries = listedEntries(1, 2);
	ElementQueries::respond(Entry::class, fn(EntryQuery $query): array => $query->asArray ? [] : $entries);
	ElementQueries::respond(User::class, []);
	$this->db()->respond('FROM {{%hub_activity}}', [['subjectId' => '2', 'userId' => null, 'userName' => 'Editor']]);

	$response = $this->runAction(EntriesController::class, 'trash');
	$variables = $response->variables;
	$query = ElementQueries::executed(Entry::class)[0];

	expect($response->template)->toBe('hub/pages/entries/trash')
		->and(at($variables, 'list', 'entries'))->toBe($entries)
		->and(at($variables, 'list', 'deleters'))->toBe([2 => ['user' => null, 'name' => 'Editor']])
		->and(array_column(arrayAt($variables, 'list', 'sections'), 'handle'))->toContain('news')
		->and(array_column(arrayAt($variables, 'list', 'columns'), 'key'))->toBe(['type', 'origin', 'owner', 'deleted', 'deleter'])
		->and(at($variables, 'list', 'filtered'))->toBeFalse()
		->and(at($variables, 'list', 'listPath'))->toBe('hub/data/entries/trash')
		->and(array_keys(arrayAt($variables, 'list', 'bulkActions')))->toBe(['export', 'restore', 'restoreWithRelations', 'deletePermanently'])
		->and(at($variables, 'list', 'sort'))->toBeNull()
		->and($query->trashed)->toBeTrue();
});

it('reads the filters of the trashed entries list', function (): void {
	ElementQueries::respond(Entry::class, []);

	$variables = $this->runAction(EntriesController::class, 'trash', query: [
		'q' => 'term',
		'section' => 'news,bogus',
		'field' => 'blocks,bogus',
		'type' => 'article',
		'deletedFrom' => '2024-01-01',
		'deletedTo' => '2024-01-31',
		'sort' => 'title',
	])->variables;
	$filter = objectAt(TrashedEntryFilter::class, $variables, 'list', 'filter');

	expect($filter->search)->toBe('term')
		->and($filter->sections)->toBe(['news'])
		->and($filter->fields)->toBe(['blocks'])
		->and($filter->types)->toBe(['article'])
		->and($filter->deletedFrom?->format('Y-m-d'))->toBe('2024-01-01')
		->and($filter->deletedTo?->format('Y-m-d'))->toBe('2024-01-31')
		->and(at($variables, 'list', 'filtered'))->toBeTrue()
		->and(at($variables, 'list', 'sort'))->toBe('title');
});
