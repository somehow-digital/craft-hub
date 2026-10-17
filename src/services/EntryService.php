<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use DateTime;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\helpers\FilterParams;
use SomehowDigital\Craft\Hub\helpers\Range;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use yii\base\Component;
use yii\db\Expression;

/**
 * Lists the entries of all sections and nested entry fields and collects the details shown for a single entry.
 */
class EntryService extends Component
{
	public const USAGE_REFERENCED = 'referenced';
	public const USAGE_UNREFERENCED = 'unreferenced';

	public const ORIGIN_SECTION = 'section';
	public const ORIGIN_NESTED = 'nested';

	public const SORT_TITLE = 'title';
	public const SORT_SECTION = 'section';
	public const SORT_TYPE = 'type';
	public const SORT_REFERENCES = 'references';
	public const SORT_POSTED = 'posted';
	public const SORT_UPDATED = 'updated';

	/**
	 * Entries of all sections and nested entry fields on the counted sites, narrowed by the given filters.
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
			->andWhere($this->contentCondition())
			->orderBy($this->sortOrder($sort, $direction));

		if ($filter->search !== '') {
			// PostgreSQL's `like` is case-sensitive, unlike MySQL's with Craft's collation.
			$operator = Craft::$app->getDb()->getIsPgsql() ? 'ilike' : 'like';
			$query->andWhere([
				'or',
				[$operator, 'elements_sites.title', $filter->search],
				[$operator, 'elements_sites.slug', $filter->search],
			]);
		}

		if ($filter->labels !== []) {
			$query->andWhere(Hub::getInstance()->labels->condition(ColumnService::LIST_ENTRIES, $filter->labels));
		}

		if ($filter->savedFilter !== null) {
			$query->andWhere(Hub::getInstance()->filters->elementCondition(CheckKind::Entries, $filter->savedFilter));
		}

		if ($filter->sections !== []) {
			$query->section($filter->sections);
		}

		if ($filter->types !== []) {
			$query->type($filter->types);
		}

		if ($filter->origin === self::ORIGIN_SECTION || $filter->origin === self::ORIGIN_NESTED) {
			$query->andWhere($filter->origin === self::ORIGIN_SECTION ? ['not', ['entries.sectionId' => null]] : ['entries.sectionId' => null]);
		}

		if ($filter->fields !== []) {
			$fields = Craft::$app->getFields();
			$query->andWhere(['entries.fieldId' => array_values(array_filter(array_map(fn (string $handle) => $fields->getFieldByHandle($handle)?->id, $filter->fields)))]);
		}

		if ($filter->authors !== []) {
			$query->authorId(FilterParams::userIds($filter->authors));
		}

		if ($filter->editors !== []) {
			$query->andWhere(Hub::getInstance()->edits->editedByCondition(FilterParams::userIds($filter->editors)));
		}

		if ($filter->usage === self::USAGE_REFERENCED || $filter->usage === self::USAGE_UNREFERENCED) {
			$query->andWhere($filter->usage === self::USAGE_REFERENCED ? $review->referencedCondition() : ['not', $review->referencedCondition()]);
		}

		if (($references = Range::condition(new Expression($review->referenceCountSql()), $filter->referencesMin, $filter->referencesMax)) !== null) {
			$query->andWhere($references);
		}

		if (($posted = Range::dateParam($filter->postedFrom, $filter->postedTo)) !== null) {
			$query->postDate($posted);
		}

		// Entries are updated by any save, so they are filtered by when a person last edited them.
		$edited = Range::dateParam($filter->updatedFrom, $filter->updatedTo) ?? ['and'];

		if ($filter->staleMonths !== null) {
			$edited[] = '< ' . (new DateTime())->modify(sprintf('-%d months', $filter->staleMonths))->format(DateTime::ATOM);
		}

		if ($edited !== ['and']) {
			$query->andWhere(Hub::getInstance()->edits->lastEditedCondition($edited));
		}

		if ($filter->drafts) {
			$query->andWhere($review->draftsCondition());
		}

		if ($filter->myDrafts) {
			$query->andWhere($review->userDraftsCondition((int)Craft::$app->getUser()->getId()));
		}

		return $query;
	}

	/**
	 * Authors of entries, with how many entries they wrote, most entries first, optionally of a section, entry type or field only.
	 *
	 * @return array<int,array{user:User,count:int}> keyed by user ID
	 */
	public function getAuthors(?int $sectionId = null, ?int $typeId = null, ?int $fieldId = null): array
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
			->andFilterWhere(['entries.sectionId' => $sectionId, 'entries.typeId' => $typeId, 'entries.fieldId' => $fieldId])
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
			self::SORT_REFERENCES => SORT_DESC,
			self::SORT_POSTED => SORT_DESC,
			self::SORT_UPDATED => SORT_DESC,
		];
	}

	/**
	 * The entry in the first selected site, or the current one, or in the first site it exists in.
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
	 * Query of the revisions of the entry on its site, newest first, with their creators, as Craft lists them.
	 */
	public function getRevisionsQuery(Entry $entry): EntryQuery
	{
		return Entry::find()
			->revisionOf($entry)
			->siteId($entry->siteId)
			->status(null)
			->orderBy(['num' => SORT_DESC])
			->with(['revisionCreator']);
	}

	/**
	 * Saved drafts of the entry on its site, last updated first, with their creators. Provisional drafts, the unsaved
	 * changes of each user, are left out.
	 *
	 * @return Entry[]
	 */
	public function getDrafts(Entry $entry): array
	{
		return Entry::find()
			->draftOf($entry)
			->siteId($entry->siteId)
			->status(null)
			->orderBy(['dateUpdated' => SORT_DESC])
			->with(['draftCreator'])
			->all();
	}

	/**
	 * Entries with the IDs along with their saved drafts that the current user may delete, last updated first, by the
	 * entry's ID; entries without any are left out. Provisional drafts, the unsaved changes of each user, are kept.
	 *
	 * @param int[] $ids
	 * @return array{0:Entry[],1:array<int,Entry[]>} the entries, and their drafts by their ID
	 */
	public function getDeletableDrafts(array $ids): array
	{
		$entries = $ids !== [] ? Entry::find()->id($ids)->site('*')->unique()->status(null)->all() : [];

		if ($entries === []) {
			return [[], []];
		}

		$service = Craft::$app->getElements();
		$drafts = [];

		$found = Entry::find()
			->draftOf(array_map(fn (Entry $entry) => (int)$entry->id, $entries))
			->site('*')
			->unique()
			->status(null)
			->orderBy(['dateUpdated' => SORT_DESC])
			->with(['draftCreator'])
			->all();

		foreach ($found as $draft) {
			if ($service->canDelete($draft)) {
				$drafts[(int)$draft->getCanonicalId()][] = $draft;
			}
		}

		return [array_values(array_filter($entries, fn (Entry $entry) => isset($drafts[$entry->id]))), $drafts];
	}

	/**
	 * Active users who may be an author of any of the entries, by name: those who may view the entries of their sections,
	 * as Craft lets them author those. Sections without authors, like singles, have none.
	 *
	 * @param Entry[] $entries
	 * @return User[]
	 */
	public function getAuthorCandidates(array $entries): array
	{
		$ids = [];

		foreach ($this->authoredSections($entries) as $section) {
			$ids = [...$ids, ...User::find()->can("viewEntries:$section->uid")->status(User::STATUS_ACTIVE)->ids()];
		}

		if ($ids === []) {
			return [];
		}

		$users = User::find()->id(array_values(array_unique($ids)))->status(User::STATUS_ACTIVE)->all();
		usort($users, fn (User $a, User $b) => strnatcasecmp((string)$a->getName(), (string)$b->getName()));

		return $users;
	}

	/**
	 * Whether the current user may change the authors of the entry, as Craft lets them on its edit page: entries without
	 * authors and those they are an author of, and others in sections they may change the authors of peer entries in.
	 */
	public function canChangeAuthors(Entry $entry): bool
	{
		$user = Craft::$app->getUser()->getIdentity();
		$section = $entry->getSection();

		if ($user === null || $section === null || $this->authoredSections([$entry]) === [] || !Craft::$app->getElements()->canSave($entry)) {
			return false;
		}

		$authorIds = $entry->getAuthorIds();

		return $authorIds === [] || in_array($user->id, $authorIds, true) || $user->can("changeAuthorForPeerEntries:$section->uid");
	}

	/**
	 * Sections the entries can be moved to, by name, along with the number of the entries each takes, see canMoveTo().
	 * Sections taking none of them are left out.
	 *
	 * @param Entry[] $entries
	 * @return array<int,array{section:Section,count:int}> keyed by section ID
	 */
	public function getSectionTargets(array $entries): array
	{
		$targets = [];

		foreach (Craft::$app->getEntries()->getAllSections() as $section) {
			$count = count(array_filter($entries, fn (Entry $entry) => $this->canMoveTo($entry, $section)));

			if ($count > 0) {
				$targets[$section->id] = ['section' => $section, 'count' => $count];
			}
		}

		uasort($targets, fn (array $a, array $b) => strnatcasecmp((string)$a['section']->name, (string)$b['section']->name));

		return $targets;
	}

	/**
	 * Whether the current user may move the entry to the section, as Craft lets them move entries: from a section other
	 * than a single, to another one that isn't a single either, they may save entries in, and that has the entry's type.
	 */
	public function canMoveTo(Entry $entry, Section $section): bool
	{
		$user = Craft::$app->getUser()->getIdentity();

		return $user !== null
			&& $section->type !== Section::TYPE_SINGLE
			&& $entry->sectionId !== null
			&& $entry->sectionId !== $section->id
			&& in_array($entry->typeId, array_map(fn (EntryType $type) => $type->id, $section->getEntryTypes()), true)
			&& $user->can("saveEntries:$section->uid")
			&& $entry->canMove($user);
	}

	/**
	 * Sections of the entries that have authors, by their ID.
	 *
	 * @param Entry[] $entries
	 * @return array<int,Section>
	 */
	private function authoredSections(array $entries): array
	{
		$sections = [];

		foreach ($entries as $entry) {
			$section = $entry->getSection();

			if ($section !== null && $section->type !== Section::TYPE_SINGLE && $section->maxAuthors !== 0) {
				$sections[$section->id] = $section;
			}
		}

		return $sections;
	}

	/**
	 * Condition leaving out the nested entries owned by drafts or revisions, which are copies, not content of their own.
	 *
	 * @return array<int,mixed>
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
	 * Sorting by section, entry type, references and last edit uses subqueries, as the entry query joins none of them.
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
			self::SORT_REFERENCES => [$review->referenceCountSql()],
			self::SORT_POSTED => ['entries.postDate'],
			default => [Hub::getInstance()->edits->lastEditedSql()],
		};

		return [...array_fill_keys($columns, $direction), 'elements.id' => $direction];
	}
}
