<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\Template;
use craft\models\Volume;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FilesController extends BaseController
{
	public const PAGE_SIZE = 50;

	public function actionIndex(): Response
	{
		$request = Craft::$app->getRequest();
		$volumes = Hub::getInstance()->volumes;
		/** @var FileService $files */
		$files = Hub::getInstance()->files;

		$fileKinds = $volumes->getFileKinds();
		$volumeInfos = $volumes->getVolumes();
		$filter = $this->filterFromRequest(array_keys($fileKinds));

		$sort = (string)$request->getQueryParam('sort', '');
		$direction = (string)$request->getQueryParam('dir', '');

		// Columns sort in their natural direction unless `dir` says otherwise.
		$sortColumns = $files->getSortColumns();
		$sort = isset($sortColumns[$sort]) ? $sort : FileService::SORT_UPLOADED;
		$direction = match ($direction) {
			'asc' => SORT_ASC,
			'desc' => SORT_DESC,
			default => $sortColumns[$sort],
		};

		$query = $files->getQuery($filter, $sort, $direction)->limit(self::PAGE_SIZE);

		[$pageInfo, $assets] = Template::paginateQuery($query);

		$relationCounts = Hub::getInstance()->references->getRelationCounts(array_map(fn (Asset $asset) => $asset->id, $assets));

		$siteVariants = $files->getSiteVariantsOf($assets);
		$transforms = $files->getTransformsOf($assets);

		$reviews = [];
		foreach ($assets as $asset) {
			$reviews[$asset->id] = Hub::getInstance()->checks->forFile(
				$asset,
				$relationCounts[$asset->id] ?? 0,
				$siteVariants[$asset->id] ?? [],
				$transforms[$asset->id] ?? [],
			);
		}

		return $this->renderTemplate('hub/pages/files/index', [
			'assets' => $assets,
			'pageInfo' => $pageInfo,
			'relationCounts' => $relationCounts,
			'reviews' => $reviews,
			'fileKinds' => $fileKinds,
			'volumes' => $volumeInfos,
			'extensions' => $files->getExtensions(),
			'uploaders' => $files->getUploaders(),
			'counts' => [
				'orientations' => $files->getCounts(FileFilter::getOrientations(), fn (FileFilter $filter, string $orientation) => $filter->orientations = [$orientation]),
				'usage' => $files->getCounts([FileService::USAGE_USED, FileService::USAGE_UNUSED], fn (FileFilter $filter, string $usage) => $filter->usage = $usage),
			],
			'sortHeaders' => $this->sortHeaders('hub/assets/files', $sortColumns, FileService::SORT_UPLOADED, $sort, $direction),
			'filter' => $filter,
			// Kept as hidden fields, so changing a filter keeps the sorting.
			'sort' => $sort !== FileService::SORT_UPLOADED || $direction !== $sortColumns[$sort] ? $sort : null,
			'dir' => $direction !== $sortColumns[$sort] ? ($direction === SORT_ASC ? 'asc' : 'desc') : null,
			'summary' => $this->filesSummary($volumeInfos, $fileKinds),
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

	/**
	 * Filters arrive as query params, like the client-side filters of the other lists.
	 *
	 * @param string[] $kinds file kinds that can be filtered by
	 */
	private function filterFromRequest(array $kinds): FileFilter
	{
		$request = Craft::$app->getRequest();
		$usage = $request->getQueryParam('usage');

		$filter = new FileFilter();
		$filter->search = trim((string)$request->getQueryParam('q', ''));
		$filter->volumes = $this->listParam('volume', array_map(fn (Volume $volume) => $volume->handle, Craft::$app->getVolumes()->getAllVolumes()));
		$filter->kinds = $this->listParam('kind', $kinds);
		$filter->extensions = array_values(array_filter(array_map('mb_strtolower', $this->listParam('extension')), fn (string $extension) => preg_match('/^[a-z0-9]+$/', $extension) === 1));
		$filter->orientations = $this->listParam('orientation', FileFilter::getOrientations());
		$filter->uploaders = $this->idsParam('uploader');
		$filter->usage = in_array($usage, [FileService::USAGE_USED, FileService::USAGE_UNUSED], true) ? $usage : null;
		$filter->relationsMin = $this->intParam('relationsMin');
		$filter->relationsMax = $this->intParam('relationsMax');
		$filter->sizeMin = $this->numberParam('sizeMin');
		$filter->sizeMax = $this->numberParam('sizeMax');
		$filter->widthMin = $this->intParam('widthMin');
		$filter->widthMax = $this->intParam('widthMax');
		$filter->heightMin = $this->intParam('heightMin');
		$filter->heightMax = $this->intParam('heightMax');
		$filter->uploadedFrom = $this->dateParam('uploadedFrom');
		$filter->uploadedTo = $this->dateParam('uploadedTo');
		$filter->missingAlt = $request->getQueryParam('alt') === '1';

		return $filter;
	}
}
