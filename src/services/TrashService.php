<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\helpers\Range;
use SomehowDigital\Craft\Hub\helpers\Sql;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\TrashedEntryFilter;
use SomehowDigital\Craft\Hub\models\TrashedFileFilter;
use Throwable;
use yii\base\Component;

/**
 * Lists the entries and files in the trash, from which they can be restored or deleted permanently (see BulkService).
 *
 * Like Craft's element indexes, entries deleted along with their owner are left out, as they're restored with it, and so
 * are files deleted along with their volume. Files are only restored if their file was kept when they were deleted.
 * Who deleted the items is looked up in the activity log, as Craft doesn't keep it.
 *
 * While Settings::$keepTrashedFiles is on, the files of assets moved to the trash are kept, by whatever deletes them (see
 * TrashTracker), and recorded by their volume and path. Craft's garbage collection deletes trashed assets permanently
 * without deleting their files, so the files recorded are deleted once their assets are gone, see deletePurgedFiles().
 */
class TrashService extends Component
{
	/** files kept of trashed assets, by the asset's ID, with its volume and path */
	public const TABLE_KEPT_FILES = '{{%hub_kept_files}}';

	public const SORT_TITLE = 'title';
	public const SORT_FILE = 'file';
	public const SORT_SIZE = 'size';
	public const SORT_DELETED = 'deleted';

	public const FILE_KEPT = 'kept';
	public const FILE_DELETED = 'deleted';

	private ?bool $keptFilesTableExists = null;

	/**
	 * Trashed entries of all sections and nested entry fields on the counted sites, narrowed by the given filters.
	 *
	 * @param string $sort one of the columns of {@see getEntrySortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 */
	public function getEntryQuery(TrashedEntryFilter $filter, string $sort = self::SORT_DELETED, int $direction = SORT_DESC): EntryQuery
	{
		$entries = Hub::getInstance()->entries;

		$query = Entry::find()
			->siteId(Hub::getInstance()->siteScope->getSiteIds())
			->unique()
			->status(null)
			->trashed()
			->andWhere($entries->contentCondition())
			->andWhere(['or', ['elements.deletedWithOwner' => null], ['elements.deletedWithOwner' => false]])
			->orderBy($this->sortOrder($sort === self::SORT_TITLE ? ['elements_sites.title'] : ['elements.dateDeleted'], $direction));

		if (($viewable = $entries->viewableCondition()) !== null) {
			$query->andWhere($viewable);
		}

		if ($filter->search !== '') {
			$operator = Sql::likeOperator();
			$query->andWhere([
				'or',
				[$operator, 'elements_sites.title', $filter->search],
				[$operator, 'elements_sites.slug', $filter->search],
			]);
		}

		if (($origin = $entries->originCondition($filter->sections, $filter->fields)) !== null) {
			$query->andWhere($origin);
		}

		if ($filter->types !== []) {
			$query->type($filter->types);
		}

		if (($deleted = Range::dateParam($filter->deletedFrom, $filter->deletedTo)) !== null) {
			$query->andWhere(Db::parseDateParam('elements.dateDeleted', $deleted));
		}

		return $query;
	}

	/**
	 * Trashed files of all volumes on the counted sites, narrowed by the given filters.
	 *
	 * @param string $sort one of the columns of {@see getFileSortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 */
	public function getFileQuery(TrashedFileFilter $filter, string $sort = self::SORT_DELETED, int $direction = SORT_DESC): AssetQuery
	{
		$query = Asset::find()
			->siteId(Hub::getInstance()->siteScope->getSiteIds())
			->unique()
			->trashed()
			->andWhere(['or', ['assets.deletedWithVolume' => null], ['assets.deletedWithVolume' => false]])
			->orderBy($this->sortOrder(match ($sort) {
				self::SORT_FILE => ['assets.filename'],
				self::SORT_SIZE => ['assets.size'],
				default => ['elements.dateDeleted'],
			}, $direction));

		if (($viewable = Hub::getInstance()->files->viewableCondition()) !== null) {
			$query->andWhere($viewable);
		}

		if ($filter->search !== '') {
			$operator = Sql::likeOperator();
			$query->andWhere([
				'or',
				[$operator, 'assets.filename', $filter->search],
				[$operator, 'elements_sites.title', $filter->search],
			]);
		}

		if ($filter->volumes !== []) {
			$query->volume($filter->volumes);
		}

		if ($filter->kinds !== []) {
			$query->kind($filter->kinds);
		}

		if ($filter->file === self::FILE_KEPT || $filter->file === self::FILE_DELETED) {
			$query->andWhere($filter->file === self::FILE_KEPT ? ['assets.keptFile' => true] : ['or', ['assets.keptFile' => null], ['assets.keptFile' => false]]);
		}

		if (($deleted = Range::dateParam($filter->deletedFrom, $filter->deletedTo)) !== null) {
			$query->andWhere(Db::parseDateParam('elements.dateDeleted', $deleted));
		}

		return $query;
	}

	/**
	 * Trashed entries and files related to each of the trashed items, restored along with them: those the items relate to
	 * by relation fields, like Entries and Assets fields, and those relating to them, by their nested entries too, as Craft
	 * keeps the relations of trashed elements. Like the trash lists them, entries deleted along with their owner are left
	 * out, as they're restored with it, and so are files deleted along with their volume; files whose file wasn't kept are
	 * left out too, as they can't be restored. So are the items themselves.
	 *
	 * @param int[] $ids
	 * @return array<int,array<int,Entry|Asset>> keyed by the item's ID, then the related element's ID
	 */
	public function getRelatedTrashed(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		// The items and their nested entries, by the item they belong to.
		$members = array_fill_keys($ids, null);
		foreach ($this->owners(array_keys($members), 'ownerId') as $nestedId => $ownerIds) {
			$members[$nestedId] ??= $ownerIds[0];
		}

		$related = [];
		$rows = (new Query())
			->select(['sourceId', 'targetId'])
			->from([Table::RELATIONS])
			->where(['or', ['sourceId' => array_keys($members)], ['targetId' => array_keys($members)]])
			->all();

		foreach ($rows as $row) {
			foreach ([['sourceId', 'targetId'], ['targetId', 'sourceId']] as [$own, $other]) {
				$id = (int)$row[$own];

				if (array_key_exists($id, $members)) {
					$related[(int)$row[$other]][$members[$id] ?? $id] = true;
				}
			}
		}

		// Nested entries relate on behalf of their owners, which are restored along with them.
		foreach ($this->owners(array_keys($related), 'elementId') as $nestedId => $ownerIds) {
			foreach ($ownerIds as $ownerId) {
				$related[$ownerId] = ($related[$ownerId] ?? []) + $related[$nestedId];
			}
		}

		$related = array_diff_key($related, array_flip($ids));

		if ($related === []) {
			return [];
		}

		$elements = [
			...Hub::getInstance()->siteScope->inSiteScope(Entry::find())
				->id(array_keys($related))
				->status(null)
				->trashed()
				->andWhere(['or', ['elements.deletedWithOwner' => null], ['elements.deletedWithOwner' => false]])
				->all(),
			...Hub::getInstance()->siteScope->inSiteScope(Asset::find())
				->id(array_keys($related))
				->trashed()
				->andWhere(['assets.keptFile' => true])
				->andWhere(['or', ['assets.deletedWithVolume' => null], ['assets.deletedWithVolume' => false]])
				->all(),
		];

		$byItem = [];
		foreach ($elements as $element) {
			foreach (array_keys($related[$element->id]) as $itemId) {
				$byItem[$itemId][(int)$element->id] = $element;
			}
		}

		return $byItem;
	}

	/**
	 * Owners of nested entries, by the nested entry's ID, of the elements with the IDs: the nested entries they own
	 * (`ownerId`), or the owners of those that are nested entries (`elementId`).
	 *
	 * @param int[] $ids
	 * @return array<int,int[]>
	 */
	private function owners(array $ids, string $column): array
	{
		if ($ids === []) {
			return [];
		}

		$owners = [];
		$rows = (new Query())->select(['elementId', 'ownerId'])->from([Table::ELEMENTS_OWNERS])->where([$column => $ids])->all();

		foreach ($rows as $row) {
			$owners[(int)$row['elementId']][] = (int)$row['ownerId'];
		}

		return $owners;
	}

	/**
	 * Columns the trashed entries can be sorted by, with the direction they sort in first.
	 *
	 * @return array<string,int> column => `SORT_ASC` or `SORT_DESC`
	 */
	public function getEntrySortColumns(): array
	{
		return [
			self::SORT_TITLE => SORT_ASC,
			self::SORT_DELETED => SORT_DESC,
		];
	}

	/**
	 * Columns the trashed files can be sorted by, with the direction they sort in first.
	 *
	 * @return array<string,int> column => `SORT_ASC` or `SORT_DESC`
	 */
	public function getFileSortColumns(): array
	{
		return [
			self::SORT_FILE => SORT_ASC,
			self::SORT_SIZE => SORT_DESC,
			self::SORT_DELETED => SORT_DESC,
		];
	}

	/**
	 * Who last deleted the elements with the IDs, as recorded in the activity log: the user, if they still exist, and
	 * their name as it was recorded. Elements deleted while the log was disabled, or whose deletion has expired from it,
	 * are left out.
	 *
	 * @param int[] $ids
	 * @return array<int,array{user:User|null,name:string|null}> by element ID
	 */
	public function getDeleters(ActivitySubject $subject, array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		// The last deletion of each element, which is the one that moved it to the trash.
		$latest = [];
		foreach ((new Query())
			->select(['subjectId', 'userId', 'userName'])
			->from(ActivityService::TABLE)
			->where(['id' => (new Query())
				->select('MAX([[id]])')
				->from(ActivityService::TABLE)
				->where(['action' => ActivityAction::Deleted->value, 'subject' => $subject->value, 'subjectId' => $ids])
				->groupBy(['subjectId'])])
			->all() as $row) {
			$latest[(int)$row['subjectId']] = $row;
		}

		$userIds = array_values(array_unique(array_filter(array_map(fn (array $row) => $row['userId'] !== null ? (int)$row['userId'] : null, $latest))));
		$users = Hub::getInstance()->users->getUsers($userIds);

		return array_map(fn (array $row) => [
			'user' => $row['userId'] !== null ? $users[(int)$row['userId']] ?? null : null,
			'name' => $row['userName'] !== null ? (string)$row['userName'] : null,
		], $latest);
	}

	/**
	 * Records the file kept of an asset moved to the trash, to delete it once the asset is deleted permanently.
	 */
	public function recordKeptFile(Asset $asset): void
	{
		if (!$this->keptFilesTableExists()) {
			return;
		}

		Db::upsert(self::TABLE_KEPT_FILES, [
			'assetId' => $asset->id,
			'volumeId' => $asset->volumeId,
			'path' => $asset->getPath(),
			'dateCreated' => Db::prepareDateForDb(new DateTime()),
		]);
	}

	/**
	 * Forgets the file kept of an asset, as it's restored, or deleted permanently along with its file.
	 */
	public function forgetKeptFile(int $assetId): void
	{
		if ($this->keptFilesTableExists()) {
			Db::delete(self::TABLE_KEPT_FILES, ['assetId' => $assetId]);
		}
	}

	/**
	 * Deletes the files kept of trashed assets that Craft's garbage collection has since deleted permanently, unless
	 * another asset, live or trashed, has taken their place since; files of volumes that no longer exist are left as
	 * they are, as Craft keeps the files of deleted volumes too.
	 *
	 * @return int number of files deleted
	 */
	public function deletePurgedFiles(): int
	{
		if (!$this->keptFilesTableExists()) {
			return 0;
		}

		$rows = (new Query())
			->select(['kept.assetId', 'kept.volumeId', 'kept.path'])
			->from(['kept' => self::TABLE_KEPT_FILES])
			->leftJoin(['assets' => Table::ASSETS], '[[assets.id]] = [[kept.assetId]]')
			->where(['assets.id' => null])
			->all();

		$volumes = Craft::$app->getVolumes();
		$deleted = 0;

		foreach ($rows as $row) {
			$volume = $volumes->getVolumeById((int)$row['volumeId']);
			$path = (string)$row['path'];

			if ($volume !== null && !$this->isFileInUse((int)$volume->id, $path)) {
				try {
					$volume->deleteFile($path);
					$deleted++;
				} catch (Throwable $exception) {
					Craft::warning("Couldn't delete the kept file $path: {$exception->getMessage()}", __METHOD__);
				}
			}

			Db::delete(self::TABLE_KEPT_FILES, ['assetId' => $row['assetId']]);
		}

		return $deleted;
	}

	/**
	 * Whether an asset, live or trashed, has the file at the path of a volume.
	 */
	private function isFileInUse(int $volumeId, string $path): bool
	{
		$folders = (new Query())
			->select(['folders.path'])
			->from(['assets' => Table::ASSETS])
			->innerJoin(['folders' => Table::VOLUMEFOLDERS], '[[folders.id]] = [[assets.folderId]]')
			->where(['assets.volumeId' => $volumeId, 'assets.filename' => basename($path)])
			->column();

		foreach ($folders as $folder) {
			if (((string)$folder) . basename($path) === $path) {
				return true;
			}
		}

		return false;
	}

	protected function keptFilesTableExists(): bool
	{
		return $this->keptFilesTableExists ??= Craft::$app->getDb()->tableExists(self::TABLE_KEPT_FILES);
	}

	/**
	 * @param string[] $columns
	 * @return array<string,int>
	 */
	private function sortOrder(array $columns, int $direction): array
	{
		return [...array_fill_keys($columns, $direction), 'elements.id' => $direction];
	}
}
