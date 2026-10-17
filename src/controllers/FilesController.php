<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\Asset;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\helpers\Classifier;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FilesController extends BaseController
{
	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/data/assets')) !== null) {
			return $redirect;
		}

		return $this->renderTemplate('hub/pages/files/index', $this->fileListVariables('hub/data/assets'));
	}

	public function actionSummary(): Response
	{
		$volumes = Hub::getInstance()->volumes;

		return $this->renderSummary($this->filesSummary($volumes->getVolumes(), $volumes->getFileKinds()));
	}

	public function actionDiagnostics(): Response
	{
		$hub = Hub::getInstance();
		$settings = $hub->settings;
		$review = $hub->review;

		$assets = new AssetStatistics();
		foreach ($hub->volumes->getVolumes() as $info) {
			$assets->add($info->stats);
		}

		return $this->renderDiagnostics('data', 'assets', Craft::t('hub', 'Assets'), [
			[
				'label' => Craft::t('hub', 'Assets with no references'),
				'description' => Craft::t('hub', 'Not referenced through any relation field.'),
				'icon' => 'link-slash',
				'severity' => Severity::Warning,
				'value' => $assets->getUnused(),
				'url' => $this->hubUrl('hub/data/assets', ['usage' => FileService::USAGE_UNUSED]),
			],
			[
				'label' => Craft::t('hub', 'Missing alt text'),
				'description' => Craft::t('hub', 'Images without alternative text.'),
				'icon' => 'universal-access',
				'severity' => Severity::Warning,
				'value' => $assets->missingAlt,
				'url' => $this->hubUrl('hub/data/assets', ['alt' => 1]),
			],
			[
				'label' => Craft::t('hub', 'Large files'),
				'description' => Craft::t('hub', 'Larger than {size} MB.', ['size' => $settings->largeFileThresholdMb]),
				'icon' => 'weight-hanging',
				'severity' => Severity::Warning,
				'value' => $review->countLargeFiles(),
				'url' => $this->hubUrl('hub/data/assets', ['sizeMin' => $settings->largeFileThresholdMb, 'sort' => FileService::SORT_SIZE]),
			],
			[
				'label' => Craft::t('hub', 'Oversized images'),
				'description' => Craft::t('hub', 'Wider or taller than {pixels, number} px.', ['pixels' => $settings->largeImageThresholdPx]),
				'icon' => 'expand',
				'severity' => Severity::Notice,
				'value' => $review->countLargeImages(),
				'url' => $this->hubUrl('hub/data/assets', ['oversized' => 1, 'sort' => FileService::SORT_DIMENSIONS]),
			],
		]);
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		/** @var FileService $files */
		$files = Hub::getInstance()->files;
		/** @var ReferenceService $references */
		$references = Hub::getInstance()->references;

		$asset = $files->getFile($id) ?? throw new NotFoundHttpException('File not found.');
		$transforms = $files->getTransforms($asset);
		$tab = $this->detailTab($tab, $this->hasTransforms($asset, $transforms) ? [self::TAB_TRANSFORMS] : []);
		$fileKinds = Hub::getInstance()->volumes->getFileKinds();
		$referencing = $references->getReferences($asset);
		$sites = $files->getSiteVariants($asset);

		return $this->renderTemplate('hub/pages/files/view', [
			'asset' => $asset,
			'selectedTab' => $tab,
			'fileKind' => $fileKinds[$asset->kind] ?? new FileKindInfo($asset->kind),
			'references' => $referencing,
			'sites' => $sites,
			'transforms' => $transforms,
			'hasTransforms' => $this->hasTransforms($asset, $transforms),
			'isPlayable' => $asset->kind === Asset::KIND_VIDEO && Classifier::isPlayableVideo($asset->getMimeType()),
			'canDeleteTransforms' => Craft::$app->getElements()->canSave($asset),
			'checks' => Hub::getInstance()->checks->forFile($asset, count($referencing), $sites, $transforms),
		]);
	}

	/**
	 * Sends a file as a download, streamed from its volume, so it works for files without a public URL too.
	 */
	public function actionDownload(int $id): Response
	{
		/** @var FileService $files */
		$files = Hub::getInstance()->files;

		$asset = $files->getFile($id) ?? throw new NotFoundHttpException('File not found.');

		if (!Craft::$app->getElements()->canView($asset)) {
			throw new ForbiddenHttpException('User is not authorized to download this file.');
		}

		return $this->response->sendStreamAsFile($asset->getStream(), $asset->getFilename(), [
			'fileSize' => $asset->size,
			'mimeType' => $asset->getMimeType(),
		]);
	}

	/**
	 * Deletes the selected transforms of a file, which Craft generates again when they're next requested.
	 */
	public function actionDeleteTransforms(): ?Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		/** @var FileService $files */
		$files = Hub::getInstance()->files;

		$id = (int)$request->getRequiredBodyParam('assetId');
		$asset = $files->getFile($id) ?? throw new NotFoundHttpException('File not found.');

		if (!Craft::$app->getElements()->canSave($asset)) {
			throw new ForbiddenHttpException('User is not authorized to delete the transforms of this file.');
		}

		$ids = $request->getBodyParam('transformIds');
		$ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
		$count = $files->deleteTransforms($asset, $ids);

		return $this->asSuccess(
			Craft::t('hub', '{count, plural, =0{No transforms} =1{One transform} other{# transforms}} deleted.', ['count' => $count]),
			['count' => $count],
			$this->hubUrl("hub/data/assets/$id/" . self::TAB_TRANSFORMS),
		);
	}

	/**
	 * Images have a transforms tab, as do other files with transforms generated before they were replaced.
	 *
	 * @param array<int,array<string,mixed>> $transforms
	 */
	private function hasTransforms(Asset $asset, array $transforms): bool
	{
		return $asset->kind === Asset::KIND_IMAGE || $transforms !== [];
	}
}
