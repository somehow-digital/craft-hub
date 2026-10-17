<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\models\Site;
use SomehowDigital\Craft\Hub\helpers\Range;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FileFilter;
use yii\base\Component;

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
	public const SORT_RELATIONS = 'relations';
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

		if ($filter->search !== '') {
			$query->andWhere([
				'or',
				['like', 'assets.filename', $filter->search],
				['like', 'elements_sites.title', $filter->search],
			]);
		}

		if ($filter->volumes !== []) {
			$query->volume($filter->volumes);
		}

		if ($filter->kinds !== []) {
			$query->kind($filter->kinds);
		}

		if ($filter->extensions !== []) {
			$operator = Craft::$app->getDb()->getIsPgsql() ? 'ilike' : 'like';
			$query->andWhere(['or', ...array_map(fn (string $extension) => [$operator, 'assets.filename', '%.' . $extension, false], $filter->extensions)]);
		}

		if ($filter->orientations !== []) {
			$query->andWhere(['or', ...array_map(fn (string $orientation) => match ($orientation) {
				FileFilter::ORIENTATION_LANDSCAPE => '[[assets.width]] > [[assets.height]]',
				FileFilter::ORIENTATION_PORTRAIT => '[[assets.width]] < [[assets.height]]',
				default => '[[assets.width]] = [[assets.height]]',
			}, $filter->orientations)]);
		}

		if ($filter->uploaders !== []) {
			$query->andWhere(['assets.uploaderId' => $filter->uploaders]);
		}

		if ($filter->usage === self::USAGE_USED || $filter->usage === self::USAGE_UNUSED) {
			$query->andWhere([$filter->usage === self::USAGE_USED ? 'exists' : 'not exists', $review->referencesQuery()]);
		}

		$megabytes = fn (?float $value) => $value !== null ? (int)round($value * 1024 * 1024) : null;

		$conditions = [
			Range::condition($review->referencesQuery()->select('COUNT(*)'), $filter->relationsMin, $filter->relationsMax),
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

		return $query;
	}

	/**
	 * Lowercased filename extensions of all files, with how many files have them, most used first.
	 *
	 * @return array<string,int> extension => count
	 */
	public function getExtensions(): array
	{
		$db = Craft::$app->getDb();

		// Everything after the last dot of the filename.
		$extension = $db->getIsPgsql()
			? "LOWER(SUBSTRING([[assets.filename]] FROM '\\.([^.]+)$'))"
			: "LOWER(SUBSTRING_INDEX([[assets.filename]], '.', -1))";

		$rows = $this->filesQuery()
			->select(['extension' => $extension, 'count' => 'COUNT(*)'])
			->andWhere(['like', 'assets.filename', '.'])
			->groupBy(['extension'])
			->orderBy(['count' => SORT_DESC, 'extension' => SORT_ASC])
			->all();

		return array_map('intval', array_column($rows, 'count', 'extension'));
	}

	/**
	 * Users who uploaded files, with how many files they uploaded, most uploads first.
	 *
	 * @return array<int,array{user:User,count:int}> keyed by user ID
	 */
	public function getUploaders(): array
	{
		$counts = $this->filesQuery()
			->select(['assets.uploaderId', 'count' => 'COUNT(*)'])
			->andWhere(['not', ['assets.uploaderId' => null]])
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
	 * How many files each value of a filter matches on its own, for the counts of its options.
	 *
	 * @param string[] $values
	 * @param callable(FileFilter,string):void $apply sets a value on the filter
	 * @return array<string,int> value => count
	 */
	public function getCounts(array $values, callable $apply): array
	{
		$counts = [];

		foreach ($values as $value) {
			$filter = new FileFilter();
			$apply($filter, $value);
			$counts[$value] = (int)$this->getQuery($filter)->count();
		}

		return $counts;
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
			self::SORT_RELATIONS => SORT_DESC,
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
	 * Image transforms generated for the file, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getTransforms(Asset $asset): array
	{
		return $this->getTransformsOf([$asset])[$asset->id] ?? [];
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
			->select(['assetId', 'transformString', 'transformer', 'filename', 'format', 'fileExists', 'inProgress', 'error', 'dateIndexed'])
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
				'fileExists' => (bool)$row['fileExists'],
				'inProgress' => (bool)$row['inProgress'],
				'error' => (bool)$row['error'],
				'dateIndexed' => $row['dateIndexed'] !== null ? DateTimeHelper::toDateTime($row['dateIndexed']) ?: null : null,
			];
		}

		return $grouped;
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
	 * Sorting by volume and relations uses subqueries, as the asset query joins neither.
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
			self::SORT_RELATIONS => ['(' . $review->referencesQuery()->select('COUNT(*)')->createCommand()->getRawSql() . ')'],
			default => ['elements.dateCreated'],
		};

		return [...array_fill_keys($columns, $direction), 'elements.id' => $direction];
	}
}
