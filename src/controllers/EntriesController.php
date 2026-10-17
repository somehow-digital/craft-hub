<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Template;
use craft\models\EntryType;
use craft\models\Section;
use DateTime;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class EntriesController extends BaseController
{
	public const PAGE_SIZE = 50;

	public function actionIndex(): Response
	{
		$request = Craft::$app->getRequest();
		$hub = Hub::getInstance();
		/** @var EntryService $entries */
		$entries = $hub->entries;

		$sections = $hub->structure->getSections();
		$months = $hub->settings->staleThresholdMonths;

		$filter = $this->filterFromRequest($entries->getStatuses(), $months);

		$sort = (string)$request->getQueryParam('sort', '');
		$direction = (string)$request->getQueryParam('dir', '');

		// Columns sort in their natural direction unless `dir` says otherwise.
		$sortColumns = $entries->getSortColumns();
		$sort = isset($sortColumns[$sort]) ? $sort : EntryService::SORT_UPDATED;
		$direction = match ($direction) {
			'asc' => SORT_ASC,
			'desc' => SORT_DESC,
			default => $sortColumns[$sort],
		};

		$query = $entries->getQuery($filter, $sort, $direction)->limit(self::PAGE_SIZE);

		[$pageInfo, $results] = Template::paginateQuery($query);

		$relationCounts = $hub->references->getRelationCounts(array_map(fn (Entry $entry) => $entry->id, $results));
		$staleDate = (new DateTime())->modify(sprintf('-%d months', $months));

		$staleEntries = [];
		foreach ($results as $entry) {
			$staleEntries[$entry->id] = $entry->dateUpdated !== null && $entry->dateUpdated < $staleDate;
		}

		$reviews = $hub->checks->forEntries($results, $relationCounts, $staleEntries);

		// Only entry types used by sections, as nested entries aren't listed.
		$typeHandles = array_values(array_map(
			fn (EntryTypeInfo $info) => $info->entryType->handle,
			array_filter($hub->structure->getEntryTypes(), fn (EntryTypeInfo $info) => $info->sections !== []),
		));

		return $this->renderTemplate('hub/pages/entries/index', [
			'entries' => $results,
			'pageInfo' => $pageInfo,
			'relationCounts' => $relationCounts,
			'reviews' => $reviews,
			'sections' => $sections,
			'entryTypes' => $hub->structure->getEntryTypes(),
			'statuses' => array_intersect_key(Entry::statuses(), array_flip($entries->getStatuses())),
			'staleMonths' => $months,
			'sortHeaders' => $this->sortHeaders('hub/entries/entries', $sortColumns, EntryService::SORT_UPDATED, $sort, $direction),
			'authors' => $entries->getAuthors(),
			'counts' => [
				'types' => $entries->getCounts($typeHandles, fn (EntryFilter $filter, string $type) => $filter->types = [$type]),
				'statuses' => $entries->getCounts($entries->getStatuses(), fn (EntryFilter $filter, string $status) => $filter->statuses = [$status]),
				'usage' => $entries->getCounts([EntryService::USAGE_REFERENCED, EntryService::USAGE_UNREFERENCED], fn (EntryFilter $filter, string $usage) => $filter->usage = $usage),
			],
			'filter' => $filter,
			// Kept as hidden fields, so changing a filter keeps the sorting.
			'sort' => $sort !== EntryService::SORT_UPDATED || $direction !== $sortColumns[$sort] ? $sort : null,
			'dir' => $direction !== $sortColumns[$sort] ? ($direction === SORT_ASC ? 'asc' : 'desc') : null,
			'summary' => $this->entriesSummary($sections),
		]);
	}

	public function actionView(int $id): Response
	{
		$hub = Hub::getInstance();
		/** @var EntryService $entries */
		$entries = $hub->entries;
		/** @var ReferenceService $references */
		$references = $hub->references;

		$entry = $entries->getEntry($id) ?? throw new NotFoundHttpException('Entry not found.');
		$months = $hub->settings->staleThresholdMonths;
		$staleDate = (new DateTime())->modify(sprintf('-%d months', $months));
		$relations = $references->getRelations($entry);
		$stale = $entry->dateUpdated !== null && $entry->dateUpdated < $staleDate;

		return $this->renderTemplate('hub/pages/entries/view', [
			'entry' => $entry,
			'relations' => $relations,
			'sites' => $entries->getSiteVariants($entry),
			'stale' => $stale,
			'staleMonths' => $months,
			'checks' => $hub->checks->forEntry($entry, count($relations), $stale),
		]);
	}

	/**
	 * Filters arrive as query params, like the client-side filters of the other lists.
	 *
	 * @param string[] $statuses statuses that can be filtered by
	 * @param int $months months without updates after which entries are stale
	 */
	private function filterFromRequest(array $statuses, int $months): EntryFilter
	{
		$request = Craft::$app->getRequest();
		$entriesService = Craft::$app->getEntries();
		$usage = $request->getQueryParam('usage');

		$filter = new EntryFilter();
		$filter->search = trim((string)$request->getQueryParam('q', ''));
		$filter->sections = $this->listParam('section', array_map(fn (Section $section) => $section->handle, $entriesService->getAllSections()));
		$filter->types = $this->listParam('type', array_map(fn (EntryType $type) => $type->handle, $entriesService->getAllEntryTypes()));
		$filter->statuses = $this->listParam('status', $statuses);
		$filter->authors = $this->idsParam('author');
		$filter->usage = in_array($usage, [EntryService::USAGE_REFERENCED, EntryService::USAGE_UNREFERENCED], true) ? $usage : null;
		$filter->relationsMin = $this->intParam('relationsMin');
		$filter->relationsMax = $this->intParam('relationsMax');
		$filter->postedFrom = $this->dateParam('postedFrom');
		$filter->postedTo = $this->dateParam('postedTo');
		$filter->updatedFrom = $this->dateParam('updatedFrom');
		$filter->updatedTo = $this->dateParam('updatedTo');
		$filter->staleMonths = $request->getQueryParam('stale') === '1' ? $months : null;

		return $filter;
	}
}
