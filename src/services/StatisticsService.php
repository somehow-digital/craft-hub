<?php

namespace SomehowDigital\Craft\Hub\services;

use Closure;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use SomehowDigital\Craft\Hub\helpers\Aggregator;
use SomehowDigital\Craft\Hub\helpers\SiteSet;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\ReferenceStatistics;
use yii\base\Component;
use yii\db\Expression;

/**
 * Aggregated entry, asset and reference counts, computed with a few grouped queries per set of sites.
 *
 * The statistics of a snapshot (see fromSnapshot()) count the rows the snapshot kept instead, summed the same way.
 */
class StatisticsService extends Component
{
	/** entries that are drafts, apart from provisional ones and unsaved drafts of new entries, like Craft's element indexes */
	private const IS_DRAFT = '[[drafts.provisional]] = FALSE AND ([[elements.canonicalId]] IS NOT NULL OR [[drafts.saved]] = TRUE)';

	/** when a person last edited an entry, see EditService */
	private const LAST_EDITED = 'COALESCE([[edits.dateEdited]], [[elements.dateUpdated]])';

	/** @var (Closure(string):array<int|string,mixed>)|null rows of a snapshot by their kind, see fromSnapshot() */
	private ?Closure $snapshot = null;

	/** @var array<string,array<int,array{volumeId:int,kind:string,stats:AssetStatistics}>> */
	private array $assetRows = [];

	/** @var array<string,string[]> */
	private array $assetKinds = [];

	/** @var array<string,array<int,array{sectionId:?int,typeId:int,fieldId:?int,stats:EntryStatistics}>> */
	private array $entryRows = [];

	/** @var array<string,array<int,array{fieldId:int,sectionId:?int,volumeId:?int,stats:ReferenceStatistics}>> */
	private array $referenceRows = [];

	/** @var array<string,array<string,EntryStatistics>> sums of the entry rows for each combination of criteria */
	private array $entrySums = [];

	/** @var array<string,array<string,ReferenceStatistics>> */
	private array $referenceSums = [];

	/** @var array<string,array<string,AssetStatistics>> */
	private array $assetSums = [];

	/**
	 * Entries are counted once, even when they exist on several of the given sites.
	 * Section entries and nested entries are counted separately, as most pages only need those of sections.
	 *
	 * @param int[] $siteIds
	 * @param bool|null $nested true for nested entries only, false for section entries only, null for both
	 * @return array<int,array{sectionId:?int,typeId:int,fieldId:?int,stats:EntryStatistics}>
	 */
	public function getEntryRows(array $siteIds, ?bool $nested = null): array
	{
		if ($nested === null) {
			return [...$this->getEntryRows($siteIds, false), ...$this->getEntryRows($siteIds, true)];
		}

		$key = $this->cacheKey($siteIds) . ($nested ? ':nested' : ':sections');

		if (isset($this->entryRows[$key])) {
			return $this->entryRows[$key];
		}

		if ($this->snapshot !== null) {
			return $this->entryRows[$key] = $this->snapshotEntryRows($siteIds, $nested);
		}

		// Sites are matched with subqueries, so each entry is a single row and counted without a sort for each count.
		$onSites = (new Query())
			->from(['elements_sites' => Table::ELEMENTS_SITES])
			->where('[[elements_sites.elementId]] = [[elements.id]]')
			->andWhere(['elements_sites.siteId' => $siteIds]);

		$enabledOnSites = (clone $onSites)->andWhere(['elements_sites.enabled' => true]);
		$isEnabled = sprintf(
			'[[elements.draftId]] IS NULL AND [[elements.enabled]] = TRUE AND EXISTS (%s)',
			$enabledOnSites->select(new Expression('1'))->createCommand()->getRawSql(),
		);

		// Like Craft's statuses, enabled entries are pending before their post date and expired from their expiry date on.
		$now = Db::prepareDateForDb(new DateTime());

		// A row for each entry, as whether it's enabled is looked up once for it, rather than for each of the sums.
		$entries = $this->entryQuery()
			->select([
				'sectionId' => 'entries.sectionId',
				'typeId' => 'entries.typeId',
				'fieldId' => 'entries.fieldId',
				'postDate' => 'entries.postDate',
				'expiryDate' => 'entries.expiryDate',
				'live' => new Expression('CASE WHEN [[elements.draftId]] IS NULL THEN 1 ELSE 0 END'),
				'enabled' => new Expression("CASE WHEN $isEnabled THEN 1 ELSE 0 END"),
				'draft' => new Expression('CASE WHEN ' . self::IS_DRAFT . ' THEN 1 ELSE 0 END'),
				'lastEdited' => new Expression(self::LAST_EDITED),
			])
			->andWhere(['exists', $onSites])
			->andWhere($nested ? ['entries.sectionId' => null] : ['not', ['entries.sectionId' => null]]);

		$results = (new Query())
			->select([
				'sectionId',
				'typeId',
				'fieldId',
				'total' => new Expression('SUM([[live]])'),
				'enabled' => new Expression('SUM([[enabled]])'),
				'pending' => new Expression('SUM(CASE WHEN [[enabled]] = 1 AND [[postDate]] > :now THEN 1 ELSE 0 END)', [':now' => $now]),
				'expired' => new Expression('SUM(CASE WHEN [[enabled]] = 1 AND [[expiryDate]] <= :now THEN 1 ELSE 0 END)', [':now' => $now]),
				'drafts' => new Expression('SUM([[draft]])'),
				'lastUpdated' => new Expression('MAX(CASE WHEN [[live]] = 1 THEN [[lastEdited]] END)'),
			])
			->from(['hubEntryRows' => $this->keepApart($entries)])
			->groupBy(['sectionId', 'typeId', 'fieldId'])
			->all();

		$rows = [];

		foreach ($results as $result) {
			$stats = new EntryStatistics();
			$stats->total = (int)$result['total'];
			$stats->enabled = (int)$result['enabled'];
			$stats->pending = (int)$result['pending'];
			$stats->expired = (int)$result['expired'];
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
	 * @return array<int,array{fieldId:int,sectionId:?int,volumeId:?int,stats:ReferenceStatistics}>
	 */
	public function getReferenceRows(array $siteIds): array
	{
		$key = $this->cacheKey($siteIds);

		if (isset($this->referenceRows[$key])) {
			return $this->referenceRows[$key];
		}

		if ($this->snapshot !== null) {
			return $this->referenceRows[$key] = $this->snapshotReferenceRows($siteIds);
		}

		$results = $this->referenceQuery()
			->andWhere([
				'or',
				['relations.sourceSiteId' => null],
				['relations.sourceSiteId' => $siteIds],
			])
			->groupBy(['relations.fieldId', 'targets.sectionId', 'targetAssets.volumeId'])
			->all();

		$rows = [];

		foreach ($results as $result) {
			$stats = new ReferenceStatistics();
			$stats->references = (int)$result['references'];
			$stats->sources = (int)$result['sources'];
			$stats->targets = (int)$result['targets'];

			$rows[] = [
				'fieldId' => (int)$result['fieldId'],
				'sectionId' => $result['sectionId'] !== null ? (int)$result['sectionId'] : null,
				'volumeId' => $result['volumeId'] !== null ? (int)$result['volumeId'] : null,
				'stats' => $stats,
			];
		}

		return $this->referenceRows[$key] = $rows;
	}

	/**
	 * Sums entry stats for all rows matching the given criteria.
	 *
	 * @param int[] $siteIds
	 */
	public function sumEntries(array $siteIds, ?int $sectionId = null, ?int $typeId = null, ?int $fieldId = null): EntryStatistics
	{
		// Entries of a section are section entries, and those of a field are nested ones, so only those are read.
		$nested = $sectionId !== null ? false : ($fieldId !== null ? true : null);

		$sums = $this->entrySums[$this->cacheKey($siteIds) . ':' . var_export($nested, true)] ??= Aggregator::index(
			$this->getEntryRows($siteIds, $nested),
			['sectionId', 'typeId', 'fieldId'],
			fn (?EntryStatistics $sum, array $row) => ($sum ?? new EntryStatistics())->add($row['stats']),
		);

		$sum = $sums[Aggregator::key([$sectionId, $typeId, $fieldId])] ?? null;

		return $sum !== null ? clone $sum : new EntryStatistics();
	}

	/**
	 * Sums reference stats for all rows matching the given criteria.
	 *
	 * @param int[] $siteIds
	 */
	public function sumReferences(array $siteIds, ?int $fieldId = null, ?int $sectionId = null, ?int $volumeId = null): ReferenceStatistics
	{
		$sums = $this->referenceSums[$this->cacheKey($siteIds)] ??= Aggregator::index(
			$this->getReferenceRows($siteIds),
			['fieldId', 'sectionId', 'volumeId'],
			fn (?ReferenceStatistics $sum, array $row) => ($sum ?? new ReferenceStatistics())->add($row['stats']),
		);

		$sum = $sums[Aggregator::key([$fieldId, $sectionId, $volumeId])] ?? null;

		return $sum !== null ? clone $sum : new ReferenceStatistics();
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

		if ($this->snapshot !== null) {
			return $this->assetRows[$key] = $this->snapshotAssetRows($siteIds);
		}

		// Correlated subqueries use the indexes on the asset ID, instead of collecting all relations and alternative texts first.
		// Assets referenced by any live element, through a relation or a reference tag, or the photo of a user.
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

		$tagged = (new Query())
			->from(['hubReferences' => ReferenceService::TABLE])
			->innerJoin(['hubTagSources' => Table::ELEMENTS], '[[hubTagSources.id]] = [[hubReferences.sourceId]]')
			->where('[[hubReferences.targetId]] = [[assets.id]]')
			->andWhere(['hubTagSources.dateDeleted' => null])
			->andWhere(['hubReferences.sourceSiteId' => $siteIds]);

		$isUsed = sprintf(
			'(EXISTS (%s) OR EXISTS (%s) OR [[assets.id]] IN (%s))',
			$used->select(new Expression('1'))->createCommand()->getRawSql(),
			$tagged->select(new Expression('1'))->createCommand()->getRawSql(),
			ReferenceService::photoIdsQuery()->createCommand()->getRawSql(),
		);
		$hasSiteAlt = sprintf('EXISTS (%s)', $alt->select(new Expression('1'))->createCommand()->getRawSql());

		$onSites = (new Query())
			->from(['elements_sites' => Table::ELEMENTS_SITES])
			->where('[[elements_sites.elementId]] = [[elements.id]]')
			->andWhere(['elements_sites.siteId' => $siteIds]);

		$isImage = sprintf("[[assets.kind]] = '%s'", Asset::KIND_IMAGE);

		// A row for each asset, as whether it's used is looked up once for it, rather than for each of the sums.
		$assets = $this->assetQuery()
			->select([
				'volumeId' => 'assets.volumeId',
				'kind' => 'assets.kind',
				'used' => new Expression("CASE WHEN $isUsed THEN 1 ELSE 0 END"),
				'image' => new Expression("CASE WHEN $isImage THEN 1 ELSE 0 END"),
				'missingAlt' => new Expression("CASE WHEN $isImage AND COALESCE([[assets.alt]], '') = '' AND NOT $hasSiteAlt THEN 1 ELSE 0 END"),
				'size' => new Expression('COALESCE([[assets.size]], 0)'),
				'dateCreated' => 'elements.dateCreated',
			])
			->andWhere(['exists', $onSites]);

		$results = (new Query())
			->select([
				'volumeId',
				'kind',
				'total' => new Expression('COUNT(*)'),
				'used' => new Expression('SUM([[used]])'),
				'images' => new Expression('SUM([[image]])'),
				'missingAlt' => new Expression('SUM([[missingAlt]])'),
				'size' => new Expression('SUM([[size]])'),
				'unusedSize' => new Expression('SUM(CASE WHEN [[used]] = 1 THEN 0 ELSE [[size]] END)'),
				'lastUploaded' => new Expression('MAX([[dateCreated]])'),
			])
			->from(['hubAssetRows' => $this->keepApart($assets)])
			->groupBy(['volumeId', 'kind'])
			->all();

		$rows = [];

		foreach ($results as $result) {
			$stats = new AssetStatistics();
			$stats->total = (int)$result['total'];
			$stats->used = (int)$result['used'];
			$stats->images = (int)$result['images'];
			$stats->missingAlt = (int)$result['missingAlt'];
			$stats->size = (int)$result['size'];
			$stats->unusedSize = (int)$result['unusedSize'];
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
	 * The kinds of the assets on the given sites, the ones with the most assets first, without the cost of {@see getAssetRows()}.
	 *
	 * @param int[] $siteIds
	 * @return string[]
	 */
	public function getAssetKinds(array $siteIds): array
	{
		$key = $this->cacheKey($siteIds);

		if (isset($this->assetRows[$key])) {
			$totals = [];
			foreach ($this->assetRows[$key] as $row) {
				$totals[$row['kind']] = ($totals[$row['kind']] ?? 0) + $row['stats']->total;
			}
			arsort($totals);

			return array_map('strval', array_keys($totals));
		}

		return $this->assetKinds[$key] ??= array_map('strval', $this->assetQuery()
			->select(['assets.kind'])
			->andWhere(['exists', (new Query())
				->from(['elements_sites' => Table::ELEMENTS_SITES])
				->where('[[elements_sites.elementId]] = [[elements.id]]')
				->andWhere(['elements_sites.siteId' => $siteIds])])
			->groupBy(['assets.kind'])
			->orderBy(new Expression('COUNT(*) DESC'))
			->column());
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
	 * Statistics counting the rows of a snapshot instead of the content, see SnapshotService. Each row is of the items on
	 * the same set of sites (see SiteSet), so they're counted for any sites, just like the live ones are.
	 *
	 * @param Closure(string):array<int|string,mixed> $rows rows of the snapshot by their kind, loaded once asked for: `entries`
	 * (see collectEntryRows()), `assets` (see collectAssetRows()) or `references` (see collectReferenceRows())
	 */
	public static function fromSnapshot(Closure $rows): self
	{
		$statistics = new self();
		$statistics->snapshot = $rows;

		return $statistics;
	}

	/**
	 * Entry counts of all sites, for a snapshot: by section, entry type and field, like getEntryRows(), and by the set of
	 * sites the entries are on and the set of those they're enabled on, so they can be counted for any sites later.
	 *
	 * @return array<int,array{sectionId:?int,typeId:int,fieldId:?int,sites:string,enabledSites:?string,total:int,enabled:int,pending:int,expired:int,drafts:int,lastUpdated:?string}>
	 */
	public function collectEntryRows(): array
	{
		$isEnabled = '[[elements.draftId]] IS NULL AND [[elements.enabled]] = TRUE';
		$now = Db::prepareDateForDb(new DateTime());

		// Each entry with its sets of sites first, then they're counted by those.
		$entries = $this->entryQuery()->select([
			'sectionId' => 'entries.sectionId',
			'typeId' => 'entries.typeId',
			'fieldId' => 'entries.fieldId',
			'sites' => new Expression(sprintf('(%s)', $this->siteList(false))),
			'enabledSites' => new Expression(sprintf("CASE WHEN $isEnabled THEN (%s) END", $this->siteList(true))),
			'total' => new Expression('CASE WHEN [[elements.draftId]] IS NULL THEN 1 ELSE 0 END'),
			'enabled' => new Expression("CASE WHEN $isEnabled THEN 1 ELSE 0 END"),
			'pending' => new Expression("CASE WHEN $isEnabled AND [[entries.postDate]] > :now THEN 1 ELSE 0 END", [':now' => $now]),
			'expired' => new Expression("CASE WHEN $isEnabled AND [[entries.expiryDate]] <= :now THEN 1 ELSE 0 END", [':now' => $now]),
			'drafts' => new Expression('CASE WHEN ' . self::IS_DRAFT . ' THEN 1 ELSE 0 END'),
			'lastUpdated' => new Expression('CASE WHEN [[elements.draftId]] IS NULL THEN ' . self::LAST_EDITED . ' END'),
		]);

		$results = (new Query())
			->select([
				'sectionId',
				'typeId',
				'fieldId',
				'sites',
				'enabledSites',
				'total' => new Expression('SUM([[total]])'),
				'enabled' => new Expression('SUM([[enabled]])'),
				'pending' => new Expression('SUM([[pending]])'),
				'expired' => new Expression('SUM([[expired]])'),
				'drafts' => new Expression('SUM([[drafts]])'),
				'lastUpdated' => new Expression('MAX([[lastUpdated]])'),
			])
			->from(['hubEntries' => $entries])
			->where(['not', ['sites' => null]])
			->groupBy(['sectionId', 'typeId', 'fieldId', 'sites', 'enabledSites'])
			->all();

		return array_map(fn (array $result) => [
			'sectionId' => $result['sectionId'] !== null ? (int)$result['sectionId'] : null,
			'typeId' => (int)$result['typeId'],
			'fieldId' => $result['fieldId'] !== null ? (int)$result['fieldId'] : null,
			'sites' => (string)SiteSet::format(SiteSet::parse($result['sites'])),
			'enabledSites' => SiteSet::format(SiteSet::parse($result['enabledSites'])),
			'total' => (int)$result['total'],
			'enabled' => (int)$result['enabled'],
			'pending' => (int)$result['pending'],
			'expired' => (int)$result['expired'],
			'drafts' => (int)$result['drafts'],
			'lastUpdated' => $result['lastUpdated'] ?: null,
		], $results);
	}

	/**
	 * Asset counts of all sites, for a snapshot: by volume and file kind, like getAssetRows(), and by the set of sites the
	 * assets are on, the set of those they're used from and the set of those they have alternative text on.
	 *
	 * @return array<int,array{volumeId:int,kind:string,sites:string,usedSites:?string,altSites:?string,total:int,size:int,lastUploaded:?string}>
	 */
	public function collectAssetRows(): array
	{
		// Relations of fields that aren't translatable have no source site, so they count for every site.
		$used = (new Query())
			->select(new Expression($this->concatIds(sprintf('COALESCE([[hubRelations.sourceSiteId]], %d)', SiteSet::ANY), true)))
			->from(['hubRelations' => Table::RELATIONS])
			->innerJoin(['hubSources' => Table::ELEMENTS], '[[hubSources.id]] = [[hubRelations.sourceId]]')
			->where('[[hubRelations.targetId]] = [[assets.id]]')
			->andWhere([
				'hubSources.dateDeleted' => null,
				'hubSources.draftId' => null,
				'hubSources.revisionId' => null,
			]);

		$tagged = (new Query())
			->select(new Expression($this->concatIds('[[hubReferences.sourceSiteId]]', true)))
			->from(['hubReferences' => ReferenceService::TABLE])
			->innerJoin(['hubTagSources' => Table::ELEMENTS], '[[hubTagSources.id]] = [[hubReferences.sourceId]]')
			->where('[[hubReferences.targetId]] = [[assets.id]]')
			->andWhere(['hubTagSources.dateDeleted' => null]);

		// Alternative text of the asset itself counts for every site.
		$alt = (new Query())
			->select(new Expression($this->concatIds('[[hubAssetsSites.siteId]]')))
			->from(['hubAssetsSites' => Table::ASSETS_SITES])
			->where('[[hubAssetsSites.assetId]] = [[assets.id]]')
			->andWhere(['not', ['hubAssetsSites.alt' => null]])
			->andWhere(['not', ['hubAssetsSites.alt' => '']]);

		$assets = $this->assetQuery()->select([
			'volumeId' => 'assets.volumeId',
			'kind' => 'assets.kind',
			'sites' => new Expression(sprintf('(%s)', $this->siteList(false))),
			// Users aren't site-specific, so photos count for every site.
			'usedSites' => new Expression(sprintf(
				"CASE WHEN [[assets.id]] IN (%s) THEN '%d' ELSE (%s) END",
				ReferenceService::photoIdsQuery()->createCommand()->getRawSql(),
				SiteSet::ANY,
				$used->createCommand()->getRawSql(),
			)),
			'taggedSites' => new Expression(sprintf('(%s)', $tagged->createCommand()->getRawSql())),
			'altSites' => new Expression(sprintf("CASE WHEN COALESCE([[assets.alt]], '') <> '' THEN '%d' ELSE (%s) END", SiteSet::ANY, $alt->createCommand()->getRawSql())),
			'size' => new Expression('COALESCE([[assets.size]], 0)'),
			'dateCreated' => 'elements.dateCreated',
		]);

		$results = (new Query())
			->select([
				'volumeId',
				'kind',
				'sites',
				'usedSites',
				'taggedSites',
				'altSites',
				'total' => new Expression('COUNT(*)'),
				'size' => new Expression('SUM([[size]])'),
				'lastUploaded' => new Expression('MAX([[dateCreated]])'),
			])
			->from(['hubAssets' => $assets])
			->where(['not', ['sites' => null]])
			->groupBy(['volumeId', 'kind', 'sites', 'usedSites', 'taggedSites', 'altSites'])
			->all();

		// Assets are used from the sites of their relations and of their reference tags alike, so they're counted by both.
		// Only images can miss alternative text, so the others are kept apart by their sites alone, in fewer rows.
		$rows = [];

		foreach ($results as $result) {
			$row = [
				'volumeId' => (int)$result['volumeId'],
				'kind' => (string)$result['kind'],
				'sites' => (string)SiteSet::format(SiteSet::parse($result['sites'])),
				'usedSites' => SiteSet::union($result['usedSites'], $result['taggedSites']),
				'altSites' => $result['kind'] === Asset::KIND_IMAGE ? SiteSet::format(SiteSet::parse($result['altSites'])) : null,
			];
			$key = implode('|', array_map(fn ($value) => (string)$value, $row));
			$lastUploaded = $result['lastUploaded'] ?: null;

			$rows[$key] ??= [...$row, 'total' => 0, 'size' => 0, 'lastUploaded' => null];
			$rows[$key]['total'] += (int)$result['total'];
			$rows[$key]['size'] += (int)$result['size'];
			$rows[$key]['lastUploaded'] = max($rows[$key]['lastUploaded'], $lastUploaded);
		}

		return array_values($rows);
	}

	/**
	 * Reference counts of all sites, for a snapshot: by field and target section or volume, like getReferenceRows(), and by
	 * the site of their source, null for relations of fields that aren't translatable.
	 *
	 * @return array<int,array{fieldId:int,sectionId:?int,volumeId:?int,siteId:?int,references:int,sources:int,targets:int}>
	 */
	public function collectReferenceRows(): array
	{
		$results = $this->referenceQuery()
			->addSelect(['siteId' => 'relations.sourceSiteId'])
			->groupBy(['relations.fieldId', 'targets.sectionId', 'targetAssets.volumeId', 'relations.sourceSiteId'])
			->all();

		return array_map(fn (array $result) => [
			'fieldId' => (int)$result['fieldId'],
			'sectionId' => $result['sectionId'] !== null ? (int)$result['sectionId'] : null,
			'volumeId' => $result['volumeId'] !== null ? (int)$result['volumeId'] : null,
			'siteId' => $result['siteId'] !== null ? (int)$result['siteId'] : null,
			'references' => (int)$result['references'],
			'sources' => (int)$result['sources'],
			'targets' => (int)$result['targets'],
		], $results);
	}

	/**
	 * Entries that are content of their own, on any site: neither deleted nor revisions, nor nested entries owned by drafts
	 * or revisions, which are copies.
	 */
	private function entryQuery(): Query
	{
		return (new Query())
			->from(['entries' => Table::ENTRIES])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
			->leftJoin(['drafts' => Table::DRAFTS], '[[drafts.id]] = [[elements.draftId]]')
			->leftJoin(['edits' => EditService::TABLE], '[[edits.elementId]] = [[elements.id]]')
			->leftJoin(['owners' => Table::ELEMENTS], '[[owners.id]] = [[entries.primaryOwnerId]]')
			->where([
				'elements.dateDeleted' => null,
				'elements.revisionId' => null,
			])
			->andWhere([
				'or',
				['entries.primaryOwnerId' => null],
				['owners.dateDeleted' => null, 'owners.draftId' => null, 'owners.revisionId' => null],
			]);
	}

	/**
	 * Keeps a subquery of a row for each item apart from the sums over it. PostgreSQL pulls it up into them otherwise,
	 * evaluating its columns again for each sum referring to them; MySQL keeps subqueries apart that select subqueries already.
	 */
	private function keepApart(Query $rows): Query
	{
		return Craft::$app->getDb()->getIsPgsql() ? $rows->offset(new Expression('0')) : $rows;
	}

	/**
	 * Assets of volumes, on any site, apart from deleted ones, drafts and revisions.
	 */
	private function assetQuery(): Query
	{
		return (new Query())
			->from(['assets' => Table::ASSETS])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[assets.id]]')
			->where([
				'elements.dateDeleted' => null,
				'elements.draftId' => null,
				'elements.revisionId' => null,
			])
			->andWhere(['not', ['assets.volumeId' => null]]);
	}

	/**
	 * Relations from live elements to elements that aren't deleted, from any site, counted by field and target section or
	 * volume.
	 */
	private function referenceQuery(): Query
	{
		return (new Query())
			->select([
				'fieldId' => 'relations.fieldId',
				'sectionId' => 'targets.sectionId',
				'volumeId' => 'targetAssets.volumeId',
				'references' => new Expression('COUNT(*)'),
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
			]);
	}

	/**
	 * SQL of the set of sites the element of `elements` is on (see SiteSet), or of those it's enabled on.
	 */
	private function siteList(bool $enabled): string
	{
		$query = (new Query())
			->select(new Expression($this->concatIds('[[hubElementsSites.siteId]]')))
			->from(['hubElementsSites' => Table::ELEMENTS_SITES])
			->where('[[hubElementsSites.elementId]] = [[elements.id]]');

		if ($enabled) {
			$query->andWhere(['hubElementsSites.enabled' => true]);
		}

		return $query->createCommand()->getRawSql();
	}

	/**
	 * SQL aggregating the IDs of a column into a comma-separated list, sorted, so the same IDs always make the same list.
	 * MySQL's lists are cut off at its `group_concat_max_len`, which fits about 200 sites by default.
	 */
	private function concatIds(string $column, bool $distinct = false): string
	{
		$distinct = $distinct ? 'DISTINCT ' : '';

		if (Craft::$app->getDb()->getIsPgsql()) {
			// PostgreSQL sorts distinct values by the aggregated expression only.
			return "STRING_AGG({$distinct}CAST($column AS VARCHAR), ',' ORDER BY CAST($column AS VARCHAR))";
		}

		return "GROUP_CONCAT({$distinct}$column ORDER BY $column SEPARATOR ',')";
	}

	/**
	 * Entry rows of a snapshot counted for the sites, like getEntryRows() counts the live ones.
	 *
	 * @param int[] $siteIds
	 * @return array<int,array{sectionId:?int,typeId:int,fieldId:?int,stats:EntryStatistics}>
	 */
	private function snapshotEntryRows(array $siteIds, bool $nested): array
	{
		$rows = [];

		foreach (($this->snapshot)('entries') as $row) {
			$sectionId = $row['sectionId'] !== null ? (int)$row['sectionId'] : null;

			if (($sectionId === null) !== $nested || !SiteSet::matches($row['sites'], $siteIds)) {
				continue;
			}

			$stats = new EntryStatistics();
			$stats->total = (int)$row['total'];
			$stats->drafts = (int)$row['drafts'];
			$stats->lastUpdated = $row['lastUpdated'] ? (DateTimeHelper::toDateTime($row['lastUpdated']) ?: null) : null;

			// Entries are enabled on the sites when they're enabled on any of them.
			if (SiteSet::matches($row['enabledSites'], $siteIds)) {
				$stats->enabled = (int)$row['enabled'];
				$stats->pending = (int)$row['pending'];
				$stats->expired = (int)$row['expired'];
			}

			$criteria = [$sectionId, (int)$row['typeId'], $row['fieldId'] !== null ? (int)$row['fieldId'] : null];
			$rows[Aggregator::key($criteria)] ??= [
				'sectionId' => $criteria[0],
				'typeId' => $criteria[1],
				'fieldId' => $criteria[2],
				'stats' => new EntryStatistics(),
			];
			$rows[Aggregator::key($criteria)]['stats']->add($stats);
		}

		return array_values($rows);
	}

	/**
	 * Asset rows of a snapshot counted for the sites, like getAssetRows() counts the live ones.
	 *
	 * @param int[] $siteIds
	 * @return array<int,array{volumeId:int,kind:string,stats:AssetStatistics}>
	 */
	private function snapshotAssetRows(array $siteIds): array
	{
		$rows = [];

		foreach (($this->snapshot)('assets') as $row) {
			if (!SiteSet::matches($row['sites'], $siteIds)) {
				continue;
			}

			$used = SiteSet::matches($row['usedSites'], $siteIds);
			$isImage = $row['kind'] === Asset::KIND_IMAGE;

			$stats = new AssetStatistics();
			$stats->total = (int)$row['total'];
			$stats->used = $used ? $stats->total : 0;
			$stats->images = $isImage ? $stats->total : 0;
			$stats->missingAlt = $isImage && !SiteSet::matches($row['altSites'], $siteIds) ? $stats->total : 0;
			$stats->size = (int)$row['size'];
			$stats->unusedSize = $used ? 0 : $stats->size;
			$stats->lastUploaded = $row['lastUploaded'] ? (DateTimeHelper::toDateTime($row['lastUploaded']) ?: null) : null;

			$key = Aggregator::key([(int)$row['volumeId'], (string)$row['kind']]);
			$rows[$key] ??= ['volumeId' => (int)$row['volumeId'], 'kind' => (string)$row['kind'], 'stats' => new AssetStatistics()];
			$rows[$key]['stats']->add($stats);
		}

		return array_values($rows);
	}

	/**
	 * Reference rows of a snapshot counted for the sites, like getReferenceRows() counts the live ones. Sources and targets
	 * are counted once per site, so they're summed for several sites, and may count the same element more than once then.
	 *
	 * @param int[] $siteIds
	 * @return array<int,array{fieldId:int,sectionId:?int,volumeId:?int,stats:ReferenceStatistics}>
	 */
	private function snapshotReferenceRows(array $siteIds): array
	{
		$rows = [];

		foreach (($this->snapshot)('references') as $row) {
			if ($row['siteId'] !== null && !in_array((int)$row['siteId'], $siteIds, false)) {
				continue;
			}

			$stats = new ReferenceStatistics();
			$stats->references = (int)$row['references'];
			$stats->sources = (int)$row['sources'];
			$stats->targets = (int)$row['targets'];

			$criteria = [
				(int)$row['fieldId'],
				$row['sectionId'] !== null ? (int)$row['sectionId'] : null,
				$row['volumeId'] !== null ? (int)$row['volumeId'] : null,
			];
			$rows[Aggregator::key($criteria)] ??= [
				'fieldId' => $criteria[0],
				'sectionId' => $criteria[1],
				'volumeId' => $criteria[2],
				'stats' => new ReferenceStatistics(),
			];
			$rows[Aggregator::key($criteria)]['stats']->add($stats);
		}

		return array_values($rows);
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
