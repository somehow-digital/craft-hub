<?php

namespace SomehowDigital\Craft\Hub\services;

use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\DateTimeHelper;
use SomehowDigital\Craft\Hub\helpers\Aggregator;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\RelationStatistics;
use yii\base\Component;
use yii\db\Expression;

/**
 * Aggregated entry, asset and relation counts, computed with a few grouped queries per set of sites.
 */
class StatisticsService extends Component
{
	/** @var array<string,array<int,array{volumeId:int,kind:string,stats:AssetStatistics}>> */
	private array $assetRows = [];

	/** @var array<string,array<int,array{sectionId:?int,typeId:int,fieldId:?int,stats:EntryStatistics}>> */
	private array $entryRows = [];

	/** @var array<string,array<int,array{fieldId:int,sectionId:?int,volumeId:?int,stats:RelationStatistics}>> */
	private array $relationRows = [];

	/** @var array<string,array<string,EntryStatistics>> sums of the entry rows for each combination of criteria */
	private array $entrySums = [];

	/** @var array<string,array<string,RelationStatistics>> */
	private array $relationSums = [];

	/** @var array<string,array<string,AssetStatistics>> */
	private array $assetSums = [];

	/**
	 * Entries are counted once, even when they exist on several of the given sites.
	 *
	 * @param int[] $siteIds
	 * @return array<int,array{sectionId:?int,typeId:int,fieldId:?int,stats:EntryStatistics}>
	 */
	public function getEntryRows(array $siteIds): array
	{
		$key = $this->cacheKey($siteIds);

		if (isset($this->entryRows[$key])) {
			return $this->entryRows[$key];
		}

		$results = (new Query())
			->select([
				'sectionId' => 'entries.sectionId',
				'typeId' => 'entries.typeId',
				'fieldId' => 'entries.fieldId',
				'total' => new Expression('COUNT(DISTINCT CASE WHEN [[elements.draftId]] IS NULL THEN [[elements.id]] END)'),
				'enabled' => new Expression('COUNT(DISTINCT CASE WHEN [[elements.draftId]] IS NULL AND [[elements.enabled]] = TRUE AND [[elements_sites.enabled]] = TRUE THEN [[elements.id]] END)'),
				'drafts' => new Expression('COUNT(DISTINCT CASE WHEN [[drafts.provisional]] = FALSE THEN [[elements.id]] END)'),
				'lastUpdated' => new Expression('MAX(CASE WHEN [[elements.draftId]] IS NULL THEN [[elements.dateUpdated]] END)'),
			])
			->from(['entries' => Table::ENTRIES])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
			->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], [
				'and',
				'[[elements_sites.elementId]] = [[elements.id]]',
				['elements_sites.siteId' => $siteIds],
			])
			->leftJoin(['drafts' => Table::DRAFTS], '[[drafts.id]] = [[elements.draftId]]')
			->leftJoin(['owners' => Table::ELEMENTS], '[[owners.id]] = [[entries.primaryOwnerId]]')
			->where([
				'elements.dateDeleted' => null,
				'elements.revisionId' => null,
			])
			// Nested entries owned by drafts or revisions are copies, not content of their own.
			->andWhere([
				'or',
				['entries.primaryOwnerId' => null],
				['owners.dateDeleted' => null, 'owners.draftId' => null, 'owners.revisionId' => null],
			])
			->groupBy(['entries.sectionId', 'entries.typeId', 'entries.fieldId'])
			->all();

		$rows = [];

		foreach ($results as $result) {
			$stats = new EntryStatistics();
			$stats->total = (int)$result['total'];
			$stats->enabled = (int)$result['enabled'];
			$stats->drafts = (int)$result['drafts'];
			$stats->lastUpdated = $result['lastUpdated'] ? (DateTimeHelper::toDateTime($result['lastUpdated']) ?: null) : null;

			$rows[] = [
				'sectionId' => $result['sectionId'] !== null ? (int)$result['sectionId'] : null,
				'typeId' => (int)$result['typeId'],
				'fieldId' => $result['fieldId'] !== null ? (int)$result['fieldId'] : null,
				'stats' => $stats,
			];
		}

		return $this->entryRows[$key] = $rows;
	}

	/**
	 * @param int[] $siteIds
	 * @return array<int,array{fieldId:int,sectionId:?int,volumeId:?int,stats:RelationStatistics}>
	 */
	public function getRelationRows(array $siteIds): array
	{
		$key = $this->cacheKey($siteIds);

		if (isset($this->relationRows[$key])) {
			return $this->relationRows[$key];
		}

		$results = (new Query())
			->select([
				'fieldId' => 'relations.fieldId',
				'sectionId' => 'targets.sectionId',
				'volumeId' => 'targetAssets.volumeId',
				'relations' => new Expression('COUNT(*)'),
				'sources' => new Expression('COUNT(DISTINCT [[relations.sourceId]])'),
				'targets' => new Expression('COUNT(DISTINCT [[relations.targetId]])'),
			])
			->from(['relations' => Table::RELATIONS])
			->innerJoin(['sources' => Table::ELEMENTS], '[[sources.id]] = [[relations.sourceId]]')
			->innerJoin(['targetElements' => Table::ELEMENTS], '[[targetElements.id]] = [[relations.targetId]]')
			->leftJoin(['targets' => Table::ENTRIES], '[[targets.id]] = [[relations.targetId]]')
			->leftJoin(['targetAssets' => Table::ASSETS], '[[targetAssets.id]] = [[relations.targetId]]')
			->where([
				'sources.dateDeleted' => null,
				'sources.draftId' => null,
				'sources.revisionId' => null,
				'targetElements.dateDeleted' => null,
			])
			->andWhere([
				'or',
				['relations.sourceSiteId' => null],
				['relations.sourceSiteId' => $siteIds],
			])
			->groupBy(['relations.fieldId', 'targets.sectionId', 'targetAssets.volumeId'])
			->all();

		$rows = [];

		foreach ($results as $result) {
			$stats = new RelationStatistics();
			$stats->relations = (int)$result['relations'];
			$stats->sources = (int)$result['sources'];
			$stats->targets = (int)$result['targets'];

			$rows[] = [
				'fieldId' => (int)$result['fieldId'],
				'sectionId' => $result['sectionId'] !== null ? (int)$result['sectionId'] : null,
				'volumeId' => $result['volumeId'] !== null ? (int)$result['volumeId'] : null,
				'stats' => $stats,
			];
		}

		return $this->relationRows[$key] = $rows;
	}

	/**
	 * Sums entry stats for all rows matching the given criteria.
	 *
	 * @param int[] $siteIds
	 */
	public function sumEntries(array $siteIds, ?int $sectionId = null, ?int $typeId = null, ?int $fieldId = null): EntryStatistics
	{
		$sums = $this->entrySums[$this->cacheKey($siteIds)] ??= Aggregator::index(
			$this->getEntryRows($siteIds),
			['sectionId', 'typeId', 'fieldId'],
			fn (?EntryStatistics $sum, array $row) => ($sum ?? new EntryStatistics())->add($row['stats']),
		);

		$sum = $sums[Aggregator::key([$sectionId, $typeId, $fieldId])] ?? null;

		return $sum !== null ? clone $sum : new EntryStatistics();
	}

	/**
	 * Sums relation stats for all rows matching the given criteria.
	 *
	 * @param int[] $siteIds
	 */
	public function sumRelations(array $siteIds, ?int $fieldId = null, ?int $sectionId = null, ?int $volumeId = null): RelationStatistics
	{
		$sums = $this->relationSums[$this->cacheKey($siteIds)] ??= Aggregator::index(
			$this->getRelationRows($siteIds),
			['fieldId', 'sectionId', 'volumeId'],
			fn (?RelationStatistics $sum, array $row) => ($sum ?? new RelationStatistics())->add($row['stats']),
		);

		$sum = $sums[Aggregator::key([$fieldId, $sectionId, $volumeId])] ?? null;

		return $sum !== null ? clone $sum : new RelationStatistics();
	}

	/**
	 * Assets per volume and file kind. Assets are counted once, even when they exist on several of the given sites.
	 *
	 * @param int[] $siteIds
	 * @return array<int,array{volumeId:int,kind:string,stats:AssetStatistics}>
	 */
	public function getAssetRows(array $siteIds): array
	{
		$key = $this->cacheKey($siteIds);

		if (isset($this->assetRows[$key])) {
			return $this->assetRows[$key];
		}

		// Correlated subqueries use the indexes on the asset ID, instead of collecting all relations and alternative texts first.
		// Assets related to by any live element.
		$used = (new Query())
			->from(['hubRelations' => Table::RELATIONS])
			->innerJoin(['hubSources' => Table::ELEMENTS], '[[hubSources.id]] = [[hubRelations.sourceId]]')
			->where('[[hubRelations.targetId]] = [[assets.id]]')
			->andWhere([
				'hubSources.dateDeleted' => null,
				'hubSources.draftId' => null,
				'hubSources.revisionId' => null,
			])
			->andWhere([
				'or',
				['hubRelations.sourceSiteId' => null],
				['hubRelations.sourceSiteId' => $siteIds],
			]);

		// Assets with site-specific alternative text on any of the given sites.
		$alt = (new Query())
			->from(['hubAssetsSites' => Table::ASSETS_SITES])
			->where('[[hubAssetsSites.assetId]] = [[assets.id]]')
			->andWhere(['hubAssetsSites.siteId' => $siteIds])
			->andWhere(['not', ['hubAssetsSites.alt' => null]])
			->andWhere(['not', ['hubAssetsSites.alt' => '']]);

		$isUsed = sprintf('EXISTS (%s)', $used->select(new Expression('1'))->createCommand()->getRawSql());
		$hasSiteAlt = sprintf('EXISTS (%s)', $alt->select(new Expression('1'))->createCommand()->getRawSql());

		$onSites = (new Query())
			->from(['elements_sites' => Table::ELEMENTS_SITES])
			->where('[[elements_sites.elementId]] = [[elements.id]]')
			->andWhere(['elements_sites.siteId' => $siteIds]);

		$isImage = sprintf("[[assets.kind]] = '%s'", Asset::KIND_IMAGE);

		$results = (new Query())
			->select([
				'volumeId' => 'assets.volumeId',
				'kind' => 'assets.kind',
				'total' => new Expression('COUNT(*)'),
				'used' => new Expression("SUM(CASE WHEN $isUsed THEN 1 ELSE 0 END)"),
				'images' => new Expression("SUM(CASE WHEN $isImage THEN 1 ELSE 0 END)"),
				'missingAlt' => new Expression("SUM(CASE WHEN $isImage AND COALESCE([[assets.alt]], '') = '' AND NOT $hasSiteAlt THEN 1 ELSE 0 END)"),
				'size' => new Expression('SUM(COALESCE([[assets.size]], 0))'),
				'lastUploaded' => new Expression('MAX([[elements.dateCreated]])'),
			])
			->from(['assets' => Table::ASSETS])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[assets.id]]')
			->where([
				'elements.dateDeleted' => null,
				'elements.draftId' => null,
				'elements.revisionId' => null,
			])
			->andWhere(['not', ['assets.volumeId' => null]])
			->andWhere(['exists', $onSites])
			->groupBy(['assets.volumeId', 'assets.kind'])
			->all();

		$rows = [];

		foreach ($results as $result) {
			$stats = new AssetStatistics();
			$stats->total = (int)$result['total'];
			$stats->used = (int)$result['used'];
			$stats->images = (int)$result['images'];
			$stats->missingAlt = (int)$result['missingAlt'];
			$stats->size = (int)$result['size'];
			$stats->lastUploaded = $result['lastUploaded'] ? (DateTimeHelper::toDateTime($result['lastUploaded']) ?: null) : null;

			$rows[] = [
				'volumeId' => (int)$result['volumeId'],
				'kind' => (string)$result['kind'],
				'stats' => $stats,
			];
		}

		return $this->assetRows[$key] = $rows;
	}

	/**
	 * Sums asset stats for all rows matching the given criteria.
	 *
	 * @param int[] $siteIds
	 */
	public function sumAssets(array $siteIds, ?int $volumeId = null, ?string $kind = null): AssetStatistics
	{
		$sums = $this->assetSums[$this->cacheKey($siteIds)] ??= Aggregator::index(
			$this->getAssetRows($siteIds),
			['volumeId', 'kind'],
			fn (?AssetStatistics $sum, array $row) => ($sum ?? new AssetStatistics())->add($row['stats']),
		);

		$sum = $sums[Aggregator::key([$volumeId, $kind])] ?? null;

		return $sum !== null ? clone $sum : new AssetStatistics();
	}

	/**
	 * @param int[] $siteIds
	 */
	private function cacheKey(array $siteIds): string
	{
		sort($siteIds);

		return implode(',', $siteIds);
	}
}
