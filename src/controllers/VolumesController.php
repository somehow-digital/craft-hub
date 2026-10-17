<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class VolumesController extends BaseController
{
	public function actionIndex(): Response
	{
		$hub = Hub::getInstance();
		/** @var ListService $lists */
		$lists = $hub->lists;
		$fileKinds = $hub->volumes->getFileKinds();
		$all = $hub->volumes->getVolumes();

		$filter = $this->filterFromRequest(array_keys($fileKinds));
		$sortColumns = $lists->getVolumeSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		$volumes = $lists->getVolumes($filter, $sort, $direction);

		return $this->renderTemplate('hub/pages/volumes/index', [
			'volumes' => $volumes,
			'total' => count($all),
			'fileKinds' => $fileKinds,
			'diagnostics' => array_map(fn (VolumeInfo $info) => $hub->checks->forVolume($info), $volumes),
			'filter' => $filter,
			'counts' => [
				'kinds' => $lists->getVolumeCounts(array_map('strval', array_keys($fileKinds)), fn (VolumeFilter $filter, string $kind) => $filter->kinds = [$kind]),
				'urls' => $lists->getVolumeCounts([ListService::URLS_YES, ListService::URLS_NO], fn (VolumeFilter $filter, string $urls) => $filter->urls = $urls),
				'diagnoses' => $lists->getVolumeCounts(CheckService::getDiagnoses(), fn (VolumeFilter $filter, string $diagnosis) => $filter->diagnoses = [$diagnosis]),
			],
			...$this->sortVariables('hub/assets/volumes', $sortColumns, ListService::SORT_NAME, $sort, $direction),
			'summary' => $this->volumesSummary($all, $fileKinds),
		]);
	}

	public function actionView(int $id): Response
	{
		$service = Hub::getInstance()->volumes;
		$info = $service->getVolume($id) ?? throw new NotFoundHttpException('Volume not found.');
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->getSiteIds();

		$fileKinds = $service->getFileKinds();
		$kinds = [];
		foreach ($info->kinds as $kind => $stats) {
			$kinds[$fileKinds[$kind]->getLabel()] = $stats->total;
		}

		$relations = [];
		foreach ([...$info->assetFields, ...$info->linkFields] as $field) {
			$relations[$field->name] = $statistics->sumRelations($siteIds, $field->id, volumeId: $id)->relations;
		}

		$references = $this->topCard(Craft::t('hub', 'Relations'), 'link', $relations, sum: true);
		$references['note'] = Craft::t('hub', '{assets, plural, =0{No Assets field} =1{One Assets field} other{# Assets fields}} and {links, plural, =0{no Link field} =1{one Link field} other{# Link fields}} can relate to assets from this volume', [
			'assets' => count($info->assetFields),
			'links' => count($info->linkFields),
		]);

		return $this->renderTemplate('hub/pages/volumes/view', [
			...$this->fileListVariables('hub/assets/volumes/' . $id, $info->volume),
			'info' => $info,
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
			'checks' => Hub::getInstance()->checks->forVolume($info),
			...$this->assetReviewListVariables($id),
		]);
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
		$filter->diagnoses = $this->listParam('diagnosis', CheckService::getDiagnoses());

		return $filter;
	}
}
