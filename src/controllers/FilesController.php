<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\Asset;
use craft\models\Volume;
use SomehowDigital\Craft\Hub\bulk\DeleteTransforms;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\Classifier;
use SomehowDigital\Craft\Hub\helpers\Ids;
use SomehowDigital\Craft\Hub\helpers\ListSelection;
use SomehowDigital\Craft\Hub\helpers\ListSort;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\TrashedFileFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\TrashService;
use SomehowDigital\Craft\Hub\statistics\FilesSummary;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The assets page, below the "Data" navigation item: its list, trash, statistics and diagnostics, and the detail pages
 * of assets, with their transforms.
 */
class FilesController extends BaseController
{
	protected ?Page $page = Page::Assets;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/data/assets')) !== null) {
			return $redirect;
		}

		return $this->renderTemplate('hub/pages/files/index', [
			...$this->fileListVariables('hub/data/assets'),
			'extraTabs' => $this->trashTabs(),
		]);
	}

	/**
	 * The files in the trash, to restore or delete them permanently.
	 */
	public function actionTrash(): Response
	{
		$hub = Hub::getInstance();
		$trash = $hub->trash;
		$path = 'hub/data/assets/trash';

		if (($redirect = $this->keepFilters($path)) !== null) {
			return $redirect;
		}

		// While reviewing the selection, the filters don't apply.
		$selection = ListSelection::fromRequest(ColumnService::LIST_TRASHED_FILES, $path);
		$filter = $selection->review ? new TrashedFileFilter() : $this->trashedFileFilterFromRequest();
		$sort = ListSort::fromRequest($trash->getFileSortColumns(), TrashService::SORT_DELETED);

		$query = $trash->getFileQuery($filter, $sort->column, $sort->direction);
		$selection->limitQuery($query);
		[$pageInfo, $results] = Paginator::fromRequest()->elements($query);
		$columns = $hub->columns->getColumns(ColumnService::LIST_TRASHED_FILES);

		return $this->renderTemplate('hub/pages/files/trash', [
			'assets' => $results,
			'pageInfo' => $pageInfo,
			'deleters' => in_array('deleter', $hub->columns->getShownKeys($columns), true)
				? $trash->getDeleters(ActivitySubject::Asset, array_map(fn (Asset $asset) => (int)$asset->id, $results))
				: [],
			'volumes' => Craft::$app->getVolumes()->getAllVolumes(),
			'fileKinds' => $hub->volumes->getAllKinds(),
			'kindOptions' => $this->showsFilterOptions($selection) ? $this->pickedKindOptions($filter->kinds) : [],
			'optionsUrls' => ['kind' => $this->filterOptionsUrl('kind')],
			'filter' => $filter,
			'filtered' => $filter != new TrashedFileFilter(),
			'listPath' => $path,
			'columns' => $columns,
			...$selection->variables($filter),
			'extraTabs' => $this->trashTabs(),
			...$sort->variables($path),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Assets, (new FilesSummary())->index(), extraTabs: $this->trashTabs());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::Assets, extraTabs: $this->trashTabs());
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$files = Hub::getInstance()->files;
		$references = Hub::getInstance()->references;

		$asset = $files->getFile($id) ?? throw new NotFoundHttpException('File not found.');
		$this->requireViewable($asset);
		// Images always have the tab, so their transforms are only read for it.
		$transforms = $asset->kind !== Asset::KIND_IMAGE || $tab === self::TAB_TRANSFORMS ? $files->getTransforms($asset) : [];
		$tab = $this->detailTab($tab, $this->hasTransforms($asset, $transforms) ? [self::TAB_TRANSFORMS] : []);

		return $this->renderTemplate('hub/pages/files/view', [
			'asset' => $asset,
			'selectedTab' => $tab,
			'fileKind' => new FileKindInfo($asset->kind),
			'transforms' => $transforms,
			...($tab === self::TAB_OVERVIEW ? [
				'references' => $references->getReferences($asset),
				'referenced' => $references->getReferenced($asset),
				'sites' => $files->getSiteVariants($asset),
			] : []),
			'hasTransforms' => $this->hasTransforms($asset, $transforms),
			'isPlayable' => $asset->kind === Asset::KIND_VIDEO && Classifier::isPlayableVideo($asset->getMimeType()),
			'canDeleteTransforms' => Hub::getInstance()->access->canAct(Page::Assets, Hub::PERMISSION_DELETE_TRANSFORMS) && Craft::$app->getElements()->canSave($asset),
			'canDownload' => Hub::getInstance()->access->canAct(Page::Assets, Hub::PERMISSION_DOWNLOAD_ASSETS),
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

		$files = Hub::getInstance()->files;

		$asset = $files->getFile($id) ?? throw new NotFoundHttpException('File not found.');

		$this->requireViewable($asset);

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
		$files = Hub::getInstance()->files;

		$id = (int)$request->getRequiredBodyParam('assetId');
		$asset = $files->getFile($id) ?? throw new NotFoundHttpException('File not found.');

		if (!Craft::$app->getElements()->canSave($asset)) {
			throw new ForbiddenHttpException('User is not authorized to delete the transforms of this file.');
		}

		$ids = $request->getBodyParam('transformIds');
		$ids = Ids::positive($ids);
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
		$ids = Ids::positive($ids);

		$preview = (new DeleteTransforms(ColumnService::LIST_FILES))->previewOf([(int)$request->getRequiredBodyParam('assetId')], (int)$request->getBodyParam('page', 1), $ids);

		return $this->renderPreview($preview, 1);
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

	private function trashedFileFilterFromRequest(): TrashedFileFilter
	{
		$params = $this->filterParamsOfRequest();

		$filter = new TrashedFileFilter();
		$filter->search = $params->search();
		$filter->volumes = $params->list('volume', array_map(fn (Volume $volume) => (string)$volume->handle, Craft::$app->getVolumes()->getAllVolumes()));
		$filter->kinds = $params->list('kind', array_map('strval', array_keys(Hub::getInstance()->volumes->getAllKinds())));
		$filter->file = $params->choice('file', [TrashService::FILE_KEPT, TrashService::FILE_DELETED]);
		$filter->deletedFrom = $params->date('deletedFrom');
		$filter->deletedTo = $params->date('deletedTo');

		return $filter;
	}
}
