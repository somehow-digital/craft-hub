<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use Closure;
use craft\db\Connection;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\Db;
use DateTime;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\helpers\Aggregator;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\helpers\SiteSet;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\ReferenceStatistics;
use yii\base\Component;
use yii\db\Expression;

/**
 * Aggregated entry, asset and reference counts, computed with a few grouped queries per set of sites.
 *
 * The statistics of a snapshot (see withSnapshot()) count the rows the snapshot kept instead, summed the same way.
 *
 * @internal
 */
final class StatisticsService extends Component
{
	/** entries that are drafts, apart from provisional ones and unsaved drafts of new entries, like Craft's element indexes */
	private const IS_DRAFT = '[[drafts.provisional]] = FALSE AND ([[elements.canonicalId]] IS NOT NULL OR [[drafts.saved]] = TRUE)';

	/** when a person last edited an entry, see EditService */
	private const LAST_EDITED = 'COALESCE([[edits.dateEdited]], [[elements.dateUpdated]])';

	/** @var (Closure(string):array<int|string,mixed>)|null rows of a snapshot by their kind, see withSnapshot() */
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
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly SiteScopeService $siteScope,
		private readonly Connection $db,
		array $config = [],
	) {
		parent::__construct($config);
	}

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

		if ($this->snapshot instanceof Closure) {
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
				'enabled' => new Expression("CASE WHEN {$isEnabled} THEN 1 ELSE 0 END"),
				'draft' => new Expression('CASE WHEN ' . self::IS_DRAFT . ' THEN 1 ELSE 0 END'),
				'lastEdited' => new Expression(self::LAST_EDITED),
			])
			->andWhere($this->onSitesCondition($siteIds, $onSites))
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
			$stats->total = Row::int($result, 'total');
			$stats->enabled = Row::int($result, 'enabled');
			$stats->pending = Row::int($result, 'pending');
			$stats->expired = Row::int($result, 'expired');
			$stats->drafts = Row::int($result, 'drafts');
			$stats->lastUpdated = Row::date($result, 'lastUpdated');

			$rows[] = [
				'sectionId' => Row::intOrNull($result, 'sectionId'),
				'typeId' => Row::int($result, 'typeId'),
				'fieldId' => Row::intOrNull($result, 'fieldId'),
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

		if ($this->snapshot instanceof Closure) {
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
			$stats->references = Row::int($result, 'references');
			$stats->sources = Row::int($result, 'sources');
			$stats->targets = Row::int($result, 'targets');

			$rows[] = [
				'fieldId' => Row::int($result, 'fieldId'),
				'sectionId' => Row::intOrNull($result, 'sectionId'),
				'volumeId' => Row::intOrNull($result, 'volumeId'),
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

		$sums = $this->entrySums[$this->cacheKey($siteIds) . ':' . json_encode($nested, JSON_THROW_ON_ERROR)] ??= Aggregator::index(
			$this->getEntryRows($siteIds, $nested),
			['sectionId', 'typeId', 'fieldId'],
			fn(?EntryStatistics $sum, array $row): EntryStatistics => ($sum ?? new EntryStatistics())->add(Row::instance($row, 'stats', EntryStatistics::class)),
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
			fn(?ReferenceStatistics $sum, array $row): ReferenceStatistics => ($sum ?? new ReferenceStatistics())->add(Row::instance($row, 'stats', ReferenceStatistics::class)),
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

		if ($this->snapshot instanceof Closure) {
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
			->from(['hubReferences' => Tables::REFERENCES])
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
				'used' => new Expression("CASE WHEN {$isUsed} THEN 1 ELSE 0 END"),
				'image' => new Expression("CASE WHEN {$isImage} THEN 1 ELSE 0 END"),
				'missingAlt' => new Expression("CASE WHEN {$isImage} AND COALESCE([[assets.alt]], '') = '' AND NOT {$hasSiteAlt} THEN 1 ELSE 0 END"),
				'size' => new Expression('COALESCE([[assets.size]], 0)'),
				'dateCreated' => 'elements.dateCreated',
			])
			->andWhere($this->onSitesCondition($siteIds, $onSites));

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
			$stats->total = Row::int($result, 'total');
			$stats->used = Row::int($result, 'used');
			$stats->images = Row::int($result, 'images');
			$stats->missingAlt = Row::int($result, 'missingAlt');
			$stats->size = Row::int($result, 'size');
			$stats->unusedSize = Row::int($result, 'unusedSize');
			$stats->lastUploaded = Row::date($result, 'lastUploaded');

			$rows[] = [
				'volumeId' => Row::int($result, 'volumeId'),
				'kind' => Row::string($result, 'kind'),
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

			return array_map(strval(...), array_keys($totals));
		}

		return $this->assetKinds[$key] ??= array_map(static fn(mixed $kind): string => Fill::text($kind) ?? '', $this->assetQuery()
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
			fn(?AssetStatistics $sum, array $row): AssetStatistics => ($sum ?? new AssetStatistics())->add(Row::instance($row, 'stats', AssetStatistics::class)),
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
	public function withSnapshot(Closure $rows): self
	{
		$statistics = new self($this->siteScope, $this->db);
		$statistics->snapshot = $rows;

		return $statistics;
	}

	/**
	 * Entry counts of all sites, for a snapshot: by section, entry type and field, like getEntryRows(), and by the set of
	 * sites the entries are on and the set of those they're enabled on, so they can be counted for any sites later.
	 *
	 * @return list<array{sectionId:?int,typeId:int,fieldId:?int,sites:string,enabledSites:?string,total:int,enabled:int,pending:int,expired:int,drafts:int,lastUpdated:?string}>
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
			'enabledSites' => new Expression(sprintf("CASE WHEN {$isEnabled} THEN (%s) END", $this->siteList(true))),
			'total' => new Expression('CASE WHEN [[elements.draftId]] IS NULL THEN 1 ELSE 0 END'),
			'enabled' => new Expression("CASE WHEN {$isEnabled} THEN 1 ELSE 0 END"),
			'pending' => new Expression("CASE WHEN {$isEnabled} AND [[entries.postDate]] > :now THEN 1 ELSE 0 END", [':now' => $now]),
			'expired' => new Expression("CASE WHEN {$isEnabled} AND [[entries.expiryDate]] <= :now THEN 1 ELSE 0 END", [':now' => $now]),
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

		return array_values(array_map(fn(array $result): array => [
			'sectionId' => Row::intOrNull($result, 'sectionId'),
			'typeId' => Row::int($result, 'typeId'),
			'fieldId' => Row::intOrNull($result, 'fieldId'),
			'sites' => (string) SiteSet::format(SiteSet::parse($result['sites'])),
			'enabledSites' => SiteSet::format(SiteSet::parse($result['enabledSites'])),
			'total' => Row::int($result, 'total'),
			'enabled' => Row::int($result, 'enabled'),
			'pending' => Row::int($result, 'pending'),
			'expired' => Row::int($result, 'expired'),
			'drafts' => Row::int($result, 'drafts'),
			'lastUpdated' => Fill::text($result['lastUpdated']),
		], $results));
	}

	/**
	 * Asset counts of all sites, for a snapshot: by volume and file kind, like getAssetRows(), and by the set of sites the
	 * assets are on, the set of those they're used from and the set of those they have alternative text on.
	 *
	 * @return list<array{volumeId:int,kind:string,sites:string,usedSites:?string,altSites:?string,total:int,size:int,lastUploaded:?string}>
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
			->from(['hubReferences' => Tables::REFERENCES])
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
				'volumeId' => Row::int($result, 'volumeId'),
				'kind' => Row::string($result, 'kind'),
				'sites' => (string) SiteSet::format(SiteSet::parse(Row::value($result, 'sites'))),
				'usedSites' => SiteSet::union(Row::value($result, 'usedSites'), Row::value($result, 'taggedSites')),
				'altSites' => Row::value($result, 'kind') === Asset::KIND_IMAGE ? SiteSet::format(SiteSet::parse(Row::value($result, 'altSites'))) : null,
			];
			$key = implode('|', array_map(fn(int|string|null $value): string => (string) $value, $row));
			$lastUploaded = Fill::text(Row::value($result, 'lastUploaded'));

			$rows[$key] ??= [...$row, 'total' => 0, 'size' => 0, 'lastUploaded' => null];
			$rows[$key]['total'] += Row::int($result, 'total');
			$rows[$key]['size'] += Row::int($result, 'size');
			$rows[$key]['lastUploaded'] = max($rows[$key]['lastUploaded'], $lastUploaded);
		}

		return array_values($rows);
	}

	/**
	 * Reference counts of all sites, for a snapshot: by field and target section or volume, like getReferenceRows(), and by
	 * the site of their source, null for relations of fields that aren't translatable.
	 *
	 * @return list<array{fieldId:int,sectionId:?int,volumeId:?int,siteId:?int,references:int,sources:int,targets:int}>
	 */
	public function collectReferenceRows(): array
	{
		$results = $this->referenceQuery()
			->addSelect(['siteId' => 'relations.sourceSiteId'])
			->groupBy(['relations.fieldId', 'targets.sectionId', 'targetAssets.volumeId', 'relations.sourceSiteId'])
			->all();

		return array_values(array_map(fn(array $result): array => [
			'fieldId' => Row::int($result, 'fieldId'),
			'sectionId' => Row::intOrNull($result, 'sectionId'),
			'volumeId' => Row::intOrNull($result, 'volumeId'),
			'siteId' => Row::intOrNull($result, 'siteId'),
			'references' => Row::int($result, 'references'),
			'sources' => Row::int($result, 'sources'),
			'targets' => Row::int($result, 'targets'),
		], $results));
	}

	/**
	 * Entries that are content of their own, on any site: neither deleted nor revisions, nor nested entries owned by drafts
	 * or revisions, which are copies.
	 *
	 * @return Query<array-key,mixed>
	 */
	private function entryQuery(): Query
	{
		return (new Query())
			->from(['entries' => Table::ENTRIES])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
			->leftJoin(['drafts' => Table::DRAFTS], '[[drafts.id]] = [[elements.draftId]]')
			->leftJoin(['edits' => Tables::EDITS], '[[edits.elementId]] = [[elements.id]]')
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
	 *
	 * @param Query<array-key,mixed> $rows
	 * @return Query<array-key,mixed>
	 */
	private function keepApart(Query $rows): Query
	{
		return $this->db->getIsPgsql() ? $rows->offset(new Expression('0')) : $rows;
	}

	/**
	 * Assets of volumes, on any site, apart from deleted ones, drafts and revisions.
	 *
	 * @return Query<array-key,mixed>
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
	 *
	 * @return Query<array-key,mixed>
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
		$keyword = $distinct ? 'DISTINCT ' : '';

		if ($this->db->getIsPgsql()) {
			// PostgreSQL sorts distinct values by the aggregated expression only.
			return "STRING_AGG({$keyword}CAST({$column} AS VARCHAR), ',' ORDER BY CAST({$column} AS VARCHAR))";
		}

		return "GROUP_CONCAT({$keyword}$column ORDER BY {$column} SEPARATOR ',')";
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function snapshotRows(string $kind): array
	{
		/** @var list<array<string,mixed>> $rows */
		$rows = $this->snapshot instanceof Closure ? array_values(($this->snapshot)($kind)) : [];

		return $rows;
	}

	/**
	 * Entry rows of a snapshot counted for the sites, like getEntryRows() counts the live ones.
	 *
	 * @param int[] $siteIds
	 * @return list<array{sectionId:?int,typeId:int,fieldId:?int,stats:EntryStatistics}>
	 */
	private function snapshotEntryRows(array $siteIds, bool $nested): array
	{
		$rows = [];

		foreach ($this->snapshotRows('entries') as $row) {
			$sectionId = Row::intOrNull($row, 'sectionId');

			if (($sectionId === null) !== $nested || !SiteSet::matches($row['sites'], $siteIds)) {
				continue;
			}

			$stats = new EntryStatistics();
			$stats->total = Row::int($row, 'total');
			$stats->drafts = Row::int($row, 'drafts');
			$stats->lastUpdated = Row::date($row, 'lastUpdated');

			// Entries are enabled on the sites when they're enabled on any of them.
			if (SiteSet::matches($row['enabledSites'], $siteIds)) {
				$stats->enabled = Row::int($row, 'enabled');
				$stats->pending = Row::int($row, 'pending');
				$stats->expired = Row::int($row, 'expired');
			}

			$criteria = [$sectionId, Row::int($row, 'typeId'), Row::intOrNull($row, 'fieldId')];
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
	 * @return list<array{volumeId:int,kind:string,stats:AssetStatistics}>
	 */
	private function snapshotAssetRows(array $siteIds): array
	{
		$rows = [];

		foreach ($this->snapshotRows('assets') as $row) {
			if (!SiteSet::matches($row['sites'], $siteIds)) {
				continue;
			}

			$used = SiteSet::matches($row['usedSites'], $siteIds);
			$isImage = $row['kind'] === Asset::KIND_IMAGE;

			$stats = new AssetStatistics();
			$stats->total = Row::int($row, 'total');
			$stats->used = $used ? $stats->total : 0;
			$stats->images = $isImage ? $stats->total : 0;
			$stats->missingAlt = $isImage && !SiteSet::matches($row['altSites'], $siteIds) ? $stats->total : 0;
			$stats->size = Row::int($row, 'size');
			$stats->unusedSize = $used ? 0 : $stats->size;
			$stats->lastUploaded = Row::date($row, 'lastUploaded');

			$key = Aggregator::key([Row::int($row, 'volumeId'), Row::string($row, 'kind')]);
			$rows[$key] ??= ['volumeId' => Row::int($row, 'volumeId'), 'kind' => Row::string($row, 'kind'), 'stats' => new AssetStatistics()];
			$rows[$key]['stats']->add($stats);
		}

		return array_values($rows);
	}

	/**
	 * Reference rows of a snapshot counted for the sites, like getReferenceRows() counts the live ones. Sources and targets
	 * are counted once per site, so they're summed for several sites, and may count the same element more than once then.
	 *
	 * @param int[] $siteIds
	 * @return list<array{fieldId:int,sectionId:?int,volumeId:?int,stats:ReferenceStatistics}>
	 */
	private function snapshotReferenceRows(array $siteIds): array
	{
		$rows = [];

		foreach ($this->snapshotRows('references') as $row) {
			if ($row['siteId'] !== null && !in_array(Row::int($row, 'siteId'), $siteIds, true)) {
				continue;
			}

			$stats = new ReferenceStatistics();
			$stats->references = Row::int($row, 'references');
			$stats->sources = Row::int($row, 'sources');
			$stats->targets = Row::int($row, 'targets');

			$criteria = [
				Row::int($row, 'fieldId'),
				Row::intOrNull($row, 'sectionId'),
				Row::intOrNull($row, 'volumeId'),
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

	/**
	 * Condition matching the elements on any of the sites, or nothing on all sites, which every element is on one of.
	 *
	 * @param int[] $siteIds
	 * @return list<mixed>
	 *
	 * @param Query<array-key,mixed> $onSites
	 */
	private function onSitesCondition(array $siteIds, Query $onSites): array
	{
		return $this->siteScope->coversAllSites($siteIds) ? [] : ['exists', $onSites];
	}
}
