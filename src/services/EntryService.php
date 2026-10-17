<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\Site;
use DateTime;
use SomehowDigital\Craft\Hub\helpers\Range;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use yii\base\Component;

/**
 * Lists the entries of all sections and collects the details shown for a single entry.
 */
class EntryService extends Component
{
	public const USAGE_REFERENCED = 'referenced';
	public const USAGE_UNREFERENCED = 'unreferenced';

	public const SORT_TITLE = 'title';
	public const SORT_SECTION = 'section';
	public const SORT_TYPE = 'type';
	public const SORT_RELATIONS = 'relations';
	public const SORT_POSTED = 'posted';
	public const SORT_UPDATED = 'updated';

	/**
	 * Entries of all sections on the counted sites, narrowed by the given filters.
	 * Nested entries are left out, as they belong to their owner.
	 *
	 * @param string $sort one of the columns of {@see getSortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 */
	public function getQuery(EntryFilter $filter, string $sort = self::SORT_UPDATED, int $direction = SORT_DESC): EntryQuery
	{
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;

		$query = Entry::find()
			->siteId(Hub::getInstance()->getSiteIds())
			->unique()
			->status($filter->statuses !== [] ? $filter->statuses : null)
			->andWhere(['not', ['entries.sectionId' => null]])
			->orderBy($this->sortOrder($sort, $direction));

		if ($filter->search !== '') {
			$query->andWhere([
				'or',
				['like', 'elements_sites.title', $filter->search],
				['like', 'elements_sites.slug', $filter->search],
			]);
		}

		if ($filter->sections !== []) {
			$query->section($filter->sections);
		}

		if ($filter->types !== []) {
			$query->type($filter->types);
		}

		if ($filter->authors !== []) {
			$query->authorId($filter->authors);
		}

		if ($filter->usage === self::USAGE_REFERENCED || $filter->usage === self::USAGE_UNREFERENCED) {
			$query->andWhere([$filter->usage === self::USAGE_REFERENCED ? 'exists' : 'not exists', $review->referencesQuery()]);
		}

		if (($relations = Range::condition($review->referencesQuery()->select('COUNT(*)'), $filter->relationsMin, $filter->relationsMax)) !== null) {
			$query->andWhere($relations);
		}

		if (($posted = Range::dateParam($filter->postedFrom, $filter->postedTo)) !== null) {
			$query->postDate($posted);
		}

		$updated = Range::dateParam($filter->updatedFrom, $filter->updatedTo) ?? ['and'];

		if ($filter->staleMonths !== null) {
			$updated[] = '< ' . (new DateTime())->modify(sprintf('-%d months', $filter->staleMonths))->format(DateTime::ATOM);
		}

		if ($updated !== ['and']) {
			$query->dateUpdated($updated);
		}

		if ($filter->drafts) {
			$query->andWhere(['exists', $review->draftsQuery()]);
		}

		if ($filter->diagnoses !== []) {
			$query->andWhere(Hub::getInstance()->checks->entryDiagnosisCondition($filter->diagnoses));
		}

		return $query;
	}

	/**
	 * Authors of section entries, with how many entries they wrote, most entries first, optionally of a section or entry type only.
	 *
	 * @return array<int,array{user:User,count:int}> keyed by user ID
	 */
	public function getAuthors(?int $sectionId = null, ?int $typeId = null): array
	{
		$counts = (new Query())
			->select(['entries_authors.authorId', 'count' => 'COUNT(*)'])
			->from(['entries_authors' => Table::ENTRIES_AUTHORS])
			->innerJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[entries_authors.entryId]]')
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
			->where([
				'elements.dateDeleted' => null,
				'elements.draftId' => null,
				'elements.revisionId' => null,
			])
			->andWhere(['not', ['entries.sectionId' => null]])
			->andFilterWhere(['entries.sectionId' => $sectionId, 'entries.typeId' => $typeId])
			->groupBy(['entries_authors.authorId'])
			->orderBy(['count' => SORT_DESC])
			->pairs();

		if ($counts === []) {
			return [];
		}

		$users = User::find()->id(array_keys($counts))->status(null)->indexBy('id')->all();
		$authors = [];

		foreach ($counts as $id => $count) {
			if (isset($users[$id])) {
				$authors[(int)$id] = ['user' => $users[$id], 'count' => (int)$count];
			}
		}

		return $authors;
	}

	/**
	 * How many entries each value of a filter matches on its own, or along with a base filter, for the counts of its options.
	 *
	 * @param string[] $values
	 * @param callable(EntryFilter,string):mixed $apply sets a value on the filter
	 * @return array<string,int> value => count
	 */
	public function getCounts(array $values, callable $apply, ?EntryFilter $base = null): array
	{
		$counts = [];

		foreach ($values as $value) {
			$filter = $base !== null ? clone $base : new EntryFilter();
			$apply($filter, $value);
			$counts[$value] = (int)$this->getQuery($filter)->count();
		}

		return $counts;
	}

	/**
	 * Statuses the entries can be filtered by.
	 *
	 * @return string[]
	 */
	public function getStatuses(): array
	{
		return [Entry::STATUS_LIVE, Entry::STATUS_PENDING, Entry::STATUS_EXPIRED, Entry::STATUS_DISABLED];
	}

	/**
	 * Columns the entries can be sorted by, with the direction they sort in first: names ascending, numbers and dates descending.
	 *
	 * @return array<string,int> column => `SORT_ASC` or `SORT_DESC`
	 */
	public function getSortColumns(): array
	{
		return [
			self::SORT_TITLE => SORT_ASC,
			self::SORT_SECTION => SORT_ASC,
			self::SORT_TYPE => SORT_ASC,
			self::SORT_RELATIONS => SORT_DESC,
			self::SORT_POSTED => SORT_DESC,
			self::SORT_UPDATED => SORT_DESC,
		];
	}

	/**
	 * The section entry in the first selected site, or the current one, or in the first site it exists in.
	 */
	public function getEntry(int $id): ?Entry
	{
		$siteIds = array_map(fn (Site $site) => $site->id, Hub::getInstance()->getSites());

		return Entry::find()
			->id($id)
			->siteId(Hub::getInstance()->getSiteIds())
			->preferSites([...$siteIds, Craft::$app->getSites()->getCurrentSite()->id])
			->unique()
			->status(null)
			->andWhere(['not', ['entries.sectionId' => null]])
			->one();
	}

	/**
	 * The entry on each of the counted sites, to compare titles, slugs and statuses.
	 *
	 * @return Entry[]
	 */
	public function getSiteVariants(Entry $entry): array
	{
		return Entry::find()
			->id($entry->id)
			->siteId(Hub::getInstance()->getSiteIds())
			->status(null)
			->orderBy(['elements_sites.siteId' => SORT_ASC])
			->all();
	}

	/**
	 * Sorting by section, entry type and relations uses subqueries, as the entry query joins none of them.
	 *
	 * @return array<string,int>
	 */
	private function sortOrder(string $sort, int $direction): array
	{
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;

		$columns = match ($sort) {
			self::SORT_TITLE => ['elements_sites.title'],
			self::SORT_SECTION => ['(SELECT [[hubSections.name]] FROM ' . Table::SECTIONS . ' [[hubSections]] WHERE [[hubSections.id]] = [[entries.sectionId]])'],
			self::SORT_TYPE => ['(SELECT [[hubEntryTypes.name]] FROM ' . Table::ENTRYTYPES . ' [[hubEntryTypes]] WHERE [[hubEntryTypes.id]] = [[entries.typeId]])'],
			self::SORT_RELATIONS => ['(' . $review->referencesQuery()->select('COUNT(*)')->createCommand()->getRawSql() . ')'],
			self::SORT_POSTED => ['entries.postDate'],
			default => ['elements.dateUpdated'],
		};

		return [...array_fill_keys($columns, $direction), 'elements.id' => $direction];
	}
}
