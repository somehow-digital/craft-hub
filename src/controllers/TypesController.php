<?php

namespace SomehowDigital\Craft\Hub\controllers;

use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\helpers\ListSelection;
use SomehowDigital\Craft\Hub\helpers\ListSort;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\statistics\EntryTypesSummary;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The entry types page, below the "Structure" navigation item: its list, statistics and diagnostics, and the detail
 * pages of entry types.
 */
class TypesController extends BaseController
{
	protected ?Page $page = Page::EntryTypes;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/structure/entry-types')) !== null) {
			return $redirect;
		}

		$hub = Hub::getInstance();
		$lists = $hub->lists;
		$months = $hub->filters->getThreshold(CheckKind::EntryTypes, 'stale');
		$all = $hub->structure->getEntryTypes();

		// While reviewing the selection, the filters don't apply.
		$selection = ListSelection::fromRequest(ColumnService::LIST_ENTRY_TYPES, 'hub/structure/entry-types');
		/** @var EntryTypeFilter $filter */
		$filter = $selection->review ? new EntryTypeFilter() : $this->listFilterFromRequest(CheckKind::EntryTypes);
		$sort = ListSort::fromRequest($lists->getEntryTypeSortColumns(), ListService::SORT_NAME);
		[$pageInfo, $entryTypes] = Paginator::fromRequest()->items($selection->limitItems($lists->getEntryTypes($filter, $sort->column, $sort->direction)));
		$columns = $hub->columns->getColumns(ColumnService::LIST_ENTRY_TYPES);
		$shown = $hub->columns->getShownKeys($columns);

		return $this->renderTemplate('hub/pages/types/index', [
			'entryTypes' => $entryTypes,
			'pageInfo' => $pageInfo,
			'total' => count($all),
			'kinds' => UsageKind::listed(),
			'activities' => in_array('updated', $shown, true) ? array_map(fn (EntryTypeInfo $info) => $info->stats->getActivity($months), $entryTypes) : [],
			'filter' => $filter,
			'columns' => $columns,
			...$this->savedFilterVariables(CheckKind::EntryTypes, 'hub/structure/entry-types', $filter->savedFilter),
			...$this->labelVariables(ColumnService::LIST_ENTRY_TYPES, array_keys($entryTypes)),
			...$selection->variables($filter),
			...$sort->variables('hub/structure/entry-types'),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::EntryTypes, (new EntryTypesSummary())->index());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::EntryTypes);
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$info = Hub::getInstance()->structure->getEntryType($id) ?? throw new NotFoundHttpException('Entry type not found.');
		// Entry types used by no section or field have no entries to list.
		$tab = $this->detailTab($tab, [...($info->sections !== [] || $info->nestedFields !== [] ? [self::TAB_ENTRIES] : []), self::TAB_STATISTICS]);

		// The cards load their data once their panel is opened, so building them all is cheap (see renderCard()).
		$summary = $tab === self::TAB_STATISTICS ? (new EntryTypesSummary())->detail($info) : [];

		if ($tab === self::TAB_STATISTICS && ($card = $this->renderCard($summary)) !== null) {
			return $card;
		}

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/types/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forEntryType($info),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/structure/entry-types/$id/entries", entryType: $info->entryType),
				self::TAB_STATISTICS => ['summary' => $summary],
				self::TAB_DIAGNOSTICS => [],
				default => [
					'fields' => Hub::getInstance()->structure->getFields(),
					'statistics' => Hub::getInstance()->statistics,
				],
			},
		]);
	}
}
