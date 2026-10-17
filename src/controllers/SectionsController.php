<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SectionsController extends BaseController
{
	protected ?Page $page = Page::Sections;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/structure/sections')) !== null) {
			return $redirect;
		}

		$hub = Hub::getInstance();
		/** @var ListService $lists */
		$lists = $hub->lists;
		$months = $hub->filters->getThreshold(CheckKind::Sections, 'stale');
		$all = $hub->structure->getSections();

		// While reviewing the selection, the filters don't apply.
		$selection = $this->selection(ColumnService::LIST_SECTIONS, 'hub/structure/sections');
		/** @var SectionFilter $filter */
		$filter = ($selection['review'] ?? false) ? new SectionFilter() : $this->listFilterFromRequest(CheckKind::Sections);
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
			...$this->savedFilterVariables(CheckKind::Sections, 'hub/structure/sections', $filter->savedFilter),
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
		return $this->renderDiagnostics(CheckKind::Sections);
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
				self::TAB_DIAGNOSTICS => [],
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
			if (isset($typeHandles[$segment['label']]) && Hub::getInstance()->canView(Page::Entries)) {
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
}
