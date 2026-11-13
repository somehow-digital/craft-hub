<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\CategoryGroup;
use craft\models\EntryType;
use craft\models\GqlSchema;
use craft\models\GqlToken;
use craft\models\Section;
use craft\models\Site;
use craft\models\TagGroup;
use craft\models\UserGroup;
use craft\models\Volume;
use craft\services\Categories;
use craft\services\Entries;
use craft\services\Fields;
use craft\services\Gql;
use craft\services\Sites;
use craft\services\Tags;
use craft\services\UserGroups;
use craft\services\Volumes;
use craft\web\User as WebUser;
use DateTime;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivityGroup;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\helpers\Range;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\models\Activity;
use SomehowDigital\Craft\Hub\models\ActivityFilter;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\models\Label;
use SomehowDigital\Craft\Hub\models\SavedFilter;
use yii\base\Component;
use yii\db\Expression;

/**
 * Lists the activity log that the ActivityService records: the activities by their filters, with the users and the links
 * of their subjects. Activities done together are recorded as a batch (see ActivityService::beginBatch()): the log lists
 * the batch's own activity, with its activities within it.
 *
 * @internal
 */
final class ActivityFeedService extends Component
{
	public const SORT_DATE = 'date';
	public const SORT_USER = 'user';
	public const SORT_SUBJECT = 'subject';
	public const SORT_ACTION = 'action';

	/** activities of a batch shown within it, before linking to all of them */
	public const BATCH_PREVIEW = 10;

	/** latest activities of an item shown on its pages, before linking to all of them */
	public const ITEM_PREVIEW = 10;

	private const COLUMNS = ['id', 'batchId', 'action', 'subject', 'subjectId', 'subjectTitle', 'siteId', 'userId', 'userName', 'ipAddress', 'data', 'dateCreated'];

	/** Hub pages of the subjects that have one, by their kind */
	private const PATHS = [
		'entry' => 'hub/data/entries',
		'asset' => 'hub/data/assets',
		'user' => 'hub/data/users',
		'userGroup' => 'hub/data/users/groups',
		'section' => 'hub/structure/sections',
		'entryType' => 'hub/structure/entry-types',
		'field' => 'hub/structure/fields',
		'volume' => 'hub/structure/volumes',
	];

	/** settings pages of the subjects without a Hub page, which only admins can open, by their kind */
	private const SETTINGS_PATHS = [
		'site' => 'settings/sites',
		'categoryGroup' => 'settings/categories',
		'tagGroup' => 'settings/tags',
		'gqlSchema' => 'graphql/schemas',
		'gqlToken' => 'graphql/tokens',
	];

	/** settings pages of Hub's own subjects, by their kind and the permission managing them */
	private const HUB_PATHS = [
		'label' => ['hub/manage/settings/labels', Permission::ManageLabels->value],
		'check' => ['hub/manage/settings/diagnostics', Permission::ManageDiagnostics->value],
		'filter' => ['hub/manage/settings/filters', Permission::ManageFilters->value],
	];

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly UserService $users,
		private readonly QueryService $queries,
		private readonly SiteScopeService $siteScope,
		private readonly AccessService $access,
		private readonly LabelService $labels,
		private readonly DiagnosticsService $diagnostics,
		private readonly FilterService $filters,
		private readonly Fields $craftFields,
		private readonly Sites $craftSites,
		private readonly Entries $craftEntries,
		private readonly Volumes $craftVolumes,
		private readonly UserGroups $craftUserGroups,
		private readonly Categories $craftCategories,
		private readonly Tags $craftTags,
		private readonly Gql $craftGql,
		private readonly ?WebUser $user = null,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * Activities narrowed by the given filters, as rows.
	 *
	 * @param string $sort one of the columns of {@see getSortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 *
	 * @return Query<array-key,mixed>
	 */
	public function getQuery(ActivityFilter $filter, string $sort = self::SORT_DATE, int $direction = SORT_DESC): Query
	{
		$query = (new Query())
			->select(self::COLUMNS)
			->from(Tables::ACTIVITY)
			->orderBy($this->sortOrder($sort, $direction));

		$conditions = $this->conditions($filter);

		// The activities of a batch, listed on their own.
		if ($filter->batch !== null) {
			return $query->where(['batchId' => $filter->batch])->andWhere($conditions);
		}

		// Activities of batches are listed within them, so batches match by any of their activities too.
		$query->where(['batchId' => null]);

		if ($conditions !== ['and']) {
			$query->andWhere([
				'or',
				$conditions,
				['id' => (new Query())->select(['batchId'])->from(Tables::ACTIVITY)->where(['not', ['batchId' => null]])->andWhere($conditions)],
			]);
		}

		return $query;
	}

	/**
	 * The latest activities of an item, newest first, those of batches included, with their users.
	 *
	 * @return Activity[]
	 *
	 * @return list<Activity>
	 */
	public function getItemActivities(ActivitySubject $subject, int $id, int $limit = self::ITEM_PREVIEW): array
	{
		return $this->populate((new Query())
			->select(self::COLUMNS)
			->from(Tables::ACTIVITY)
			->where(['subject' => $subject->value, 'subjectId' => $id])
			->orderBy(['id' => SORT_DESC])
			->limit($limit)
			->all());
	}

	/**
	 * Columns the activities can be sorted by, with the direction they sort in first: names ascending, dates descending.
	 *
	 * @return array<string,int> column => `SORT_ASC` or `SORT_DESC`
	 */
	public function getSortColumns(): array
	{
		return [
			self::SORT_SUBJECT => SORT_ASC,
			self::SORT_ACTION => SORT_ASC,
			self::SORT_USER => SORT_ASC,
			self::SORT_DATE => SORT_DESC,
		];
	}

	/**
	 * Activities of the rows of {@see getQuery()}, with their users and the URLs of their subjects that still exist. Given
	 * the filters they were listed by, batches get the first of their activities matching them (see BATCH_PREVIEW), and
	 * the number of all of those; batches matching them by their own activity get the first of all of theirs.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return list<Activity>
	 */
	public function populate(array $rows, ?ActivityFilter $filter = null): array
	{
		$activities = $this->build($rows);
		$all = $activities;

		if ($filter instanceof ActivityFilter && $filter->batch === null && $activities !== []) {
			$conditions = $this->conditions($filter);
			$byId = [];
			foreach ($activities as $activity) {
				$byId[$activity->id] = $activity;
			}

			// Batches matching the filters by their own activity, like by their name or action, get all of theirs.
			$whole = $conditions !== ['and'] ? array_map(Fill::int(...), (new Query())
				->select(['id'])
				->from(Tables::ACTIVITY)
				->where(['id' => array_keys($byId)])
				->andWhere($conditions)
				->column()) : [];

			$counts = array_intersect_key($this->batchCounts(array_keys($byId), $whole, $conditions), $byId);
			$previews = $this->batchPreviews(array_intersect(array_keys($counts), $whole), ['and'])
				+ $this->batchPreviews(array_diff(array_keys($counts), $whole), $conditions);

			foreach ($counts as $batchId => $count) {
				$batch = $byId[$batchId];
				$batch->batchCount = $count;
				$batch->batchFiltered = !in_array($batchId, $whole, true);
				$batch->batched = $previews[$batchId] ?? [];
				array_push($all, ...$batch->batched);
			}
		}

		$users = $this->users->getUsers(array_values(array_filter(array_map(fn(Activity $activity): ?int => $activity->userId, $all), is_int(...))));
		$urls = $this->subjectUrls($all);

		foreach ($all as $activity) {
			$activity->user = $activity->userId !== null ? $users[$activity->userId] ?? null : null;
			$activity->url = $activity->subjectId !== null ? $urls[$activity->subject->value][$activity->subjectId] ?? null : null;
		}

		return $activities;
	}

	/**
	 * Number of the activities of the batches with the IDs matching the filters, by the ID of the batch; those of the
	 * batches with the IDs `$whole` are all counted.
	 *
	 * @param int[] $ids
	 * @param int[] $whole
	 * @param array<int,mixed> $conditions
	 * @return array<int,int>
	 */
	private function batchCounts(array $ids, array $whole, array $conditions): array
	{
		$count = fn(array $ids, array $conditions) => $ids !== [] ? (new Query())
			->select(['batchId', 'count' => 'COUNT(*)'])
			->from(Tables::ACTIVITY)
			->where(['batchId' => $ids])
			->andWhere($conditions)
			->groupBy(['batchId'])
			->pairs() : [];

		$counts = [];
		foreach ($count(array_values(array_diff($ids, $whole)), $conditions) + $count($whole, ['and']) as $batchId => $number) {
			$counts[Fill::int($batchId)] = Fill::int($number);
		}

		return $counts;
	}

	/**
	 * The first activities of the batches with the IDs matching the filters, by the ID of the batch, all at once.
	 *
	 * @param int[] $ids
	 * @param array<int,mixed> $conditions
	 * @return array<int,Activity[]>
	 */
	private function batchPreviews(array $ids, array $conditions): array
	{
		if ($ids === []) {
			return [];
		}

		$ranked = (new Query())
			->select([...self::COLUMNS, 'hubRank' => new Expression('ROW_NUMBER() OVER (PARTITION BY [[batchId]] ORDER BY [[id]])')])
			->from(Tables::ACTIVITY)
			->where(['batchId' => array_values($ids)])
			->andWhere($conditions);

		$previews = [];
		foreach ($this->build((new Query())
			->select(self::COLUMNS)
			->from(['hubRanked' => $ranked])
			->where(['<=', 'hubRank', self::BATCH_PREVIEW])
			->orderBy(['id' => SORT_ASC])
			->all()) as $activity) {
			$previews[(int) $activity->batchId][] = $activity;
		}

		return $previews;
	}

	/**
	 * The names of fields by their handles, for those that exist.
	 *
	 * @param array<mixed> $handles
	 * @return array<string,string>
	 */
	private function fieldNames(array $handles): array
	{
		$fields = $this->craftFields;
		$names = [];

		foreach ($handles as $handle) {
			$handle = Fill::text($handle) ?? '';
			$field = $fields->getFieldByHandle($handle);

			if ($field instanceof FieldInterface) {
				$names[$handle] = $field->name ?? $handle;
			}
		}

		return $names;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return list<Activity>
	 */
	private function build(array $rows): array
	{
		$activities = [];
		foreach ($rows as $row) {
			$action = ActivityAction::tryFrom(Row::string($row, 'action'));
			$subject = ActivitySubject::tryFrom(Row::string($row, 'subject'));

			// Activities of actions or subjects no longer known, like those of a later version, are left out.
			if ($action === null || $subject === null) {
				continue;
			}

			$activity = new Activity();
			$activity->id = Row::int($row, 'id');
			$activity->batchId = isset($row['batchId']) ? Row::int($row, 'batchId') : null;
			$activity->action = $action;
			$activity->subject = $subject;
			$activity->subjectId = Row::intOrNull($row, 'subjectId');
			$activity->subjectTitle = Row::stringOrNull($row, 'subjectTitle');
			$activity->siteId = Row::intOrNull($row, 'siteId');
			$activity->site = $activity->siteId !== null ? $this->craftSites->getSiteById($activity->siteId) : null;
			$activity->userId = Row::intOrNull($row, 'userId');
			$activity->userName = Row::stringOrNull($row, 'userName');
			$activity->ipAddress = Row::stringOrNull($row, 'ipAddress');
			$activity->data = Row::string($row, 'data') !== '' ? Fill::map(Json::decodeIfJson(Row::string($row, 'data'))) : [];
			$activity->fieldNames = $this->fieldNames(Fill::list($activity->data['fields'] ?? []));
			$activity->dateCreated = Row::date($row, 'dateCreated') ?? new DateTime();
			$activities[] = $activity;
		}

		return $activities;
	}

	/**
	 * Options of the users filter: the users of any activity, or the ones given of them, by their ID, in the order of their
	 * names.
	 *
	 * @param int[]|null $ids
	 *
	 * @return array<int,array{label:string,user:User|null}>
	 */
	public function getUserOptions(?array $ids = null): array
	{
		// The latest name of each user, as they may have been renamed in between.
		$rows = (new Query())
			->select(['userId', 'userName'])
			->from(Tables::ACTIVITY)
			->where(['id' => (new Query())->select('MAX([[id]])')->from(Tables::ACTIVITY)->where(['not', ['userId' => null]])->andFilterWhere(['userId' => $ids])->groupBy('userId')])
			->all();

		$users = $this->users->getUsers(array_map(fn(array $row): int => Row::int($row, 'userId'), $rows));

		$options = [];
		foreach ($rows as $row) {
			$user = $users[Row::int($row, 'userId')] ?? null;
			$options[Row::int($row, 'userId')] = ['label' => $user?->getName() ?? Row::string($row, 'userName'), 'user' => $user];
		}

		uasort($options, fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

		return $options;
	}

	/**
	 * Conditions of the filters, other than the batch, as a condition of all of them.
	 *
	 * @return list<mixed>
	 */
	private function conditions(ActivityFilter $filter): array
	{
		$conditions = ['and'];

		if ($filter->search !== '') {
			// PostgreSQL's `like` is case-sensitive, unlike MySQL's with Craft's collation.
			$operator = $this->queries->likeOperator();
			$conditions[] = [
				'or',
				[$operator, 'subjectTitle', $filter->search],
				[$operator, 'userName', $filter->search],
				[$operator, 'ipAddress', $filter->search],
			];
		}

		if ($filter->actions !== []) {
			$actions = array_merge(...array_map(fn(string $group) => ActivityGroup::from($group)->actions(), $filter->actions));
			$conditions[] = ['action' => array_map(fn(ActivityAction $action) => $action->value, $actions)];
		}

		if ($filter->subjects !== []) {
			$conditions[] = ['subject' => $filter->subjects];
		}

		if ($filter->item !== null && count($filter->subjects) === 1) {
			$conditions[] = ['subjectId' => $filter->item];
		}

		if ($filter->users !== []) {
			$ids = array_map(Fill::int(...), array_diff($filter->users, [ActivityFilter::USER_NONE]));
			$conditions[] = [
				'or',
				$ids !== [] ? ['userId' => $ids] : [],
				in_array(ActivityFilter::USER_NONE, $filter->users, true) ? ['userId' => null] : [],
			];
		}

		if (($date = Range::dateParam($filter->dateFrom, $filter->dateTo)) !== null) {
			$conditions[] = Db::parseDateParam('dateCreated', $date);
		}

		return $conditions;
	}

	/**
	 * @return array<string,int>
	 */
	private function sortOrder(string $sort, int $direction): array
	{
		// The ID keeps activities of the same moment, or of the same user or subject, in the order they happened.
		return match ($sort) {
			self::SORT_USER => ['userName' => $direction, 'id' => SORT_DESC],
			self::SORT_SUBJECT => ['subjectTitle' => $direction, 'id' => SORT_DESC],
			self::SORT_ACTION => ['action' => $direction, 'id' => SORT_DESC],
			default => ['dateCreated' => $direction, 'id' => $direction],
		};
	}

	/**
	 * URLs of the subjects of the activities that still exist, by their kind and ID.
	 *
	 * @param Activity[] $activities
	 * @return array<string,array<int,string>>
	 */
	private function subjectUrls(array $activities): array
	{
		$ids = [];
		$elementIds = [];
		foreach ($activities as $activity) {
			if ($activity->subjectId !== null) {
				$ids[$activity->subject->value][$activity->subjectId] = $activity->subjectId;

				if ($activity->subject->isElement()) {
					$elementIds[$activity->subjectId] = $activity->subjectId;
				}
			}
		}

		// Elements deleted since, even if only soft-deleted, have no page to link to.
		$existing = $elementIds !== [] ? array_flip(array_map(Fill::int(...), (new Query())
			->select(['id'])
			->from(Table::ELEMENTS)
			->where(['id' => array_values($elementIds), 'dateDeleted' => null])
			->column())) : [];

		$webUser = $this->user;
		$isAdmin = $webUser?->getIsAdmin() === true;

		$urls = [];
		foreach ($ids as $kind => $subjectIds) {
			$subject = ActivitySubject::from($kind);

			foreach ($subjectIds as $id) {
				if (!($subject->isElement() ? isset($existing[$id]) : $this->exists($subject, $id))) {
					continue;
				}

				if (isset(self::PATHS[$kind]) && $this->canOpenPage($kind)) {
					$urls[$kind][$id] = $this->siteScope->pageUrl(self::PATHS[$kind] . "/{$id}");
				} elseif ($subject->isElement()) {
					$urls[$kind][$id] = UrlHelper::cpUrl("edit/{$id}");
				} elseif (isset(self::SETTINGS_PATHS[$kind]) && $isAdmin) {
					$urls[$kind][$id] = UrlHelper::cpUrl(self::SETTINGS_PATHS[$kind] . "/{$id}");
				} elseif (isset(self::HUB_PATHS[$kind]) && $webUser?->checkPermission(self::HUB_PATHS[$kind][1]) === true) {
					$urls[$kind][$id] = UrlHelper::cpUrl(self::HUB_PATHS[$kind][0] . "/{$id}");
				}
			}
		}

		return $urls;
	}

	/**
	 * Whether the current user may open the Hub pages of the subjects of a kind (see PATHS), as they may view the page
	 * listing them; elements they may not open link to their edit page instead.
	 */
	private function canOpenPage(string $kind): bool
	{
		return $this->access->canView(match ($kind) {
			'entry' => Page::Entries,
			'asset' => Page::Assets,
			'user', 'userGroup' => Page::Users,
			'section' => Page::Sections,
			'entryType' => Page::EntryTypes,
			'field' => Page::Fields,
			'volume' => Page::Volumes,
			default => $kind,
		});
	}

	/**
	 * Whether something holding the content still exists.
	 */
	private function exists(ActivitySubject $subject, int $id): bool
	{
		return match ($subject) {
			ActivitySubject::Section => $this->craftEntries->getSectionById($id) instanceof Section,
			ActivitySubject::EntryType => $this->craftEntries->getEntryTypeById($id) instanceof EntryType,
			ActivitySubject::Field => $this->craftFields->getFieldById($id) instanceof FieldInterface,
			ActivitySubject::Volume => $this->craftVolumes->getVolumeById($id) instanceof Volume,
			ActivitySubject::UserGroup => $this->craftUserGroups->getGroupById($id) instanceof UserGroup,
			ActivitySubject::Site => $this->craftSites->getSiteById($id) instanceof Site,
			ActivitySubject::CategoryGroup => $this->craftCategories->getGroupById($id) instanceof CategoryGroup,
			ActivitySubject::TagGroup => $this->craftTags->getTagGroupById($id) instanceof TagGroup,
			ActivitySubject::GqlSchema => $this->craftGql->getSchemaById($id) instanceof GqlSchema,
			ActivitySubject::GqlToken => $this->craftGql->getTokenById($id) instanceof GqlToken,
			ActivitySubject::Label => $this->labels->getLabelById($id) instanceof Label,
			ActivitySubject::Check => $this->diagnostics->getDefinitionById($id) instanceof CheckDefinition,
			ActivitySubject::Filter => $this->filters->getFilterById($id) instanceof SavedFilter,
			ActivitySubject::Entry, ActivitySubject::Asset, ActivitySubject::User, ActivitySubject::Category, ActivitySubject::Tag, ActivitySubject::GlobalSet, ActivitySubject::Element, ActivitySubject::ImageTransform, ActivitySubject::Plugin, ActivitySubject::SiteGroup, ActivitySubject::Filesystem, ActivitySubject::Route, ActivitySubject::Settings, ActivitySubject::ProjectConfig, ActivitySubject::System, ActivitySubject::Database, ActivitySubject::Utility => false,
		};
	}
}
