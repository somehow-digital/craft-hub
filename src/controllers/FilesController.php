<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\controllers;

use Closure;
use Craft;
use craft\elements\Asset;
use craft\models\Volume;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\helpers\Classifier;
use SomehowDigital\Craft\Hub\helpers\Ids;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\TrashedFileFilter;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\TrashService;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The assets page, below the "Data" navigation item: its list, trash, statistics and diagnostics, and the detail pages
 * of assets, with their transforms.
 *
 * @internal
 */
final class FilesController extends BaseController
{
	protected ?Page $page = Page::Assets;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/data/assets')) instanceof Response) {
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

		if (($redirect = $this->keepFilters($path)) instanceof Response) {
			return $redirect;
		}

		// While reviewing the selection, the filters don't apply.
		$selection = Hub::getInstance()->bulkActions->selectionFor(ColumnService::LIST_TRASHED_FILES, $path);
		$filter = $selection->review ? new TrashedFileFilter() : $this->trashedFileFilterFromRequest();
		$sort = $this->listSort($trash->getFileSortColumns(), TrashService::SORT_DELETED);

		$query = $trash->getFileQuery($filter, $sort->column, $sort->direction);
		$selection->limitQuery($query);
		[$pageInfo, $results] = Hub::getInstance()->queries->paginate($this->paginator(), $query);
		$columns = $hub->columns->getColumns(ColumnService::LIST_TRASHED_FILES);

		return $this->renderTemplate('hub/pages/files/trash', [
			'list' => [
				'assets' => $results,
				'pageInfo' => $pageInfo,
				'deleters' => in_array('deleter', $hub->columns->getShownKeys($columns), true)
					? $trash->getDeleters(ActivitySubject::Asset, array_map(fn(Asset $asset): int => (int) $asset->id, $results))
					: [],
				'volumes' => Craft::$app->getVolumes()->getAllVolumes(),
				'fileKinds' => $hub->volumes->getAllKinds(),
				'kindOptions' => $this->showsFilterOptions($selection) ? $this->pickedKindOptions($filter->kinds) : [],
				'optionsUrls' => ['kind' => $this->filterOptionsUrl('kind')],
				'filter' => $filter,
				'filtered' => $filter->isFiltered(),
				'listPath' => $path,
				'columns' => $columns,
				...Hub::getInstance()->selection->variables($selection, $filter),
				...$sort->variables($path),
			],
			'extraTabs' => $this->trashTabs(),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Assets, Hub::getInstance()->filesSummary->index(), extraTabs: $this->trashTabs());
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
		$isPlayable = $asset->kind === Asset::KIND_VIDEO && Classifier::isPlayableVideo($asset->getMimeType());

		$panel = $this->renderPanel($tab === self::TAB_DIAGNOSTICS
			? $this->checksPanel(fn() => Hub::getInstance()->checks->forFile($asset))
			: $this->overviewPanels($asset, $isPlayable));

		if ($panel instanceof Response) {
			return $panel;
		}

		return $this->renderTemplate('hub/pages/files/view', [
			'asset' => $asset,
			'selectedTab' => $tab,
			'fileKind' => new FileKindInfo($asset->kind ?? ''),
			'transforms' => $transforms,
			...($tab === self::TAB_OVERVIEW ? [
				'referenceCount' => $references->countReferences($asset),
				'referencedCount' => $references->countReferenced($asset),
				'siteCount' => $files->countSiteVariants($asset),
			] : []),
			'hasTransforms' => $this->hasTransforms($asset, $transforms),
			'isPlayable' => $isPlayable,
			'canDeleteTransforms' => Hub::getInstance()->access->canAct(Page::Assets, Permission::DeleteTransforms->value) && Craft::$app->getElements()->canSave($asset),
			'canDownload' => Hub::getInstance()->access->canAct(Page::Assets, Permission::DownloadAssets->value),
			'checks' => Hub::getInstance()->checks->forFile($asset),
		]);
	}

	/**
	 * Sends a file as a download, streamed from its volume, so it works for files without a public URL too, recording
	 * it in the activity log, for users who may download assets.
	 */
	public function actionDownload(int $id): Response
	{
		$this->requirePermission(Permission::DownloadAssets->value);

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
		$this->requirePermission(Permission::DeleteTransforms->value);

		$request = $this->request;
		$files = Hub::getInstance()->files;

		$id = $this->requiredBodyInt('assetId');
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
			$this->hubUrl("hub/data/assets/{$id}/" . self::TAB_TRANSFORMS),
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
		$this->requirePermission(Permission::DeleteTransforms->value);

		$request = $this->request;
		$ids = $request->getBodyParam('transformIds');
		$ids = Ids::positive($ids);

		$preview = Hub::getInstance()->bulkActions->previewTransforms([$this->requiredBodyInt('assetId')], $this->bodyInt('page', 1), $ids);

		return $this->renderPreview($preview, 1);
	}

	/**
	 * Panels of the overview tab of a file page, with what each lists.
	 *
	 * @return array<string,Closure():array{0:string,1:array<string,mixed>}>
	 */
	private function overviewPanels(Asset $asset, bool $isPlayable): array
	{
		$hub = Hub::getInstance();

		return [
			'preview' => fn(): array => ['hub/_panels/files/preview', ['asset' => $asset, 'isImage' => $asset->kind === Asset::KIND_IMAGE, 'isPlayable' => $isPlayable]],
			'maintainers' => fn(): array => ['hub/_panels/element-maintainers', ['element' => $asset]],
			'sites' => fn(): array => ['hub/_panels/files/sites', ['sites' => $hub->files->getSiteVariants($asset), 'isImage' => $asset->kind === Asset::KIND_IMAGE]],
			'references' => fn(): array => ['hub/_panels/files/references', ['references' => $hub->references->getReferences($asset)]],
			'referenced' => fn(): array => ['hub/_panels/referenced', ['referenced' => $hub->references->getReferenced($asset)]],
			'activity' => fn(): array => ['hub/_panels/activity', ['subject' => ActivitySubject::Asset->value, 'id' => $asset->id]],
		];
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
		$filter->volumes = $params->list('volume', array_map(fn(Volume $volume): string => (string) $volume->handle, Craft::$app->getVolumes()->getAllVolumes()));
		$filter->kinds = $params->list('kind', array_map(strval(...), array_keys(Hub::getInstance()->volumes->getAllKinds())));
		$filter->file = $params->choice('file', [TrashService::FILE_KEPT, TrashService::FILE_DELETED]);
		$filter->deletedFrom = $params->date('deletedFrom');
		$filter->deletedTo = $params->date('deletedTo');

		return $filter;
	}
}
