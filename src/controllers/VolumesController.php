<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class VolumesController extends BaseController
{
	public function actionIndex(): Response
	{
		$service = Hub::getInstance()->volumes;
		$fileKinds = $service->getFileKinds();
		$volumes = $service->getVolumes();

		return $this->renderTemplate('hub/pages/volumes/index', [
			'volumes' => $volumes,
			'fileKinds' => $fileKinds,
			'reviews' => array_map(fn (VolumeInfo $info) => Hub::getInstance()->checks->forVolume($info), $volumes),
			'summary' => $this->volumesSummary($volumes, $fileKinds),
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

		$references = $this->topCard(Craft::t('hub', 'Relations'), 'link', $relations);
		$references['note'] = Craft::t('hub', '{assets, plural, =0{No Assets field} =1{One Assets field} other{# Assets fields}} and {links, plural, =0{no Link field} =1{one Link field} other{# Link fields}} can relate to assets from this volume', [
			'assets' => count($info->assetFields),
			'links' => count($info->linkFields),
		]);

		return $this->renderTemplate('hub/pages/volumes/view', [
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
}
