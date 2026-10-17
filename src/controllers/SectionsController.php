<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\SectionType;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SectionsController extends BaseController
{
	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/structure/sections')) !== null) {
			return $redirect;
		}

		$hub = Hub::getInstance();
		/** @var ListService $lists */
		$lists = $hub->lists;
		$months = $hub->settings->staleThresholdMonths;
		$all = $hub->structure->getSections();

		// While reviewing the selection, the filters don't apply.
		$selection = $this->selection(ColumnService::LIST_SECTIONS, 'hub/structure/sections');
		$filter = ($selection['review'] ?? false) ? new SectionFilter() : $this->filterFromRequest();
		$sortColumns = $lists->getSectionSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		[$pageInfo, $sections] = $this->paginateItems($this->reviewItems($lists->getSections($filter, $sort, $direction), $selection));
		$columns = $hub->columns->getColumns(ColumnService::LIST_SECTIONS);
		$shown = $hub->columns->getShownKeys($columns);

		return $this->renderTemplate('hub/pages/sections/index', [
			'sections' => $sections,
			'pageInfo' => $pageInfo,
			'total' => count($all),
			'activities' => in_array('updated', $shown, true) ? array_map(fn (SectionInfo $info) => $info->stats->getActivity($months), $sections) : [],
			'filter' => $filter,
			'columns' => $columns,
			...$this->labelVariables(ColumnService::LIST_SECTIONS, array_keys($sections)),
			...$this->bulkVariables(ColumnService::LIST_SECTIONS, $selection, $filter),
			...$this->sortVariables('hub/structure/sections', $sortColumns, ListService::SORT_NAME, $sort, $direction),
		]);
	}

	public function actionSummary(): Response
	{
		return $this->renderSummary($this->sectionsSummary(Hub::getInstance()->structure->getSections()));
	}

	public function actionDiagnostics(): Response
	{
		$hub = Hub::getInstance();
		$sections = $hub->structure->getSections();
		$path = 'hub/structure/sections';

		return $this->renderDiagnostics('structure', 'sections', Craft::t('hub', 'Sections'), $this->checkTasks(
			array_map(fn (SectionInfo $info) => $hub->checks->forSection($info), $sections),
			$path,
			[Craft::t('hub', 'Entries') => ['empty' => 1]],
		));
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$tab = $this->detailTab($tab, [self::TAB_ENTRIES]);
		$info = Hub::getInstance()->structure->getSection($id) ?? throw new NotFoundHttpException('Section not found.');

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/sections/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forSection($info),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/structure/sections/$id/entries", section: $info->section),
				self::TAB_DIAGNOSTICS => $this->reviewListVariables(sectionId: $id),
				default => $this->overviewVariables($info),
			},
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function overviewVariables(SectionInfo $info): array
	{
		$id = $info->section->id;
		$structure = Hub::getInstance()->structure;
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->getSiteIds();

		$typeCounts = [];
		$typeHandles = [];
		foreach ($info->entryTypes as $entryType) {
			$name = Craft::t('site', $entryType->name);
			$typeCounts[$name] = $statistics->sumEntries($siteIds, $id, $entryType->id)->total;
			$typeHandles[$name] = $entryType->handle;
		}

		$entryTypes = $this->topCard(Craft::t('hub', 'Entry Types'), 'shapes', $typeCounts);
		foreach ($entryTypes['segments'] as &$segment) {
			if (isset($typeHandles[$segment['label']])) {
				$segment['url'] = $this->hubUrl('hub/data/entries', ['section' => $info->section->handle, 'type' => $typeHandles[$segment['label']]]);
			}
		}
		unset($segment);

		$typesWithoutEntries = count(array_filter($typeCounts, fn (int $count) => $count === 0));
		if ($typesWithoutEntries > 0) {
			$entryTypes['note'] = Craft::t('hub', '{count, plural, =1{One entry type has} other{# entry types have}} no entries here', ['count' => $typesWithoutEntries]);
		}

		$counts = [];
		foreach ([...$info->referenceFields, ...$info->linkFields] as $field) {
			$counts[$field->name] = $statistics->sumReferences($siteIds, $field->id, $id)->references;
		}

		$references = $this->topCard(Craft::t('hub', 'References'), 'link', $counts, sum: true);
		$references['note'] = Craft::t('hub', '{entries, plural, =0{No Entries field} =1{One Entries field} other{# Entries fields}} and {links, plural, =0{no Link field} =1{one Link field} other{# Link fields}} can relate to entries from this section', [
			'entries' => count($info->referenceFields),
			'links' => count($info->linkFields),
		]);

		return [
			'summary' => [
				$this->entriesCard($info->stats),
				$entryTypes,
				$references,
			],
			'entryTypes' => $structure->getEntryTypes(),
			'statistics' => $statistics,
		];
	}

	/**
	 * Filters arrive as query params, like those of the other lists.
	 */
	private function filterFromRequest(): SectionFilter
	{
		$request = Craft::$app->getRequest();
		$urls = $request->getQueryParam('urls');

		$filter = new SectionFilter();
		$filter->search = trim((string)$request->getQueryParam('q', ''));
		$filter->labels = $this->labelsParam();
		$filter->types = $this->listParam('type', array_map(fn (SectionType $type) => $type->value, SectionType::cases()));
		$filter->urls = in_array($urls, [ListService::URLS_YES, ListService::URLS_NO], true) ? $urls : null;
		$filter->entriesMin = $this->intParam('entriesMin');
		$filter->entriesMax = $this->intParam('entriesMax');
		$filter->referencesMin = $this->intParam('referencesMin');
		$filter->referencesMax = $this->intParam('referencesMax');
		$filter->activities = $this->listParam('activity', EntryStatistics::getActivities());
		$filter->empty = $request->getQueryParam('empty') === '1';

		return $filter;
	}
}
