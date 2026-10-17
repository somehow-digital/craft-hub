<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class VolumesController extends BaseController
{
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
		$filter = ($selection['review'] ?? false) ? new VolumeFilter() : $this->filterFromRequest(array_keys($fileKinds));
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
			...$this->labelVariables(ColumnService::LIST_VOLUMES, array_keys($volumes)),
			...$this->bulkVariables(ColumnService::LIST_VOLUMES, $selection, $filter),
			...$this->sortVariables('hub/structure/volumes', $sortColumns, ListService::SORT_NAME, $sort, $direction),
		]);
	}

	public function actionSummary(): Response
	{
		$volumes = Hub::getInstance()->volumes;

		return $this->renderSummary($this->volumesSummary($volumes->getVolumes(), $volumes->getFileKinds()));
	}

	public function actionDiagnostics(): Response
	{
		$hub = Hub::getInstance();
		$volumes = $hub->volumes->getVolumes();
		$path = 'hub/structure/volumes';

		return $this->renderDiagnostics('structure', 'volumes', Craft::t('hub', 'Volumes'), $this->checkTasks(
			array_map(fn (VolumeInfo $info) => $hub->checks->forVolume($info), $volumes),
			$path,
			[Craft::t('hub', 'Assets') => ['empty' => 1]],
		));
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$tab = $this->detailTab($tab, [self::TAB_ASSETS]);
		$info = Hub::getInstance()->volumes->getVolume($id) ?? throw new NotFoundHttpException('Volume not found.');

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/volumes/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forVolume($info),
			...match ($tab) {
				self::TAB_ASSETS => $this->fileListVariables("hub/structure/volumes/$id/assets", $info->volume),
				self::TAB_DIAGNOSTICS => [
					'fileKinds' => Hub::getInstance()->volumes->getFileKinds(),
					...$this->assetReviewListVariables($id),
				],
				default => $this->overviewVariables($info),
			},
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function overviewVariables(VolumeInfo $info): array
	{
		$id = $info->volume->id;
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->getSiteIds();

		$fileKinds = Hub::getInstance()->volumes->getFileKinds();
		$kinds = [];
		foreach ($info->kinds as $kind => $stats) {
			$kinds[$fileKinds[$kind]->getLabel()] = $stats->total;
		}

		$counts = [];
		foreach ([...$info->assetFields, ...$info->linkFields] as $field) {
			$counts[$field->name] = $statistics->sumReferences($siteIds, $field->id, volumeId: $id)->references;
		}

		$references = $this->topCard(Craft::t('hub', 'References'), 'link', $counts, sum: true);
		$references['note'] = Craft::t('hub', '{assets, plural, =0{No Assets field} =1{One Assets field} other{# Assets fields}} and {links, plural, =0{no Link field} =1{one Link field} other{# Link fields}} can relate to assets from this volume', [
			'assets' => count($info->assetFields),
			'links' => count($info->linkFields),
		]);

		return [
			'summary' => [
				$this->assetsCard($info->stats, Craft::t('hub', '{size} on average', [
					'size' => Craft::$app->getFormatter()->asShortSize($info->stats->getAverageSize(), 1),
				])),
				$this->topCard(Craft::t('hub', 'Types'), 'file', $kinds),
				$this->altCard($info->stats),
				$references,
			],
			'fileKinds' => $fileKinds,
			'statistics' => $statistics,
		];
	}

	/**
	 * Filters arrive as query params, like those of the other lists.
	 *
	 * @param string[] $kinds file kinds that can be filtered by
	 */
	private function filterFromRequest(array $kinds): VolumeFilter
	{
		$request = Craft::$app->getRequest();
		$urls = $request->getQueryParam('urls');

		$filter = new VolumeFilter();
		$filter->search = trim((string)$request->getQueryParam('q', ''));
		$filter->labels = $this->labelsParam();
		$filter->kinds = $this->listParam('kind', $kinds);
		$filter->urls = in_array($urls, [ListService::URLS_YES, ListService::URLS_NO], true) ? $urls : null;
		$filter->assetsMin = $this->intParam('assetsMin');
		$filter->assetsMax = $this->intParam('assetsMax');
		$filter->sizeMin = $this->numberParam('sizeMin');
		$filter->sizeMax = $this->numberParam('sizeMax');
		$filter->altMin = $this->intParam('altMin');
		$filter->altMax = $this->intParam('altMax');
		$filter->uploadedFrom = $this->dateParam('uploadedFrom');
		$filter->uploadedTo = $this->dateParam('uploadedTo');
		$filter->empty = $request->getQueryParam('empty') === '1';

		return $filter;
	}
}
