<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\User;
use craft\errors\ImageTransformException;
use craft\helpers\DateTimeHelper;
use craft\helpers\ImageTransforms;
use craft\imagetransforms\ImageTransformer;
use craft\models\ImageTransform;
use craft\models\ImageTransformIndex;
use craft\models\Site;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\helpers\Range;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FileFilter;
use Throwable;
use yii\base\Component;
use yii\db\Expression;

/**
 * Lists the files of all volumes and collects the details shown for a single file.
 */
class FileService extends Component
{
	public const USAGE_USED = 'used';
	public const USAGE_UNUSED = 'unused';

	public const SORT_FILE = 'file';
	public const SORT_VOLUME = 'volume';
	public const SORT_KIND = 'kind';
	public const SORT_DIMENSIONS = 'dimensions';
	public const SORT_SIZE = 'size';
	public const SORT_REFERENCES = 'references';
	public const SORT_UPLOADED = 'uploaded';

	/**
	 * Files of all volumes on the counted sites, narrowed by the given filters.
	 *
	 * @param string $sort one of the columns of {@see getSortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 */
	public function getQuery(FileFilter $filter, string $sort = self::SORT_UPLOADED, int $direction = SORT_DESC): AssetQuery
	{
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;

		$query = Asset::find()
			->siteId(Hub::getInstance()->getSiteIds())
			->unique()
			->orderBy($this->sortOrder($sort, $direction));

		// PostgreSQL's `like` is case-sensitive, unlike MySQL's with Craft's collation.
		$operator = Craft::$app->getDb()->getIsPgsql() ? 'ilike' : 'like';

		if ($filter->search !== '') {
			$query->andWhere([
				'or',
				[$operator, 'assets.filename', $filter->search],
				[$operator, 'elements_sites.title', $filter->search],
			]);
		}

		if ($filter->labels !== []) {
			$query->andWhere(Hub::getInstance()->labels->condition(ColumnService::LIST_FILES, $filter->labels));
		}

		if ($filter->savedFilter !== null) {
			$query->andWhere(Hub::getInstance()->filters->elementCondition(CheckKind::Assets, $filter->savedFilter));
		}

		if ($filter->volumes !== []) {
			$query->volume($filter->volumes);
		}

		if ($filter->kinds !== []) {
			$query->kind($filter->kinds);
		}

		if ($filter->extensions !== []) {
			$query->andWhere(['or', ...array_map(fn (string $extension) => [$operator, 'assets.filename', '%.' . $extension, false], $filter->extensions)]);
		}

		if ($filter->orientations !== []) {
			$query->andWhere(['or', ...array_map(fn (string $orientation) => $this->orientationCondition($orientation), $filter->orientations)]);
		}

		if ($filter->uploaders !== []) {
			$query->andWhere(['assets.uploaderId' => $filter->uploaders]);
		}

		if ($filter->usage === self::USAGE_USED || $filter->usage === self::USAGE_UNUSED) {
			$query->andWhere($filter->usage === self::USAGE_USED ? $review->referencedCondition() : ['not', $review->referencedCondition()]);
		}

		$megabytes = fn (?float $value) => $value !== null ? (int)round($value * 1024 * 1024) : null;

		$conditions = [
			Range::condition(new Expression($review->referenceCountSql()), $filter->referencesMin, $filter->referencesMax),
			Range::condition('assets.size', $megabytes($filter->sizeMin), $megabytes($filter->sizeMax)),
			Range::condition('assets.width', $filter->widthMin, $filter->widthMax),
			Range::condition('assets.height', $filter->heightMin, $filter->heightMax),
		];

		foreach (array_filter($conditions) as $condition) {
			$query->andWhere($condition);
		}

		if (($uploaded = Range::dateParam($filter->uploadedFrom, $filter->uploadedTo)) !== null) {
			$query->dateCreated($uploaded);
		}

		if ($filter->missingAlt) {
			$query->andWhere($review->missingAltCondition());
		}

		if ($filter->oversized) {
			$query->andWhere($review->largeImageCondition());
		}

		return $query;
	}

	/**
	 * Lowercased filename extensions of all files, or of a volume's files, with how many files have them, most used first.
	 *
	 * @return array<string,int> extension => count
	 */
	public function getExtensions(?int $volumeId = null): array
	{
		$db = Craft::$app->getDb();

		// Everything after the last dot of the filename.
		$extension = $db->getIsPgsql()
			? "LOWER(SUBSTRING([[assets.filename]] FROM '\\.([^.]+)$'))"
			: "LOWER(SUBSTRING_INDEX([[assets.filename]], '.', -1))";

		$rows = $this->filesQuery()
			->select(['extension' => $extension, 'count' => 'COUNT(*)'])
			->andWhere(['like', 'assets.filename', '.'])
			->andFilterWhere(['assets.volumeId' => $volumeId])
			->groupBy(['extension'])
			->orderBy(['count' => SORT_DESC, 'extension' => SORT_ASC])
			->all();

		return array_map('intval', array_column($rows, 'count', 'extension'));
	}

	/**
	 * Users who uploaded files, or files to a volume, with how many files they uploaded, most uploads first.
	 *
	 * @return array<int,array{user:User,count:int}> keyed by user ID
	 */
	public function getUploaders(?int $volumeId = null): array
	{
		$counts = $this->filesQuery()
			->select(['assets.uploaderId', 'count' => 'COUNT(*)'])
			->andWhere(['not', ['assets.uploaderId' => null]])
			->andFilterWhere(['assets.volumeId' => $volumeId])
			->groupBy(['assets.uploaderId'])
			->orderBy(['count' => SORT_DESC])
			->pairs();

		if ($counts === []) {
			return [];
		}

		$users = User::find()->id(array_keys($counts))->status(null)->indexBy('id')->all();
		$uploaders = [];

		foreach ($counts as $id => $count) {
			if (isset($users[$id])) {
				$uploaders[(int)$id] = ['user' => $users[$id], 'count' => (int)$count];
			}
		}

		return $uploaders;
	}

	/**
	 * Columns the files can be sorted by, with the direction they sort in first: names ascending, numbers and dates descending.
	 *
	 * @return array<string,int> column => `SORT_ASC` or `SORT_DESC`
	 */
	public function getSortColumns(): array
	{
		return [
			self::SORT_FILE => SORT_ASC,
			self::SORT_VOLUME => SORT_ASC,
			self::SORT_KIND => SORT_ASC,
			self::SORT_DIMENSIONS => SORT_DESC,
			self::SORT_SIZE => SORT_DESC,
			self::SORT_REFERENCES => SORT_DESC,
			self::SORT_UPLOADED => SORT_DESC,
		];
	}

	/**
	 * The file in the first selected site, or the current one, or in the first site it exists in.
	 */
	public function getFile(int $id): ?Asset
	{
		$siteIds = array_map(fn (Site $site) => $site->id, Hub::getInstance()->getSites());

		return Asset::find()
			->id($id)
			->siteId(Hub::getInstance()->getSiteIds())
			->preferSites([...$siteIds, Craft::$app->getSites()->getCurrentSite()->id])
			->unique()
			->status(null)
			->one();
	}

	/**
	 * The file on each of the counted sites, to compare titles and alternative text.
	 *
	 * @return Asset[]
	 */
	public function getSiteVariants(Asset $asset): array
	{
		return $this->getSiteVariantsOf([$asset])[$asset->id] ?? [];
	}

	/**
	 * The files on each of the counted sites, loaded at once.
	 *
	 * @param Asset[] $assets
	 * @return array<int,Asset[]> keyed by asset ID
	 */
	public function getSiteVariantsOf(array $assets): array
	{
		if ($assets === []) {
			return [];
		}

		$variants = Asset::find()
			->id(array_map(fn (Asset $asset) => $asset->id, $assets))
			->siteId(Hub::getInstance()->getSiteIds())
			->status(null)
			->orderBy(['elements_sites.siteId' => SORT_ASC])
			->all();

		$grouped = [];
		foreach ($variants as $variant) {
			$grouped[$variant->id][] = $variant;
		}

		return $grouped;
	}

	/**
	 * Image transforms generated for the file, newest first, with the URL, dimensions and size of each generated one.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getTransforms(Asset $asset): array
	{
		return array_map(
			fn (array $transform) => [
				...$transform,
				'url' => $this->getTransformUrl($asset, $transform),
				...$this->getTransformDimensions($asset, $transform),
				'size' => $this->getTransformSize($asset, $transform),
			],
			$this->getTransformsOf([$asset])[$asset->id] ?? [],
		);
	}

	/**
	 * URL of a generated transform, or null while it isn't generated or no longer resolves to a transform.
	 *
	 * @param array<string,mixed> $transform
	 */
	public function getTransformUrl(Asset $asset, array $transform): ?string
	{
		if (!$transform['fileExists'] || $transform['error']) {
			return null;
		}

		$model = $this->getTransformModel($transform);

		if ($model === null) {
			return null;
		}

		// Named transforms are shared models, so the format is set on a copy
		$model = clone $model;
		$model->format = $transform['format'];

		return $asset->getUrl($model);
	}

	/**
	 * Dimensions the transform resizes the file to, worked out from the file's own dimensions like Craft does.
	 *
	 * @param array<string,mixed> $transform
	 * @return array{width:?int,height:?int}
	 */
	public function getTransformDimensions(Asset $asset, array $transform): array
	{
		$model = $this->getTransformModel($transform);

		if ($model === null) {
			return ['width' => null, 'height' => null];
		}

		return ['width' => $asset->getWidth($model), 'height' => $asset->getHeight($model)];
	}

	/**
	 * Size in bytes of a transformed image Craft's transformer generated, or null while there's none or it can't be read.
	 * Other transformers, like those of image services, keep no files of their own to measure.
	 *
	 * @param array<string,mixed> $transform
	 */
	public function getTransformSize(Asset $asset, array $transform): ?int
	{
		if (!$transform['fileExists'] || $transform['error'] || !is_a((string)$transform['transformer'], ImageTransformer::class, true)) {
			return null;
		}

		$index = new ImageTransformIndex([
			'transformString' => $transform['transformString'],
			'filename' => $transform['filename'],
		]);

		// Craft keeps the path of a transformed image to its transformer, so it is asked for through a subclass
		$transformer = new class() extends ImageTransformer {
			public function getPath(Asset $asset, ImageTransformIndex $index): string
			{
				return $this->getTransformBasePath($asset) . $this->getTransformSubpath($asset, $index);
			}
		};

		try {
			return $asset->getVolume()->getTransformFs()->getFileSize($transformer->getPath($asset, $index));
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Transform model of a generated transform, or null when its transform string no longer resolves to one.
	 *
	 * @param array<string,mixed> $transform
	 */
	private function getTransformModel(array $transform): ?ImageTransform
	{
		try {
			return ImageTransforms::normalizeTransform($transform['transformString']);
		} catch (ImageTransformException) {
			return null;
		}
	}

	/**
	 * Files with the IDs that the current user may save, along with the transforms deleted of each: all of them, or only
	 * those with the transform IDs, as picked on the page of a file. Files without any of them are left out.
	 *
	 * @param int[] $ids
	 * @param int[]|null $transformIds
	 * @return array{0:Asset[],1:array<int,array<int,array<string,mixed>>>} the files, and their transforms by their ID
	 */
	public function getDeletableTransforms(array $ids, ?array $transformIds = null): array
	{
		$service = Craft::$app->getElements();
		$assets = $ids !== [] ? Asset::find()->id($ids)->site('*')->unique()->all() : [];
		$transforms = array_map(
			fn (array $own) => $transformIds === null ? $own : array_values(array_filter($own, fn (array $transform) => in_array($transform['id'], $transformIds, true))),
			$this->getTransformsOf($assets),
		);
		$assets = array_values(array_filter($assets, fn (Asset $asset) => ($transforms[$asset->id] ?? []) !== [] && $service->canSave($asset)));

		return [$assets, array_intersect_key($transforms, array_flip(array_map(fn (Asset $asset) => (int)$asset->id, $assets)))];
	}

	/**
	 * Image transforms generated for the files, newest first, loaded at once.
	 *
	 * @param Asset[] $assets
	 * @return array<int,array<int,array<string,mixed>>> keyed by asset ID
	 */
	public function getTransformsOf(array $assets): array
	{
		if ($assets === []) {
			return [];
		}

		$rows = (new Query())
			->select(['id', 'assetId', 'transformString', 'transformer', 'filename', 'format', 'fileExists', 'inProgress', 'error', 'dateIndexed'])
			->from([Table::IMAGETRANSFORMINDEX])
			->where(['assetId' => array_map(fn (Asset $asset) => $asset->id, $assets)])
			->orderBy(['dateIndexed' => SORT_DESC])
			->all();

		$grouped = [];
		foreach ($rows as $row) {
			$assetId = (int)$row['assetId'];
			unset($row['assetId']);

			$grouped[$assetId][] = [
				...$row,
				'id' => (int)$row['id'],
				'fileExists' => (bool)$row['fileExists'],
				'inProgress' => (bool)$row['inProgress'],
				'error' => (bool)$row['error'],
				// Without a requested format Craft picks one and names the file after it
				'outputFormat' => $row['format'] ?: (strtolower(pathinfo((string)$row['filename'], PATHINFO_EXTENSION)) ?: null),
				'dateIndexed' => $row['dateIndexed'] !== null ? DateTimeHelper::toDateTime($row['dateIndexed']) ?: null : null,
			];
		}

		return $grouped;
	}

	/**
	 * Deletes generated transforms of the file, along with the transformed images Craft's transformer created.
	 * Transforms of other files are left alone, so IDs passed along with a file can't delete those. Craft tells of no
	 * transforms deleted this way, so they're recorded in the activity log here.
	 *
	 * @param int[] $ids
	 * @return int number of transforms deleted
	 */
	public function deleteTransforms(Asset $asset, array $ids): int
	{
		if ($ids === []) {
			return 0;
		}

		$rows = (new Query())
			->select(['id', 'assetId', 'transformer', 'filename', 'format', 'transformString', 'fileExists', 'inProgress', 'error'])
			->from([Table::IMAGETRANSFORMINDEX])
			->where(['id' => $ids, 'assetId' => $asset->id])
			->all();

		if ($rows === []) {
			return 0;
		}

		$transforms = Craft::$app->getImageTransforms();

		foreach ($rows as $row) {
			$index = new ImageTransformIndex([
				...$row,
				'fileExists' => (bool)$row['fileExists'],
				'inProgress' => (bool)$row['inProgress'],
				'error' => (bool)$row['error'],
			]);

			// Other transformers, like those of image services, keep no files of their own to delete.
			if ($index->fileExists && is_a($index->transformer, ImageTransformer::class, true)) {
				/** @var ImageTransformer $transformer */
				$transformer = $transforms->getImageTransformer($index->transformer);
				$transformer->deleteImageTransformFile($asset, $index);
			}
		}

		Craft::$app->getDb()->createCommand()
			->delete(Table::IMAGETRANSFORMINDEX, ['id' => array_map('intval', array_column($rows, 'id'))])
			->execute();

		/** @var ActivityService $activity */
		$activity = Hub::getInstance()->activity;
		$activity->record(
			ActivityAction::TransformsDeleted,
			ActivitySubject::Asset,
			$asset->id,
			$asset->getUiLabel(),
			['transforms' => array_values(array_unique(array_column($rows, 'transformString')))],
			$asset->siteId,
		);

		return count($rows);
	}

	/**
	 * Condition matching files of the orientation; files without dimensions match none.
	 */
	private function orientationCondition(string $orientation): string
	{
		return match ($orientation) {
			FileFilter::ORIENTATION_LANDSCAPE => '[[assets.width]] > [[assets.height]]',
			FileFilter::ORIENTATION_PORTRAIT => '[[assets.width]] < [[assets.height]]',
			default => '[[assets.width]] = [[assets.height]]',
		};
	}

	/**
	 * Files that aren't deleted, regardless of sites, to aggregate their columns.
	 */
	private function filesQuery(): Query
	{
		return (new Query())
			->from(['assets' => Table::ASSETS])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[assets.id]]')
			->where(['elements.dateDeleted' => null]);
	}

	/**
	 * Sorting by volume and references uses subqueries, as the asset query joins neither.
	 *
	 * @return array<string,int>
	 */
	private function sortOrder(string $sort, int $direction): array
	{
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;

		$columns = match ($sort) {
			self::SORT_FILE => ['assets.filename'],
			self::SORT_VOLUME => ['(SELECT [[hubVolumes.name]] FROM ' . Table::VOLUMES . ' [[hubVolumes]] WHERE [[hubVolumes.id]] = [[assets.volumeId]])'],
			self::SORT_KIND => ['assets.kind'],
			self::SORT_DIMENSIONS => ['assets.width', 'assets.height'],
			self::SORT_SIZE => ['assets.size'],
			self::SORT_REFERENCES => [$review->referenceCountSql()],
			default => ['elements.dateCreated'],
		};

		return [...array_fill_keys($columns, $direction), 'elements.id' => $direction];
	}
}
