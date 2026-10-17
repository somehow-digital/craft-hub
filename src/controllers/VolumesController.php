<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class VolumesController extends BaseController
{
	protected ?Page $page = Page::Volumes;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/structure/volumes')) !== null) {
			return $redirect;
		}

		$hub = Hub::getInstance();
		/** @var ListService $lists */
		$lists = $hub->lists;
		$fileKinds = $hub->volumes->getFileKinds();
		$all = $hub->volumes->getVolumes();

		// While reviewing the selection, the filters don't apply.
		$selection = $this->selection(ColumnService::LIST_VOLUMES, 'hub/structure/volumes');
		/** @var VolumeFilter $filter */
		$filter = ($selection['review'] ?? false) ? new VolumeFilter() : $this->listFilterFromRequest(CheckKind::Volumes);
		$sortColumns = $lists->getVolumeSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		[$pageInfo, $volumes] = $this->paginateItems($this->reviewItems($lists->getVolumes($filter, $sort, $direction), $selection));
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
			...$this->bulkVariables(ColumnService::LIST_VOLUMES, $selection, $filter),
			...$this->sortVariables('hub/structure/volumes', $sortColumns, ListService::SORT_NAME, $sort, $direction),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Volumes, $this->volumesSummary());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::Volumes);
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$tab = $this->detailTab($tab, [self::TAB_ASSETS, self::TAB_STATISTICS]);
		$info = Hub::getInstance()->volumes->getVolume($id) ?? throw new NotFoundHttpException('Volume not found.');

		if ($tab === self::TAB_STATISTICS && ($card = $this->renderCard($this->summaryCards($info))) !== null) {
			return $card;
		}

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/volumes/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forVolume($info),
			...match ($tab) {
				self::TAB_ASSETS => $this->fileListVariables("hub/structure/volumes/$id/assets", $info->volume),
				self::TAB_STATISTICS => ['summary' => $this->summaryCards($info)],
				self::TAB_DIAGNOSTICS => [],
				default => [
					'fileKinds' => Hub::getInstance()->volumes->getFileKinds(),
					'statistics' => Hub::getInstance()->statistics,
				],
			},
		]);
	}

	/**
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	private function summaryCards(VolumeInfo $info): array
	{
		$id = $info->volume->id;

		return [
			$this->lazyCard(Craft::t('hub', 'Assets'), 'image', fn () => $this->assetsCard($info->stats), $this->assetsHistory($id)),
			$this->lazyCard(Craft::t('hub', 'Types'), 'file', function () use ($info) {
				$fileKinds = Hub::getInstance()->volumes->getFileKinds();
				$kinds = [];
				$stats = [];
				foreach ($info->kinds as $kind => $kindStats) {
					$kinds[$fileKinds[$kind]->getLabel()] = $kindStats->total;
					$stats[$fileKinds[$kind]->getLabel()] = $kindStats;
				}

				return $this->withColumns($this->topCard(Craft::t('hub', 'Types'), 'file', $kinds), Craft::t('hub', 'Assets'), $this->assetColumns(), $this->assetCells($stats));
			}, $this->fileKindsHistory($id)),
			$this->lazyCard(Craft::t('hub', 'Alternative Text'), 'universal-access', fn () => $this->altCard($info->stats), $this->assetsHistory($id, fn (AssetStatistics $stats) => $this->altCard($stats))),
			$this->lazyCard(Craft::t('hub', 'References'), 'link', function () use ($info, $id) {
				$statistics = Hub::getInstance()->statistics;
				$siteIds = Hub::getInstance()->getSiteIds();
				$counts = [];
				foreach ([...$info->assetFields, ...$info->linkFields] as $field) {
					$counts[$field->name] = $statistics->sumReferences($siteIds, $field->id, volumeId: $id)->references;
				}

				$card = $this->topCard(Craft::t('hub', 'References'), 'link', $counts, sum: true);
				$card['note'] = Craft::t('hub', '{assets, plural, =0{No Assets field} =1{One Assets field} other{# Assets fields}} and {links, plural, =0{no Link field} =1{one Link field} other{# Link fields}} can relate to assets from this volume', [
					'assets' => count($info->assetFields),
					'links' => count($info->linkFields),
				]);

				return $card;
			}, fn (Snapshot $snapshot) => $this->labeledTopHistory($this->fieldLabels(), $this->byId(
				$snapshot->getVolumes()[$id]['fields'] ?? [],
				fn (int $fieldId) => $snapshot->statistics->sumReferences(Hub::getInstance()->getSiteIds(), $fieldId, volumeId: $id)->references,
			), true)),
			$this->lazyCard(Craft::t('hub', 'Uploaders'), 'user', fn () => $this->peopleCard(Craft::t('hub', 'Uploaders'), 'user', Hub::getInstance()->files->getUploaders($id), 'uploader', "hub/structure/volumes/$id/assets", Craft::t('hub', 'Assets'))),
		];
	}
}
