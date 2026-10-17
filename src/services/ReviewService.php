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
	public function getStaleQuery(int $months, ?int $sectionId = null, ?int $typeId = null, ?int $fieldId = null): EntryQuery
	{
		$date = (new DateTime())->modify(sprintf('-%d months', $months));

		return $this->createQuery($sectionId, $typeId, $fieldId)
			->dateUpdated('< ' . $date->format(DateTime::ATOM));
	}

	private function createQuery(?int $sectionId, ?int $typeId, ?int $fieldId = null): EntryQuery
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

		if ($fieldId !== null) {
			$query->fieldId($fieldId);
		}

		return $query;
	}
}
