<?php

namespace SomehowDigital\Craft\Hub\controllers;

use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\ListSelection;
use SomehowDigital\Craft\Hub\helpers\ListSort;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\statistics\VolumesSummary;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The volumes page, below the "Structure" navigation item: its list, statistics and diagnostics, and the detail pages
 * of volumes.
 */
class VolumesController extends BaseController
{
	protected ?Page $page = Page::Volumes;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/structure/volumes')) !== null) {
			return $redirect;
		}

		$hub = Hub::getInstance();
		$lists = $hub->lists;
		$fileKinds = $hub->volumes->getFileKinds();
		$all = $hub->volumes->getVolumes();

		// While reviewing the selection, the filters don't apply.
		$selection = ListSelection::fromRequest(ColumnService::LIST_VOLUMES, 'hub/structure/volumes');
		/** @var VolumeFilter $filter */
		$filter = $selection->review ? new VolumeFilter() : $this->listFilterFromRequest(CheckKind::Volumes);
		$sort = ListSort::fromRequest($lists->getVolumeSortColumns(), ListService::SORT_NAME);
		[$pageInfo, $volumes] = Paginator::fromRequest()->items($selection->limitItems($lists->getVolumes($filter, $sort->column, $sort->direction)));
		$columns = $hub->columns->getColumns(ColumnService::LIST_VOLUMES);

		return $this->renderTemplate('hub/pages/volumes/index', [
			'volumes' => $volumes,
			'pageInfo' => $pageInfo,
			'total' => count($all),
			'fileKinds' => $fileKinds,
			'filter' => $filter,
			'columns' => $columns,
			...$this->savedFilterVariables(CheckKind::Volumes, 'hub/structure/volumes', $filter->savedFilter),
			...$this->labelVariables(ColumnService::LIST_VOLUMES, array_keys($volumes)),
			...$selection->variables($filter),
			...$sort->variables('hub/structure/volumes'),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Volumes, (new VolumesSummary())->index());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::Volumes);
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$tab = $this->detailTab($tab, [self::TAB_ASSETS, self::TAB_STATISTICS]);
		$info = Hub::getInstance()->volumes->getVolume($id) ?? throw new NotFoundHttpException('Volume not found.');

		// The cards load their data once their panel is opened, so building them all is cheap (see renderCard()).
		$summary = $tab === self::TAB_STATISTICS ? (new VolumesSummary())->detail($info) : [];

		if ($tab === self::TAB_STATISTICS && ($card = $this->renderCard($summary)) !== null) {
			return $card;
		}

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/volumes/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forVolume($info),
			...match ($tab) {
				self::TAB_ASSETS => $this->fileListVariables("hub/structure/volumes/$id/assets", $info->volume),
				self::TAB_STATISTICS => ['summary' => $summary],
				self::TAB_DIAGNOSTICS => [],
				default => [
					'fileKinds' => Hub::getInstance()->volumes->getFileKinds(),
					'fields' => Hub::getInstance()->structure->getFields(),
					'statistics' => Hub::getInstance()->statistics,
				],
			},
		]);
	}
}
