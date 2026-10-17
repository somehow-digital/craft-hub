<?php

namespace SomehowDigital\Craft\Hub\controllers;

use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FilesController extends BaseController
{
	public function actionIndex(): Response
	{
		$volumes = Hub::getInstance()->volumes;

		return $this->renderTemplate('hub/pages/files/index', [
			...$this->fileListVariables('hub/assets/files'),
			'summary' => $this->filesSummary($volumes->getVolumes(), $volumes->getFileKinds()),
		]);
	}

	public function actionView(int $id): Response
	{
		/** @var FileService $files */
		$files = Hub::getInstance()->files;
		/** @var ReferenceService $references */
		$references = Hub::getInstance()->references;

		$asset = $files->getFile($id) ?? throw new NotFoundHttpException('File not found.');
		$fileKinds = Hub::getInstance()->volumes->getFileKinds();
		$relations = $references->getRelations($asset);
		$sites = $files->getSiteVariants($asset);
		$transforms = $files->getTransforms($asset);

		return $this->renderTemplate('hub/pages/files/view', [
			'asset' => $asset,
			'fileKind' => $fileKinds[$asset->kind] ?? new FileKindInfo($asset->kind),
			'relations' => $relations,
			'sites' => $sites,
			'transforms' => $transforms,
			'checks' => Hub::getInstance()->checks->forFile($asset, count($relations), $sites, $transforms),
		]);
	}
}
