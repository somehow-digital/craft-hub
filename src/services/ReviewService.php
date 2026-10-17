<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use DateTime;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\helpers\Aggregator;
use SomehowDigital\Craft\Hub\Hub;
use yii\base\Component;

/**
 * Queries for section entries and assets worth reviewing for cleanup.
 */
class ReviewService extends Component
{
	private const ENTRY_GROUPS = ['sectionId' => 'entries.sectionId', 'typeId' => 'entries.typeId'];

	/** @var array<string,array<string,int>> grouped counts of the review queries, keyed by query and site scope */
	private array $counts = [];


	/**
	 * Number of stale entries, counted for all sections and entry types at once.
	 */
	public function countStale(int $months, ?int $sectionId = null, ?int $typeId = null): int
	{
		return $this->countBy("stale:$months", fn () => $this->getStaleQuery($months), self::ENTRY_GROUPS, [$sectionId, $typeId]);
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
		$pixels = Hub::getInstance()->filters->getThreshold(CheckKind::Assets, 'oversized');

		return [
			'and',
			['assets.kind' => Asset::KIND_IMAGE],
			['or', ['>', 'assets.width', $pixels], ['>', 'assets.height', $pixels]],
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
	 * Live reference tags targeting the queried element; drafts and revisions aren't indexed.
	 */
	public function tagReferencesQuery(): Query
	{
		return (new Query())
			->from(['hubReferences' => ReferenceService::TABLE])
			->innerJoin(['hubTagSources' => Table::ELEMENTS], '[[hubTagSources.id]] = [[hubReferences.sourceId]]')
			->where('[[hubReferences.targetId]] = [[elements.id]]')
			->andWhere(['hubTagSources.dateDeleted' => null]);
	}

	/**
	 * Condition matching the queried elements that are referenced, through a relation field or a reference tag.
	 *
	 * @return array<int,mixed>
	 */
	public function referencedCondition(): array
	{
		return ['or', ['exists', $this->referencesQuery()], ['exists', $this->tagReferencesQuery()]];
	}

	/**
	 * SQL of the number of live references targeting the queried element, relations and reference tags alike, for
	 * conditions and sorting.
	 */
	public function referenceCountSql(): string
	{
		return sprintf(
			'((%s) + (%s))',
			$this->referencesQuery()->select('COUNT(*)')->createCommand()->getRawSql(),
			$this->tagReferencesQuery()->select('COUNT(*)')->createCommand()->getRawSql(),
		);
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


	/**
	 * Entries that no person has edited within the given number of months, nested ones included, least recently edited first.
	 *
	 * @see EditService
	 */
	public function getStaleQuery(int $months, ?int $sectionId = null, ?int $typeId = null, ?int $fieldId = null): EntryQuery
	{
		/** @var EditService $edits */
		$edits = Hub::getInstance()->edits;
		$date = (new DateTime())->modify(sprintf('-%d months', $months));

		return $this->createQuery($sectionId, $typeId, $fieldId)
			->andWhere($edits->lastEditedCondition('< ' . $date->format(DateTime::ATOM)))
			->orderBy([$edits->lastEditedSql() => SORT_ASC, 'elements.id' => SORT_ASC]);
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
