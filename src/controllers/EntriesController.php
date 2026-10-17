<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use DateTime;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\ListSelection;
use SomehowDigital\Craft\Hub\helpers\ListSort;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\TrashedEntryFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\TrashService;
use SomehowDigital\Craft\Hub\statistics\EntriesSummary;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * The entries page, below the "Data" navigation item: its list, trash, statistics and diagnostics, and the detail pages
 * of entries.
 */
class EntriesController extends BaseController
{
	protected ?Page $page = Page::Entries;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/data/entries')) !== null) {
			return $redirect;
		}

		return $this->renderTemplate('hub/pages/entries/index', [
			...$this->entryListVariables('hub/data/entries'),
			'extraTabs' => $this->trashTabs(),
		]);
	}

	/**
	 * The entries in the trash, to restore or delete them permanently.
	 */
	public function actionTrash(): Response
	{
		$hub = Hub::getInstance();
		$trash = $hub->trash;
		$path = 'hub/data/entries/trash';
		$entries = Craft::$app->getEntries();

		if (($redirect = $this->keepFilters($path)) !== null) {
			return $redirect;
		}

		// While reviewing the selection, the filters don't apply.
		$selection = ListSelection::fromRequest(ColumnService::LIST_TRASHED_ENTRIES, $path);
		$filter = $selection->review ? new TrashedEntryFilter() : $this->trashedEntryFilterFromRequest();
		$sort = ListSort::fromRequest($trash->getEntrySortColumns(), TrashService::SORT_DELETED);

		$query = $trash->getEntryQuery($filter, $sort->column, $sort->direction);
		$selection->limitQuery($query);
		[$pageInfo, $results] = Paginator::fromRequest()->elements($query);
		$columns = $hub->columns->getColumns(ColumnService::LIST_TRASHED_ENTRIES);

		return $this->renderTemplate('hub/pages/entries/trash', [
			'entries' => $results,
			'pageInfo' => $pageInfo,
			'deleters' => in_array('deleter', $hub->columns->getShownKeys($columns), true)
				? $trash->getDeleters(ActivitySubject::Entry, array_map(fn (Entry $entry) => (int)$entry->id, $results))
				: [],
			'sections' => $entries->getAllSections(),
			'nestedFields' => array_filter($hub->structure->getFields(), fn (FieldInfo $info) => $info->isNested),
			'entryTypes' => $entries->getAllEntryTypes(),
			'filter' => $filter,
			'filtered' => $filter != new TrashedEntryFilter(),
			'listPath' => $path,
			'columns' => $columns,
			...$selection->variables($filter),
			'extraTabs' => $this->trashTabs(),
			...$sort->variables($path),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Entries, (new EntriesSummary())->index(), extraTabs: $this->trashTabs());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::Entries, extraTabs: $this->trashTabs());
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$hub = Hub::getInstance();
		$entries = $hub->entries;
		$references = $hub->references;

		$entry = $entries->getEntry($id) ?? throw new NotFoundHttpException('Entry not found.');
		$this->requireViewable($entry);
		// Entries without versioning have no revisions, but can have drafts.
		$hasRevisions = $entry->hasRevisions();
		$drafts = !$hasRevisions || $tab === self::TAB_HISTORY ? $entries->getDrafts($entry) : [];
		$hasHistory = $hasRevisions || $drafts !== [];
		$tab = $this->detailTab($tab, $hasHistory ? [self::TAB_HISTORY] : []);
		$months = $hub->filters->getThreshold(CheckKind::Entries, 'stale');
		$staleDate = (new DateTime())->modify(sprintf('-%d months', $months));
		$lastEdit = $hub->edits->getLastEdit($entry);
		$stale = $lastEdit['date'] !== null && $lastEdit['date'] < $staleDate;

		return $this->renderTemplate('hub/pages/entries/view', [
			'entry' => $entry,
			'lastEdit' => $lastEdit,
			'selectedTab' => $tab,
			'stale' => $stale,
			'hasHistory' => $hasHistory,
			'hasRevisions' => $hasRevisions,
			'checks' => $hub->checks->forEntry($entry),
			...($tab === self::TAB_OVERVIEW ? [
				'references' => $references->getReferences($entry),
				'referenced' => $references->getReferenced($entry),
				'sites' => $entries->getSiteVariants($entry),
			] : []),
			...($tab === self::TAB_HISTORY ? $this->historyVariables($entry, $drafts) : []),
		]);
	}

	/**
	 * Opens a revision of an entry on the front end, as Craft's preview does: on a token rendering the revision in
	 * place of the entry. The token is only created as the revision is opened, as each one is stored until it expires.
	 */
	public function actionViewRevision(int $id, int $revisionId): Response
	{
		$entries = Hub::getInstance()->entries;

		$entry = $entries->getEntry($id) ?? throw new NotFoundHttpException('Entry not found.');

		$this->requireViewable($entry);

		$revision = $entries->getRevisionsQuery($entry)->id($revisionId)->with([])->one()
			?? throw new NotFoundHttpException('Revision not found.');

		// The first preview target of the section, like the CP's preview, falling back to the URL of the revision.
		$url = $revision->getPreviewTargets()[0]['url'] ?? $revision->getUrl() ?? throw new NotFoundHttpException('Revision has no URL.');

		$token = Craft::$app->getTokens()->createPreviewToken([
			'preview/preview', [
				'elementType' => Entry::class,
				'canonicalId' => $entry->id,
				'siteId' => $revision->siteId,
				'revisionId' => $revision->revisionId,
				'userId' => Craft::$app->getUser()->getId(),
			],
		]);

		if ($token === false) {
			throw new ServerErrorHttpException('Could not create a preview token.');
		}

		return $this->redirect(UrlHelper::urlWithToken($url, $token));
	}

	private function trashedEntryFilterFromRequest(): TrashedEntryFilter
	{
		$params = $this->filterParamsOfRequest();
		$entries = Craft::$app->getEntries();

		$filter = new TrashedEntryFilter();
		$filter->search = $params->search();
		$filter->sections = $params->list('section', array_map(fn (Section $section) => $section->handle, $entries->getAllSections()));
		$filter->fields = $params->list('field', array_map(fn (FieldInfo $info) => (string)$info->field->handle, array_filter(Hub::getInstance()->structure->getFields(), fn (FieldInfo $info) => $info->isNested)));
		$filter->types = $params->list('type', array_map(fn (EntryType $type) => $type->handle, $entries->getAllEntryTypes()));
		$filter->deletedFrom = $params->date('deletedFrom');
		$filter->deletedTo = $params->date('deletedTo');

		return $filter;
	}

	/**
	 * Variables of the history tab of an entry page: its drafts and, for entries with versioning, its revisions, paginated.
	 *
	 * @param Entry[] $drafts
	 * @return array<string,mixed>
	 */
	private function historyVariables(Entry $entry, array $drafts): array
	{
		if (!$entry->hasRevisions()) {
			return ['drafts' => $drafts];
		}

		[$pageInfo, $revisions] = Paginator::fromRequest()->query(Hub::getInstance()->entries->getRevisionsQuery($entry));

		return [
			'revisions' => $revisions,
			'pageInfo' => $pageInfo,
			'currentRevision' => $entry->getCurrentRevision(),
			'drafts' => $drafts,
		];
	}
}
