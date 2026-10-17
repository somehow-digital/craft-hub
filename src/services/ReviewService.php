<?php

namespace SomehowDigital\Craft\Hub\services;

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

	/** @var array<string,array<string,int>> grouped counts of the review queries, keyed by query and site scope */
	private array $counts = [];

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
	 * Entries of sections with URLs on their site are skipped, as they are reachable on their own.
	 */
	public function getUnreferencedQuery(?int $sectionId = null, ?int $typeId = null): EntryQuery
	{
		$withUrls = (new Query())
			->from(['hubSectionsSites' => Table::SECTIONS_SITES])
			->where('[[hubSectionsSites.sectionId]] = [[entries.sectionId]]')
			->andWhere('[[hubSectionsSites.siteId]] = [[elements_sites.siteId]]')
			->andWhere(['hubSectionsSites.hasUrls' => true]);

		return $this->createQuery($sectionId, $typeId)
			->andWhere(['not exists', $this->referencesQuery()])
			->andWhere(['not exists', $withUrls]);
	}

	/**
	 * Assets that no element relates to through a relation field, largest first.
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
		$pixels = Hub::getInstance()->settings->largeImageThresholdPx;

		return $this->createAssetQuery($volumeId)
			->kind(Asset::KIND_IMAGE)
			->andWhere(['or', ['>', 'assets.width', $pixels], ['>', 'assets.height', $pixels]])
			->orderBy(['assets.size' => SORT_DESC]);
	}

	/**
	 * Images without alternative text that each of the elements or their nested entries relate to.
	 *
	 * @param ElementInterface[] $elements
	 * @return array<int,Asset[]> keyed by element ID
	 */
	public function getRelatedMissingAlt(array $elements): array
	{
		$siteIds = Hub::getInstance()->getSiteIds();
		$targetIds = $this->getRelatedIds($elements, $siteIds);
		$ids = array_keys(array_replace([], ...array_values($targetIds)));

		$assets = $ids === [] ? [] : Asset::find()
			->id($ids)
			->siteId($siteIds)
			->unique()
			->status(null)
			->andWhere($this->missingAltCondition())
			->all();

		return array_map(
			fn (array $relatedIds) => array_values(array_filter($assets, fn (Asset $asset) => isset($relatedIds[$asset->id]))),
			$targetIds,
		);
	}

	/**
	 * Entries that each of the elements or their nested entries relate to, which are not live (disabled, pending or expired).
	 *
	 * @param ElementInterface[] $elements
	 * @return array<int,Entry[]> keyed by element ID
	 */
	public function getRelatedUnavailable(array $elements): array
	{
		$related = [];

		// Relations and statuses are site-specific, so the elements are looked up in their own site.
		foreach ($this->groupBySite($elements) as $siteId => $siteElements) {
			$targetIds = $this->getRelatedIds($siteElements, [$siteId]);
			$ids = array_keys(array_replace([], ...array_values($targetIds)));

			$entries = $ids === [] ? [] : Entry::find()
				->id($ids)
				->siteId($siteId)
				->status([Entry::STATUS_DISABLED, Entry::STATUS_PENDING, Entry::STATUS_EXPIRED])
				->all();

			foreach ($targetIds as $elementId => $relatedIds) {
				$related[$elementId] = array_values(array_filter($entries, fn (Entry $entry) => isset($relatedIds[$entry->id])));
			}
		}

		return $related;
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
	 * Live relations targeting the queried element.
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
	 * IDs of the elements that each of the elements or the nested entries it owns relate to.
	 *
	 * @param ElementInterface[] $elements
	 * @param int[] $siteIds sites the relations are made on, apart from relations of fields that aren't translatable
	 * @return array<int,array<int,true>> element ID => related element IDs as keys
	 */
	private function getRelatedIds(array $elements, array $siteIds): array
	{
		$ownedIds = $this->getOwnedIds($elements);
		$owners = [];

		foreach ($ownedIds as $elementId => $ids) {
			foreach ($ids as $id) {
				$owners[$id][] = $elementId;
			}
		}

		$related = array_fill_keys(array_keys($ownedIds), []);

		if ($owners === []) {
			return $related;
		}

		$rows = (new Query())
			->select(['sourceId', 'targetId'])
			->from([Table::RELATIONS])
			->where(['sourceId' => array_keys($owners)])
			->andWhere(['or', ['sourceSiteId' => null], ['sourceSiteId' => $siteIds]])
			->all();

		foreach ($rows as $row) {
			foreach ($owners[(int)$row['sourceId']] as $elementId) {
				$related[$elementId][(int)$row['targetId']] = true;
			}
		}

		return $related;
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
			// Without `unique()`, elements on several sites are counted once by their ID instead of a subquery per element.
			/** @var array<int,array<string,mixed>> $rows */
			$rows = $query()
				->unique(false)
				->orderBy(null)
				->select([...$columns, 'total' => new Expression('COUNT(DISTINCT [[elements.id]])')])
				->groupBy(array_values($columns))
				->asArray()
				->all();

			$this->counts[$key] = Aggregator::index($rows, array_keys($columns), fn (?int $sum, array $row) => ($sum ?? 0) + (int)$row['total']);
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
	 * Entries that have not been updated within the given number of months.
	 */
	public function getStaleQuery(int $months, ?int $sectionId = null, ?int $typeId = null): EntryQuery
	{
		$date = (new DateTime())->modify(sprintf('-%d months', $months));

		return $this->createQuery($sectionId, $typeId)
			->dateUpdated('< ' . $date->format(DateTime::ATOM));
	}

	private function createQuery(?int $sectionId, ?int $typeId): EntryQuery
	{
		$query = Entry::find()
			->siteId(Hub::getInstance()->getSiteIds())
			->unique()
			->status(null)
			->orderBy(['elements.dateUpdated' => SORT_ASC]);

		if ($sectionId !== null) {
			$query->sectionId($sectionId);
		} else {
			// Nested entries belong to their owner and are never referenced on their own.
			$query->andWhere(['not', ['entries.sectionId' => null]]);
		}

		if ($typeId !== null) {
			$query->typeId($typeId);
		}

		return $query;
	}
}
