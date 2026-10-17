<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use DateTime;
use SomehowDigital\Craft\Hub\helpers\Aggregator;
use SomehowDigital\Craft\Hub\helpers\Classifier;
use SomehowDigital\Craft\Hub\Hub;
use yii\base\Component;
use yii\db\Expression;

/**
 * Queries for section entries and assets worth reviewing for cleanup.
 */
class ReviewService extends Component
{
	private const ENTRY_GROUPS = ['sectionId' => 'entries.sectionId', 'typeId' => 'entries.typeId'];
	private const ASSET_GROUPS = ['volumeId' => 'assets.volumeId'];

	/** filenames read at once when classifying them */
	private const FILENAME_BATCH = 1000;

	/** seconds the files with generic filenames are cached for at most, in case a change keeps the state of the files */
	private const FILENAME_CACHE_DURATION = 3600;

	/** @var array<string,array<string,int>> grouped counts of the review queries, keyed by query and site scope */
	private array $counts = [];

	/** @var array<string,mixed> element IDs found by the reverse lookups, keyed by lookup and site scope */
	private array $ids = [];

	/**
	 * Number of unreferenced entries, counted for all sections and entry types at once.
	 */
	public function countUnreferenced(?int $sectionId = null, ?int $typeId = null): int
	{
		return $this->countBy('unreferenced', fn () => $this->getUnreferencedQuery(), self::ENTRY_GROUPS, [$sectionId, $typeId]);
	}

	/**
	 * Number of stale entries, counted for all sections and entry types at once.
	 */
	public function countStale(int $months, ?int $sectionId = null, ?int $typeId = null): int
	{
		return $this->countBy("stale:$months", fn () => $this->getStaleQuery($months), self::ENTRY_GROUPS, [$sectionId, $typeId]);
	}

	/**
	 * Number of large files, counted for all volumes at once.
	 */
	public function countLargeFiles(?int $volumeId = null): int
	{
		return $this->countBy('largeFiles', fn () => $this->getLargeFilesQuery(), self::ASSET_GROUPS, [$volumeId]);
	}

	/**
	 * Number of large images, counted for all volumes at once.
	 */
	public function countLargeImages(?int $volumeId = null): int
	{
		return $this->countBy('largeImages', fn () => $this->getLargeImagesQuery(), self::ASSET_GROUPS, [$volumeId]);
	}

	/**
	 * Entries that no element references through a relation field.
	 *
	 * Entries of sections with URLs on their site are skipped, as they are reachable on their own,
	 * and so are nested entries, as they belong to their owner and are never referenced on their own.
	 */
	public function getUnreferencedQuery(?int $sectionId = null, ?int $typeId = null): EntryQuery
	{
		$withUrls = (new Query())
			->from(['hubSectionsSites' => Table::SECTIONS_SITES])
			->where('[[hubSectionsSites.sectionId]] = [[entries.sectionId]]')
			->andWhere('[[hubSectionsSites.siteId]] = [[elements_sites.siteId]]')
			->andWhere(['hubSectionsSites.hasUrls' => true]);

		return $this->createQuery($sectionId, $typeId)
			->andWhere(['not', ['entries.sectionId' => null]])
			->andWhere(['not exists', $this->referencesQuery()])
			->andWhere(['not exists', $withUrls]);
	}

	/**
	 * Assets that no element references through a relation field, largest first.
	 */
	public function getUnusedAssetsQuery(?int $volumeId = null): AssetQuery
	{
		return $this->createAssetQuery($volumeId)
			->andWhere(['not exists', $this->referencesQuery()])
			->orderBy(['assets.size' => SORT_DESC]);
	}

	/**
	 * Images without alternative text, newest first.
	 */
	public function getMissingAltQuery(?int $volumeId = null): AssetQuery
	{
		return $this->createAssetQuery($volumeId)
			->andWhere($this->missingAltCondition())
			->orderBy(['elements.dateCreated' => SORT_DESC]);
	}

	/**
	 * Assets larger than the large file threshold, largest first.
	 */
	public function getLargeFilesQuery(?int $volumeId = null): AssetQuery
	{
		return $this->createAssetQuery($volumeId)
			->size('> ' . Hub::getInstance()->settings->getLargeFileThreshold())
			->orderBy(['assets.size' => SORT_DESC]);
	}

	/**
	 * Images wider or taller than the large image threshold, largest first.
	 */
	public function getLargeImagesQuery(?int $volumeId = null): AssetQuery
	{
		return $this->createAssetQuery($volumeId)
			->andWhere($this->largeImageCondition())
			->orderBy(['assets.size' => SORT_DESC]);
	}

	/**
	 * Images without alternative text that each of the elements or their nested entries reference.
	 *
	 * @param ElementInterface[] $elements
	 * @return array<int,Asset[]> keyed by element ID
	 */
	public function getReferencedMissingAlt(array $elements): array
	{
		$siteIds = Hub::getInstance()->getSiteIds();
		$targetIds = $this->getReferencedIds($elements, $siteIds);
		$ids = array_keys(array_replace([], ...array_values($targetIds)));

		$assets = $ids === [] ? [] : Asset::find()
			->id($ids)
			->siteId($siteIds)
			->unique()
			->status(null)
			->andWhere($this->missingAltCondition())
			->all();

		return array_map(
			fn (array $referencedIds) => array_values(array_filter($assets, fn (Asset $asset) => isset($referencedIds[$asset->id]))),
			$targetIds,
		);
	}

	/**
	 * Entries that each of the elements or their nested entries reference, which are not live (disabled, pending or expired).
	 *
	 * @param ElementInterface[] $elements
	 * @return array<int,Entry[]> keyed by element ID
	 */
	public function getReferencedUnavailable(array $elements): array
	{
		$referenced = [];

		// Relations and statuses are site-specific, so the elements are looked up in their own site.
		foreach ($this->groupBySite($elements) as $siteId => $siteElements) {
			$targetIds = $this->getReferencedIds($siteElements, [$siteId]);
			$ids = array_keys(array_replace([], ...array_values($targetIds)));

			$entries = $ids === [] ? [] : Entry::find()
				->id($ids)
				->siteId($siteId)
				->status([Entry::STATUS_DISABLED, Entry::STATUS_PENDING, Entry::STATUS_EXPIRED])
				->all();

			foreach ($targetIds as $elementId => $referencedIds) {
				$referenced[$elementId] = array_values(array_filter($entries, fn (Entry $entry) => isset($referencedIds[$entry->id])));
			}
		}

		return $referenced;
	}

	/**
	 * Elements that reference images without alternative text, directly or through the nested entries they own, as `id`.
	 * The reverse of {@see getReferencedMissingAlt()}, to join to the queried elements without loading them first.
	 */
	public function relatingToMissingAltQuery(): Query
	{
		$siteIds = Hub::getInstance()->getSiteIds();

		// The criteria of an asset query, without the sorting and the row for each site it would add.
		$assets = (new Query())
			->select(['assets.id'])
			->from(['assets' => Table::ASSETS])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[assets.id]]')
			->innerJoin(['volumeFolders' => Table::VOLUMEFOLDERS], '[[volumeFolders.id]] = [[assets.folderId]]')
			->where($this->liveCondition())
			->andWhere(['exists', $this->onSitesQuery($siteIds)])
			->andWhere($this->missingAltCondition());

		$sources = (new Query())
			->select(['id' => 'hubRelations.sourceId'])
			->from(['hubRelations' => Table::RELATIONS])
			->where(['in', 'hubRelations.targetId', $assets])
			->andWhere(['or', ['hubRelations.sourceSiteId' => null], ['hubRelations.sourceSiteId' => $siteIds]]);

		return $this->withOwnersQuery($sources);
	}

	/**
	 * Elements that reference entries which are disabled, pending or expired, directly or through the nested entries they own,
	 * as `id` and `siteId`. The reverse of {@see getReferencedUnavailable()}; as statuses are site-specific,
	 * the elements are found per site, all sites at once.
	 */
	public function relatingToUnavailableQuery(): Query
	{
		$siteIds = Hub::getInstance()->getSiteIds();

		// The criteria of an entry query, without the joins it adds for loading entries.
		$targets = (new Query())
			->select(['id' => 'elements.id', 'siteId' => 'elements_sites.siteId'])
			->from(['entries' => Table::ENTRIES])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
			->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[elements.id]]')
			->where($this->liveCondition())
			->andWhere(['elements_sites.siteId' => $siteIds])
			->andWhere($this->entryStatusCondition([Entry::STATUS_DISABLED, Entry::STATUS_PENDING, Entry::STATUS_EXPIRED]));

		// Relations made on the site the entry is unavailable on, or not translatable.
		$sources = (new Query())
			->select(['id' => 'hubRelations.sourceId', 'siteId' => 'hubTargets.siteId'])
			->from(['hubRelations' => Table::RELATIONS])
			->innerJoin(['hubTargets' => $targets], '[[hubTargets.id]] = [[hubRelations.targetId]]')
			->where(['or', ['hubRelations.sourceSiteId' => null], '[[hubRelations.sourceSiteId]] = [[hubTargets.siteId]]']);

		return $this->withOwnersQuery($sources, 'siteId');
	}

	/**
	 * Files with a filename that is a camera, screenshot or download default, as classified by {@see Classifier::isGenericFilename()}.
	 * Trashed files are included, as the queries using them leave those out anyway, so restoring a file needn't find them again.
	 *
	 * Classifying them reads all filenames, so they are cached until files are added, renamed, replaced or deleted,
	 * which changes their number, the last ID or the last date a file was updated.
	 *
	 * @return int[]
	 */
	public function getGenericFilenameIds(): array
	{
		return $this->remember('genericFilenames', function () {
			$state = (new Query())
				->select(['count' => 'COUNT(*)', 'lastId' => 'MAX([[hubFiles.id]])', 'lastUpdate' => 'MAX([[hubFiles.dateUpdated]])'])
				->from(['hubFiles' => Table::ASSETS])
				->one();
			$key = 'hub:genericFilenames:' . md5((string)json_encode($state));

			return Craft::$app->getCache()->getOrSet($key, fn () => $this->findGenericFilenameIds(), self::FILENAME_CACHE_DURATION);
		});
	}

	/**
	 * The filenames are read a thousand at a time, after the ID of the last one read, so all of them are never held at once.
	 *
	 * @return int[]
	 */
	private function findGenericFilenameIds(): array
	{
		$query = (new Query())
			->select(['id' => 'assets.id', 'filename' => 'assets.filename'])
			->from(['assets' => Table::ASSETS])
			->orderBy(['assets.id' => SORT_ASC])
			->limit(self::FILENAME_BATCH);

		$ids = [];
		$lastId = 0;

		do {
			$rows = (clone $query)->andWhere(['>', 'assets.id', $lastId])->all();

			foreach ($rows as $row) {
				$lastId = (int)$row['id'];

				if (Classifier::isGenericFilename((string)$row['filename'])) {
					$ids[] = $lastId;
				}
			}
		} while (count($rows) === self::FILENAME_BATCH);

		return $ids;
	}

	/**
	 * Entries with drafts that aren't provisional, as `canonicalId`, found in the drafts alone.
	 * Finding them through the elements would visit each revision of each entry too.
	 */
	public function draftedQuery(): Query
	{
		return (new Query())
			->select(['canonicalId' => 'hubDrafted.canonicalId'])
			->distinct()
			->from(['hubDrafted' => Table::DRAFTS])
			->where(['hubDrafted.provisional' => false])
			->andWhere(['not', ['hubDrafted.canonicalId' => null]]);
	}

	/**
	 * Condition matching the queried entries with drafts on their site, only looking up the drafts of entries that have any.
	 *
	 * @return array<int,mixed>
	 */
	public function draftsCondition(): array
	{
		return ['and', ['in', 'elements.id', $this->draftedQuery()], ['exists', $this->draftsQuery()]];
	}

	/**
	 * Drafts of the queried entry on its site; provisional drafts are unsaved changes of a single user, so they are left out.
	 */
	public function draftsQuery(): Query
	{
		return (new Query())
			->from(['hubDrafts' => Table::ELEMENTS])
			->innerJoin(['hubDraftsInfo' => Table::DRAFTS], '[[hubDraftsInfo.id]] = [[hubDrafts.draftId]]')
			->innerJoin(['hubDraftsSites' => Table::ELEMENTS_SITES], '[[hubDraftsSites.elementId]] = [[hubDrafts.id]]')
			->where('[[hubDrafts.canonicalId]] = [[elements.id]]')
			->andWhere('[[hubDraftsSites.siteId]] = [[elements_sites.siteId]]')
			->andWhere([
				'hubDrafts.dateDeleted' => null,
				'hubDraftsInfo.provisional' => false,
			]);
	}

	/**
	 * Number of drafts of each of the entries, in the entry's site.
	 *
	 * @param Entry[] $entries
	 * @return array<int,int> keyed by entry ID
	 */
	public function getDraftCounts(array $entries): array
	{
		$counts = array_fill_keys(array_map(fn (Entry $entry) => $entry->id, $entries), 0);

		foreach ($this->groupBySite($entries) as $siteId => $siteEntries) {
			$rows = Entry::find()
				->draftOf(array_map(fn (Entry $entry) => $entry->getCanonicalId(), $siteEntries))
				->siteId($siteId)
				->status(null)
				->orderBy(null)
				->select(['canonicalId' => 'elements.canonicalId', 'total' => new Expression('COUNT(*)')])
				->groupBy(['elements.canonicalId'])
				->asArray()
				->all();

			$totals = array_column($rows, 'total', 'canonicalId');

			foreach ($siteEntries as $entry) {
				$counts[$entry->id] = (int)($totals[$entry->getCanonicalId()] ?? 0);
			}
		}

		return $counts;
	}

	/**
	 * Condition matching images of the queried assets without alternative text on any of the counted sites.
	 *
	 * @return array<int,mixed>
	 */
	public function missingAltCondition(): array
	{
		$siteAlt = (new Query())
			->from(['hubAssetsSites' => Table::ASSETS_SITES])
			->where('[[hubAssetsSites.assetId]] = [[assets.id]]')
			->andWhere(['hubAssetsSites.siteId' => Hub::getInstance()->getSiteIds()])
			->andWhere(['not', ['hubAssetsSites.alt' => null]])
			->andWhere(['not', ['hubAssetsSites.alt' => '']]);

		return [
			'and',
			['assets.kind' => Asset::KIND_IMAGE],
			['or', ['assets.alt' => null], ['assets.alt' => '']],
			['not exists', $siteAlt],
		];
	}

	/**
	 * Condition matching images of the queried assets wider or taller than the large image threshold.
	 *
	 * @return array<int,mixed>
	 */
	public function largeImageCondition(): array
	{
		$pixels = Hub::getInstance()->settings->largeImageThresholdPx;

		return [
			'and',
			['assets.kind' => Asset::KIND_IMAGE],
			['or', ['>', 'assets.width', $pixels], ['>', 'assets.height', $pixels]],
		];
	}

	/**
	 * Images with how many of the counted sites they exist on have alternative text, as `withAlt`, and how many don't,
	 * as `withoutAlt`, like the alt text of their site variants; to join to the queried assets as `hubAlt`.
	 * All sites of all images are counted in a single pass, as checking them for each image takes several lookups
	 * of all of its sites, one for each of the alt text conditions.
	 */
	public function altSitesQuery(): Query
	{
		$alt = "TRIM(COALESCE([[hubAssetsSites.alt]], ''))";

		return (new Query())
			->select([
				'id' => 'hubElementsSites.elementId',
				'withAlt' => new Expression("SUM(CASE WHEN $alt <> '' THEN 1 ELSE 0 END)"),
				'withoutAlt' => new Expression("SUM(CASE WHEN $alt = '' THEN 1 ELSE 0 END)"),
			])
			->from(['hubElementsSites' => Table::ELEMENTS_SITES])
			->innerJoin(['hubImages' => Table::ASSETS], '[[hubImages.id]] = [[hubElementsSites.elementId]]')
			->leftJoin(['hubAssetsSites' => Table::ASSETS_SITES], '[[hubAssetsSites.assetId]] = [[hubElementsSites.elementId]] AND [[hubAssetsSites.siteId]] = [[hubElementsSites.siteId]]')
			->where(['hubImages.kind' => Asset::KIND_IMAGE])
			->andWhere(['hubElementsSites.siteId' => Hub::getInstance()->getSiteIds()])
			->groupBy(['hubElementsSites.elementId']);
	}

	/**
	 * Condition matching images of the queried assets without alternative text on any of the counted sites they exist on.
	 * The query needs {@see altSitesQuery()} joined as `hubAlt`.
	 *
	 * @return array<int,mixed>
	 */
	public function noAltCondition(): array
	{
		return ['and', ['assets.kind' => Asset::KIND_IMAGE], 'COALESCE([[hubAlt.withAlt]], 0) = 0'];
	}

	/**
	 * Condition matching images of the queried assets with alternative text on some of the counted sites they exist on, but not all.
	 * The query needs {@see altSitesQuery()} joined as `hubAlt`.
	 *
	 * @return array<int,mixed>
	 */
	public function partialAltCondition(): array
	{
		return ['and', ['assets.kind' => Asset::KIND_IMAGE], ['>', 'hubAlt.withAlt', 0], ['>', 'hubAlt.withoutAlt', 0]];
	}

	/**
	 * Image transforms of the queried asset that failed to generate.
	 */
	public function failedTransformsQuery(): Query
	{
		return (new Query())
			->from(['hubTransforms' => Table::IMAGETRANSFORMINDEX])
			->where('[[hubTransforms.assetId]] = [[assets.id]]')
			->andWhere(['hubTransforms.error' => true]);
	}

	/**
	 * Live references targeting the queried element.
	 */
	public function referencesQuery(): Query
	{
		return (new Query())
			->from(['hubRelations' => Table::RELATIONS])
			->innerJoin(['hubSources' => Table::ELEMENTS], '[[hubSources.id]] = [[hubRelations.sourceId]]')
			->where('[[hubRelations.targetId]] = [[elements.id]]')
			->andWhere([
				'hubSources.dateDeleted' => null,
				'hubSources.draftId' => null,
				'hubSources.revisionId' => null,
			]);
	}

	/**
	 * IDs of the elements that each of the elements or the nested entries it owns reference.
	 *
	 * @param ElementInterface[] $elements
	 * @param int[] $siteIds sites the relations are made on, apart from relations of fields that aren't translatable
	 * @return array<int,array<int,true>> element ID => referenced element IDs as keys
	 */
	private function getReferencedIds(array $elements, array $siteIds): array
	{
		$ownedIds = $this->getOwnedIds($elements);
		$owners = [];

		foreach ($ownedIds as $elementId => $ids) {
			foreach ($ids as $id) {
				$owners[$id][] = $elementId;
			}
		}

		$referenced = array_fill_keys(array_keys($ownedIds), []);

		if ($owners === []) {
			return $referenced;
		}

		$rows = (new Query())
			->select(['sourceId', 'targetId'])
			->from([Table::RELATIONS])
			->where(['sourceId' => array_keys($owners)])
			->andWhere(['or', ['sourceSiteId' => null], ['sourceSiteId' => $siteIds]])
			->all();

		foreach ($rows as $row) {
			foreach ($owners[(int)$row['sourceId']] as $elementId) {
				$referenced[$elementId][(int)$row['targetId']] = true;
			}
		}

		return $referenced;
	}

	/**
	 * The canonical ID of each element along with the IDs of the nested entries it owns, at any depth.
	 * All elements are walked down together, so this takes one query per nesting level.
	 *
	 * @param ElementInterface[] $elements
	 * @return array<int,int[]> keyed by element ID
	 */
	private function getOwnedIds(array $elements): array
	{
		/** @var array<int,array<int,true>> $owned */
		$owned = [];
		/** @var array<int,int[]> $level owner ID => IDs of the elements it belongs to */
		$level = [];

		foreach ($elements as $element) {
			$canonicalId = $element->getCanonicalId();
			$owned[$element->id] = [$canonicalId => true];
			$level[$canonicalId][] = $element->id;
		}

		while ($level !== []) {
			$rows = (new Query())
				->select(['ownerId' => 'owners.ownerId', 'elementId' => 'owners.elementId'])
				->distinct()
				->from(['owners' => Table::ELEMENTS_OWNERS])
				->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[owners.elementId]]')
				->where([
					'owners.ownerId' => array_keys($level),
					'elements.dateDeleted' => null,
					'elements.draftId' => null,
					'elements.revisionId' => null,
				])
				->all();

			$next = [];

			foreach ($rows as $row) {
				$id = (int)$row['elementId'];

				foreach ($level[(int)$row['ownerId']] as $elementId) {
					if (!isset($owned[$elementId][$id])) {
						$owned[$elementId][$id] = true;
						$next[$id][] = $elementId;
					}
				}
			}

			$level = $next;
		}

		return array_map(fn (array $ids) => array_keys($ids), $owned);
	}

	/**
	 * The elements of a query along with the elements owning them, at any depth: the reverse of {@see getOwnedIds()}.
	 * Owners take the other columns of the elements they own, and each row is listed once.
	 *
	 * The owners are found by the database, level by level, in a recursive query,
	 * so the elements are neither loaded nor sent back with each query they are matched in.
	 *
	 * @param Query $elements selecting the `id` of the elements and the given columns
	 */
	private function withOwnersQuery(Query $elements, string ...$columns): Query
	{
		$owners = (new Query())
			->select(['id' => 'owners.ownerId', ...array_map(fn (string $column) => "hubOwned.$column", $columns)])
			->from(['hubOwned' => 'hubWithOwners'])
			->innerJoin(['owners' => Table::ELEMENTS_OWNERS], '[[owners.elementId]] = [[hubOwned.id]]')
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[owners.elementId]]')
			->where([
				'elements.dateDeleted' => null,
				'elements.draftId' => null,
				'elements.revisionId' => null,
			]);

		// Built as SQL, as query builders put each part of a union in parentheses, which not all databases take in recursive queries.
		// The union lists each row once, which also ends the recursion once a level finds no new owners.
		$sql = sprintf('%s UNION %s', $elements->createCommand()->getRawSql(), $owners->createCommand()->getRawSql());

		return (new Query())
			->select(['id', ...$columns])
			->from('hubWithOwners')
			->withQuery($sql, '[[hubWithOwners]]', true);
	}

	/**
	 * Condition matching elements like element queries find them by default: not archived, trashed, drafts or revisions.
	 *
	 * @return array<string,mixed>
	 */
	private function liveCondition(): array
	{
		return [
			'elements.archived' => false,
			'elements.dateDeleted' => null,
			'elements.draftId' => null,
			'elements.revisionId' => null,
		];
	}

	/**
	 * Sites of the counted ones the queried element exists on.
	 *
	 * @param int[] $siteIds
	 */
	private function onSitesQuery(array $siteIds): Query
	{
		return (new Query())
			->from(['hubSites' => Table::ELEMENTS_SITES])
			->where('[[hubSites.elementId]] = [[elements.id]]')
			->andWhere(['hubSites.siteId' => $siteIds]);
	}

	/**
	 * Craft's own condition matching entries with any of the statuses, as it depends on its config, like `staticStatuses`.
	 *
	 * @param string[] $statuses
	 * @return array<int,mixed>
	 */
	private function entryStatusCondition(array $statuses): array
	{
		$query = Entry::find();

		return ['or', ...array_map(fn (string $status) => (fn () => $this->statusCondition($status))->call($query), $statuses)];
	}

	/**
	 * Result of a reverse lookup, computed once per site scope.
	 *
	 * @template T
	 * @param callable():T $lookup
	 * @return T
	 */
	private function remember(string $name, callable $lookup): mixed
	{
		$key = $name . ':' . implode(',', Hub::getInstance()->getSiteIds());

		return $this->ids[$key] ??= $lookup();
	}

	/**
	 * @template T of ElementInterface
	 * @param T[] $elements
	 * @return array<int,T[]> keyed by site ID
	 */
	private function groupBySite(array $elements): array
	{
		$groups = [];

		foreach ($elements as $element) {
			$groups[$element->siteId][] = $element;
		}

		return $groups;
	}

	/**
	 * Counts the elements of a review query by the given columns in a single query, and sums the counts matching the criteria.
	 *
	 * @param callable():ElementQuery $query
	 * @param array<string,string> $columns alias => column to group by
	 * @param array<int,int|null> $criteria a value for each column, or null for any value
	 */
	private function countBy(string $name, callable $query, array $columns, array $criteria): int
	{
		$key = $name . ':' . implode(',', Hub::getInstance()->getSiteIds());

		if (!isset($this->counts[$key])) {
			/** @var QueryService $queries */
			$queries = Hub::getInstance()->queries;
			$this->counts[$key] = Aggregator::index($queries->count($query(), $columns), array_keys($columns), fn (?int $sum, array $row) => ($sum ?? 0) + (int)$row['total']);
		}

		return $this->counts[$key][Aggregator::key($criteria)] ?? 0;
	}

	private function createAssetQuery(?int $volumeId): AssetQuery
	{
		$query = Asset::find()
			->siteId(Hub::getInstance()->getSiteIds())
			->unique();

		if ($volumeId !== null) {
			$query->volumeId($volumeId);
		}

		return $query;
	}

	/**
	 * Entries that have not been updated within the given number of months, nested ones included.
	 */
	public function getStaleQuery(int $months, ?int $sectionId = null, ?int $typeId = null): EntryQuery
	{
		$date = (new DateTime())->modify(sprintf('-%d months', $months));

		return $this->createQuery($sectionId, $typeId)
			->dateUpdated('< ' . $date->format(DateTime::ATOM));
	}

	private function createQuery(?int $sectionId, ?int $typeId): EntryQuery
	{
		/** @var EntryService $entries */
		$entries = Hub::getInstance()->entries;

		$query = Entry::find()
			->siteId(Hub::getInstance()->getSiteIds())
			->unique()
			->status(null)
			->andWhere($entries->contentCondition())
			->orderBy(['elements.dateUpdated' => SORT_ASC]);

		if ($sectionId !== null) {
			$query->sectionId($sectionId);
		}

		if ($typeId !== null) {
			$query->typeId($typeId);
		}

		return $query;
	}
}
