<?php

namespace SomehowDigital\Craft\Hub\controllers;

use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\ListSelection;
use SomehowDigital\Craft\Hub\helpers\ListSort;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\statistics\SectionsSummary;
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
		$selection = ListSelection::fromRequest(ColumnService::LIST_SECTIONS, 'hub/structure/sections');
		/** @var SectionFilter $filter */
		$filter = $selection->review ? new SectionFilter() : $this->listFilterFromRequest(CheckKind::Sections);
		$sort = ListSort::fromRequest($lists->getSectionSortColumns(), ListService::SORT_NAME);
		[$pageInfo, $sections] = Paginator::fromRequest()->items($selection->limitItems($lists->getSections($filter, $sort->column, $sort->direction)));
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
			...$selection->variables($filter),
			...$sort->variables('hub/structure/sections'),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Sections, (new SectionsSummary())->index());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::Sections);
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$tab = $this->detailTab($tab, [self::TAB_ENTRIES, self::TAB_STATISTICS]);
		$info = Hub::getInstance()->structure->getSection($id) ?? throw new NotFoundHttpException('Section not found.');

		// The cards load their data once their panel is opened, so building them all is cheap (see renderCard()).
		$summary = $tab === self::TAB_STATISTICS ? (new SectionsSummary())->detail($info) : [];

		if ($tab === self::TAB_STATISTICS && ($card = $this->renderCard($summary)) !== null) {
			return $card;
		}

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/sections/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forSection($info),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/structure/sections/$id/entries", section: $info->section),
				self::TAB_STATISTICS => ['summary' => $summary],
				self::TAB_DIAGNOSTICS => [],
				default => [
					'entryTypes' => Hub::getInstance()->structure->getEntryTypes(),
					'statistics' => Hub::getInstance()->statistics,
				],
			},
		]);
	}
}
