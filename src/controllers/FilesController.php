<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\Asset;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\Classifier;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FilesController extends BaseController
{
	protected ?Page $page = Page::Assets;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/data/assets')) !== null) {
			return $redirect;
		}

		return $this->renderTemplate('hub/pages/files/index', $this->fileListVariables('hub/data/assets'));
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Assets, $this->filesSummary());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::Assets);
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
			'canDeleteTransforms' => Hub::getInstance()->canAct(Page::Assets, Hub::PERMISSION_DELETE_TRANSFORMS) && Craft::$app->getElements()->canSave($asset),
			'canDownload' => Hub::getInstance()->canAct(Page::Assets, Hub::PERMISSION_DOWNLOAD_ASSETS),
			'checks' => Hub::getInstance()->checks->forFile($asset),
		]);
	}

	/**
	 * Sends a file as a download, streamed from its volume, so it works for files without a public URL too, recording
	 * it in the activity log, for users who may download assets.
	 */
	public function actionDownload(int $id): Response
	{
		$this->requirePermission(Hub::PERMISSION_DOWNLOAD_ASSETS);

		/** @var FileService $files */
		$files = Hub::getInstance()->files;

		$asset = $files->getFile($id) ?? throw new NotFoundHttpException('File not found.');

		if (!Craft::$app->getElements()->canView($asset)) {
			throw new ForbiddenHttpException('User is not authorized to download this file.');
		}

		Hub::getInstance()->activity->record(ActivityAction::Downloaded, ActivitySubject::Asset, $asset->id, $asset->getUiLabel(), siteId: $asset->siteId);

		return $this->response->sendStreamAsFile($asset->getStream(), $asset->getFilename(), [
			'fileSize' => $asset->size,
			'mimeType' => $asset->getMimeType(),
		]);
	}

	/**
	 * Deletes the selected transforms of a file, which Craft generates again when they're next requested, for users who may
	 * delete them.
	 */
	public function actionDeleteTransforms(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(Hub::PERMISSION_DELETE_TRANSFORMS);

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
	 * Previews deleting the selected transforms of a file, like deleting the transforms of the files of the list (see
	 * BulkController::actionPreview()), for users who may delete them.
	 */
	public function actionPreviewTransforms(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requirePermission(Hub::PERMISSION_DELETE_TRANSFORMS);

		$request = Craft::$app->getRequest();
		$ids = $request->getBodyParam('transformIds');
		$ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];

		return $this->transformsPreview([(int)$request->getRequiredBodyParam('assetId')], (int)$request->getBodyParam('page', 1), $ids);
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
