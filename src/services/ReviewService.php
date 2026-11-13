<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use DateTime;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\helpers\Aggregator;
use SomehowDigital\Craft\Hub\helpers\Row;
use yii\base\Component;

/**
 * Queries for section entries and assets worth reviewing for cleanup.
 *
 * @internal
 */
final class ReviewService extends Component
{
	private const ENTRY_GROUPS = ['sectionId' => 'entries.sectionId', 'typeId' => 'entries.typeId'];

	/** @var array<string,array<string,int>> grouped counts of the review queries, keyed by query and site scope */
	private array $counts = [];

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly SiteScopeService $siteScope,
		private readonly FilterService $filters,
		private readonly QueryService $queries,
		private readonly EditService $edits,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * Number of stale entries, counted for all sections and entry types at once.
	 */
	public function countStale(int $months, ?int $sectionId = null, ?int $typeId = null): int
	{
		return $this->countBy("stale:{$months}", fn(): EntryQuery => $this->getStaleQuery($months), self::ENTRY_GROUPS, [$sectionId, $typeId]);
	}

	/**
	 * Entries with drafts that aren't provisional, as `canonicalId`, found in the drafts alone.
	 * Finding them through the elements would visit each revision of each entry too.
	 *
	 * @return Query<array-key,mixed>
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
	 * @return list<mixed>
	 */
	public function draftsCondition(): array
	{
		return ['and', ['in', 'elements.id', $this->draftedQuery()], ['exists', $this->draftsQuery()]];
	}

	/**
	 * Drafts of the queried entry on its site; provisional drafts are unsaved changes of a single user, so they are left out.
	 *
	 * @return Query<array-key,mixed>
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
	 * Condition matching the queried entries with drafts a user created, including their unsaved changes, which are
	 * provisional drafts of that user alone.
	 *
	 * @return list<mixed>
	 */
	public function userDraftsCondition(int $userId): array
	{
		$drafts = (new Query())
			->select(['hubUserDrafts.canonicalId'])
			->from(['hubUserDrafts' => Table::DRAFTS])
			->innerJoin(['hubUserDraftsElements' => Table::ELEMENTS], '[[hubUserDraftsElements.draftId]] = [[hubUserDrafts.id]]')
			->where(['hubUserDrafts.creatorId' => $userId, 'hubUserDraftsElements.dateDeleted' => null])
			->andWhere(['not', ['hubUserDrafts.canonicalId' => null]]);

		return ['in', 'elements.id', $drafts];
	}

	/**
	 * Condition matching images of the queried assets without alternative text on any of the counted sites.
	 *
	 * @return list<mixed>
	 */
	public function missingAltCondition(): array
	{
		$siteAlt = (new Query())
			->from(['hubAssetsSites' => Table::ASSETS_SITES])
			->where('[[hubAssetsSites.assetId]] = [[assets.id]]')
			->andWhere(['hubAssetsSites.siteId' => $this->siteScope->getSiteIds()])
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
	 * @return list<mixed>
	 */
	public function largeImageCondition(): array
	{
		$pixels = $this->filters->getThreshold(CheckKind::Assets, 'oversized');

		return [
			'and',
			['assets.kind' => Asset::KIND_IMAGE],
			['or', ['>', 'assets.width', $pixels], ['>', 'assets.height', $pixels]],
		];
	}

	/**
	 * Live relations targeting the queried element.
	 *
	 * @return Query<array-key,mixed>
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
	 * Live reference tags targeting the queried element; drafts and revisions aren't indexed.
	 *
	 * @return Query<array-key,mixed>
	 */
	public function tagReferencesQuery(): Query
	{
		return (new Query())
			->from(['hubReferences' => Tables::REFERENCES])
			->innerJoin(['hubTagSources' => Table::ELEMENTS], '[[hubTagSources.id]] = [[hubReferences.sourceId]]')
			->where('[[hubReferences.targetId]] = [[elements.id]]')
			->andWhere(['hubTagSources.dateDeleted' => null]);
	}

	/**
	 * Condition matching the queried elements that are referenced, through a relation field or a reference tag, or as the
	 * photo of a user.
	 *
	 * @param bool $photos whether the elements may be photos, which only assets are
	 * @return list<mixed>
	 */
	public function referencedCondition(bool $photos = true): array
	{
		return [
			'or',
			['exists', $this->referencesQuery()],
			['exists', $this->tagReferencesQuery()],
			...($photos ? [['in', 'elements.id', ReferenceService::photoIdsQuery()]] : []),
		];
	}

	/**
	 * Condition matching the queried elements that aren't referenced. Its parts are spelled out rather than negating
	 * {@see referencedCondition()}, as databases can only run each of them as an anti join on its own.
	 *
	 * @param bool $photos whether the elements may be photos, which only assets are
	 * @return list<mixed>
	 */
	public function unreferencedCondition(bool $photos = true): array
	{
		return [
			'and',
			['not exists', $this->referencesQuery()],
			['not exists', $this->tagReferencesQuery()],
			...($photos ? [['not in', 'elements.id', ReferenceService::photoIdsQuery()]] : []),
		];
	}

	/**
	 * SQL of the number of live references targeting the queried element, relations, reference tags and user photos alike,
	 * for conditions and sorting. Users are only counted for the few elements that are photos, as `photoId` has no index.
	 *
	 * @param bool $photos whether the elements may be photos, which only assets are
	 */
	public function referenceCountSql(bool $photos = true): string
	{
		return sprintf(
			'((%s) + (%s)%s)',
			$this->referencesQuery()->select('COUNT(*)')->createCommand()->getRawSql(),
			$this->tagReferencesQuery()->select('COUNT(*)')->createCommand()->getRawSql(),
			$photos ? sprintf(
				' + CASE WHEN [[elements.id]] IN (%s) THEN (%s) ELSE 0 END',
				ReferenceService::photoIdsQuery()->createCommand()->getRawSql(),
				ReferenceService::photosQuery('[[elements.id]]')->select('COUNT(*)')->createCommand()->getRawSql(),
			) : '',
		);
	}

	/**
	 * Counts the elements of a review query by the given columns in a single query, and sums the counts matching the criteria.
	 *
	 * @param callable():ElementQuery<array-key,covariant ElementInterface> $query
	 * @param array<string,string> $columns alias => column to group by
	 * @param array<int,int|null> $criteria a value for each column, or null for any value
	 */
	private function countBy(string $name, callable $query, array $columns, array $criteria): int
	{
		$key = $name . ':' . implode(',', $this->siteScope->getSiteIds());

		if (!isset($this->counts[$key])) {
			$queries = $this->queries;
			$this->counts[$key] = Aggregator::index($queries->count($query(), $columns), array_keys($columns), fn(?int $sum, array $row): int => ($sum ?? 0) + Row::int($row, 'total'));
		}

		return $this->counts[$key][Aggregator::key($criteria)] ?? 0;
	}

	/**
	 * Entries that no person has edited within the given number of months, nested ones included, least recently edited first.
	 *
	 * @see EditService
	 *
	 * @return EntryQuery<array-key,Entry>
	 */
	public function getStaleQuery(int $months, ?int $sectionId = null, ?int $typeId = null, ?int $fieldId = null): EntryQuery
	{
		$edits = $this->edits;
		$date = (new DateTime())->modify(sprintf('-%d months', $months));

		return $this->createQuery($sectionId, $typeId, $fieldId)
			->andWhere($edits->lastEditedCondition('< ' . $date->format(DateTime::ATOM)) ?? [])
			->orderBy([$edits->lastEditedSql() => SORT_ASC, 'elements.id' => SORT_ASC]);
	}

	/**
	 * Condition leaving out the nested entries owned by drafts or revisions, which are copies, not content of their own.
	 * Owners are looked up by their primary key for each entry on purpose: other forms are faster on some data, but need
	 * memory that grows with all elements, drafts and revisions, failing or taking minutes on large sites.
	 *
	 * @return list<mixed>
	 */
	public function contentCondition(): array
	{
		return [
			'or',
			['entries.primaryOwnerId' => null],
			['exists', (new Query())
				->from(['hubOwners' => Table::ELEMENTS])
				->where('[[hubOwners.id]] = [[entries.primaryOwnerId]]')
				->andWhere(['hubOwners.dateDeleted' => null, 'hubOwners.draftId' => null, 'hubOwners.revisionId' => null])],
		];
	}

	/**
	 * @return EntryQuery<array-key,Entry>
	 */
	private function createQuery(?int $sectionId, ?int $typeId, ?int $fieldId = null): EntryQuery
	{
		$query = Entry::find()
			->siteId($this->siteScope->getSiteIds())
			->unique()
			->status(null)
			->andWhere($this->contentCondition())
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
