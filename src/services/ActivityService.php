<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateTime;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivityGroup;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\Range;
use SomehowDigital\Craft\Hub\helpers\Sql;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Activity;
use SomehowDigital\Craft\Hub\models\ActivityFilter;
use Throwable;
use yii\base\Component;

/**
 * The activity log: what users did, as Craft's own events tell it (see ActivityTracker), along with what they did with
 * Hub itself. Activities are kept with the names of their users and subjects as they were, so they outlive them, until
 * they expire (see Settings::$activityRetentionDays).
 *
 * Activities done together, like those of a bulk action or of the project config applied, are recorded as a batch (see
 * beginBatch()): the log lists the batch's own activity, with its activities within it.
 */
class ActivityService extends Component
{
	public const TABLE = '{{%hub_activity}}';

	public const SORT_DATE = 'date';
	public const SORT_USER = 'user';
	public const SORT_SUBJECT = 'subject';
	public const SORT_ACTION = 'action';

	/** activities of a batch shown within it, before linking to all of them */
	public const BATCH_PREVIEW = 10;

	private const COLUMNS = ['id', 'batchId', 'action', 'subject', 'subjectId', 'subjectTitle', 'siteId', 'userId', 'userName', 'ipAddress', 'data', 'dateCreated'];

	/** Hub pages of the subjects that have one, by their kind */
	private const PATHS = [
		'entry' => 'hub/data/entries',
		'asset' => 'hub/data/assets',
		'user' => 'hub/data/users',
		'section' => 'hub/structure/sections',
		'entryType' => 'hub/structure/entry-types',
		'field' => 'hub/structure/fields',
		'volume' => 'hub/structure/volumes',
	];

	/** settings pages of the subjects without a Hub page, which only admins can open, by their kind */
	private const SETTINGS_PATHS = [
		'userGroup' => 'settings/users/groups',
		'site' => 'settings/sites',
		'categoryGroup' => 'settings/categories',
		'tagGroup' => 'settings/tags',
		'gqlSchema' => 'graphql/schemas',
		'gqlToken' => 'graphql/tokens',
	];

	/** settings pages of Hub's own subjects, by their kind and the permission managing them */
	private const HUB_PATHS = [
		'label' => ['hub/manage/settings/labels', Hub::PERMISSION_MANAGE_LABELS],
		'check' => ['hub/manage/settings/diagnostics', Hub::PERMISSION_MANAGE_DIAGNOSTICS],
		'filter' => ['hub/manage/settings/filters', Hub::PERMISSION_MANAGE_FILTERS],
	];

	private ?bool $tableExists = null;

	/**
	 * The batch being recorded, with the ID of its activity once it's recorded, and how many batches were begun within it.
	 *
	 * @var array{action:ActivityAction,subject:ActivitySubject,title:string|null,data:array<string,mixed>,id:int|null,depth:int}|null
	 */
	private ?array $batch = null;

	/**
	 * Whether activities are recorded and the activity log is shown (see Settings::$enableActivity).
	 */
	public function isEnabled(): bool
	{
		return Hub::getInstance()->settings->enableActivity;
	}

	/**
	 * Whether the current user may see the activity log.
	 */
	public function canView(): bool
	{
		return $this->isEnabled() && Craft::$app->getUser()->checkPermission(Hub::PERMISSION_VIEW_ACTIVITY);
	}

	/**
	 * Records an activity of the current user, or of the given one, while the activity log is enabled. Recording never
	 * fails what was done, so errors are only logged.
	 *
	 * @param array<string,mixed> $data what else is known about it, like the changed `fields` and `attributes` of an element
	 * @param User|null $user who did it, the current user by default
	 * @param bool $anonymous whether no user did it, like a failed sign-in
	 */
	public function record(
		ActivityAction $action,
		ActivitySubject $subject,
		?int $subjectId = null,
		?string $subjectTitle = null,
		array $data = [],
		?int $siteId = null,
		?User $user = null,
		bool $anonymous = false,
	): void {
		try {
			if (!$this->isEnabled() || !$this->tableExists()) {
				return;
			}

			$columns = $this->columns($action, $subject, $subjectId, $subjectTitle, $data, $siteId, $anonymous ? null : ($user ?? $this->currentUser()));

			if ($this->batch !== null) {
				$this->batch['id'] ??= $this->insert($this->columns($this->batch['action'], $this->batch['subject'], null, $this->batch['title'], $this->batch['data'], null, $this->currentUser()));
				$columns['batchId'] = $this->batch['id'];
			}

			$this->insert($columns);
		} catch (Throwable $e) {
			Craft::warning("Couldn’t record the {$action->value} activity of a {$subject->value}: {$e->getMessage()}", __METHOD__);
		}
	}

	/**
	 * Records the activities until endBatch() as a batch, like those of a bulk action, under an activity of its own, by
	 * the current user. That one's only recorded along with the first activity of the batch, so batches doing nothing
	 * leave nothing behind. Batches begun within a batch are part of it.
	 *
	 * @param string|null $title what was done, like the name of the action
	 * @param array<string,mixed> $data
	 */
	public function beginBatch(ActivityAction $action, ActivitySubject $subject, ?string $title = null, array $data = []): void
	{
		if ($this->batch !== null) {
			$this->batch['depth']++;

			return;
		}

		$this->batch = ['action' => $action, 'subject' => $subject, 'title' => $title, 'data' => $data, 'id' => null, 'depth' => 1];
	}

	/**
	 * Ends the batch begun last.
	 */
	public function endBatch(): void
	{
		if ($this->batch !== null && --$this->batch['depth'] === 0) {
			$this->batch = null;
		}
	}

	/**
	 * Whether activities are recorded as a batch.
	 */
	public function isBatching(): bool
	{
		return $this->batch !== null;
	}

	/**
	 * Activities narrowed by the given filters, as rows.
	 *
	 * @param string $sort one of the columns of {@see getSortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 */
	public function getQuery(ActivityFilter $filter, string $sort = self::SORT_DATE, int $direction = SORT_DESC): Query
	{
		$query = (new Query())
			->select(self::COLUMNS)
			->from(self::TABLE)
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
				['id' => (new Query())->select(['batchId'])->from(self::TABLE)->where(['not', ['batchId' => null]])->andWhere($conditions)],
			]);
		}

		return $query;
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
	 * @return Activity[]
	 */
	public function populate(array $rows, ?ActivityFilter $filter = null): array
	{
		$activities = $this->build($rows);
		$all = $activities;

		if ($filter !== null && $filter->batch === null && $activities !== []) {
			$conditions = $this->conditions($filter);
			$byId = [];
			foreach ($activities as $activity) {
				$byId[$activity->id] = $activity;
			}

			// Batches matching the filters by their own activity, like by their name or action, get all of theirs.
			$whole = $conditions !== ['and'] ? array_map('intval', (new Query())
				->select(['id'])
				->from(self::TABLE)
				->where(['id' => array_keys($byId)])
				->andWhere($conditions)
				->column()) : [];

			foreach ($this->batchCounts(array_keys($byId), $whole, $conditions) as $batchId => $count) {
				$batch = $byId[$batchId] ?? null;

				if ($batch === null) {
					continue;
				}

				$batch->batchCount = $count;
				$batch->batchFiltered = !in_array($batchId, $whole, true);
				$batch->batched = $this->build((new Query())
					->select(self::COLUMNS)
					->from(self::TABLE)
					->where(['batchId' => $batch->id])
					->andWhere($batch->batchFiltered ? $conditions : ['and'])
					->orderBy(['id' => SORT_ASC])
					->limit(self::BATCH_PREVIEW)
					->all());
				array_push($all, ...$batch->batched);
			}
		}

		$users = $this->users(array_filter(array_map(fn (Activity $activity) => $activity->userId, $all)));
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
		$count = fn (array $ids, array $conditions) => $ids !== [] ? (new Query())
			->select(['batchId', 'count' => 'COUNT(*)'])
			->from(self::TABLE)
			->where(['batchId' => $ids])
			->andWhere($conditions)
			->groupBy(['batchId'])
			->pairs() : [];

		$counts = [];
		foreach ($count(array_values(array_diff($ids, $whole)), $conditions) + $count($whole, ['and']) as $batchId => $number) {
			$counts[(int)$batchId] = (int)$number;
		}

		return $counts;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return Activity[]
	 */
	private function build(array $rows): array
	{
		$activities = [];
		foreach ($rows as $row) {
			$action = ActivityAction::tryFrom((string)$row['action']);
			$subject = ActivitySubject::tryFrom((string)$row['subject']);

			// Activities of actions or subjects no longer known, like those of a later version, are left out.
			if ($action === null || $subject === null) {
				continue;
			}

			$activity = new Activity();
			$activity->id = (int)$row['id'];
			$activity->batchId = isset($row['batchId']) ? (int)$row['batchId'] : null;
			$activity->action = $action;
			$activity->subject = $subject;
			$activity->subjectId = $row['subjectId'] !== null ? (int)$row['subjectId'] : null;
			$activity->subjectTitle = $row['subjectTitle'] ?? null;
			$activity->siteId = $row['siteId'] !== null ? (int)$row['siteId'] : null;
			$activity->userId = $row['userId'] !== null ? (int)$row['userId'] : null;
			$activity->userName = $row['userName'] ?? null;
			$activity->ipAddress = $row['ipAddress'] ?? null;
			$activity->data = is_string($row['data'] ?? null) ? (array)Json::decodeIfJson($row['data']) : [];
			$activity->dateCreated = DateTimeHelper::toDateTime($row['dateCreated']) ?: new DateTime();
			$activities[] = $activity;
		}

		return $activities;
	}

	/**
	 * Options of the users filter: the users of any activity, by their ID, in the order of their names.
	 *
	 * @return array<int,array{label:string,user:User|null}>
	 */
	public function getUserOptions(): array
	{
		// The latest name of each user, as they may have been renamed in between.
		$rows = (new Query())
			->select(['userId', 'userName'])
			->from(self::TABLE)
			->where(['id' => (new Query())->select('MAX([[id]])')->from(self::TABLE)->where(['not', ['userId' => null]])->groupBy('userId')])
			->all();

		$users = $this->users(array_map(fn (array $row) => (int)$row['userId'], $rows));

		$options = [];
		foreach ($rows as $row) {
			$user = $users[(int)$row['userId']] ?? null;
			$options[(int)$row['userId']] = ['label' => $user?->getName() ?? (string)$row['userName'], 'user' => $user];
		}

		uasort($options, fn (array $a, array $b) => strnatcasecmp($a['label'], $b['label']));

		return $options;
	}

	/**
	 * Deletes the activities older than they're kept for (see Settings::$activityRetentionDays), along with Craft's garbage.
	 */
	public function deleteExpired(): void
	{
		$days = Hub::getInstance()->settings->activityRetentionDays;

		if ($days < 1 || !$this->tableExists()) {
			return;
		}

		Craft::$app->getDb()->createCommand()
			->delete(self::TABLE, ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-$days days"))])
			->execute();
	}

	/**
	 * Names of the permissions granted to a user directly, rather than by their groups.
	 *
	 * @return string[]
	 */
	public function getUserPermissions(int $userId): array
	{
		return (new Query())
			->select(['permissions.name'])
			->from(['permissions' => Table::USERPERMISSIONS])
			->innerJoin(['userPermissions' => Table::USERPERMISSIONS_USERS], '[[userPermissions.permissionId]] = [[permissions.id]]')
			->where(['userPermissions.userId' => $userId])
			->column();
	}

	protected function tableExists(): bool
	{
		return $this->tableExists ??= Craft::$app->getDb()->tableExists(self::TABLE);
	}

	/**
	 * Columns of an activity, by the user given; the user impersonating them is kept along, unless they did it themselves.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function columns(ActivityAction $action, ActivitySubject $subject, ?int $subjectId, ?string $subjectTitle, array $data, ?int $siteId, ?User $user): array
	{
		$request = Craft::$app->getRequest();
		$web = !$request->getIsConsoleRequest();

		if ($web && ($impersonator = $this->impersonator()) !== null && $impersonator->id !== $user?->id) {
			$data['impersonator'] = $impersonator->getName();
		}

		return [
			'action' => $action->value,
			'subject' => $subject->value,
			'subjectId' => $subjectId,
			'subjectTitle' => $this->truncate($subjectTitle, 255),
			'siteId' => $siteId,
			'userId' => $user?->id,
			'userName' => $this->truncate($user?->getName(), 255),
			'ipAddress' => $web ? $this->truncate($request->getUserIP(), 45) : null,
			'data' => $data !== [] ? Json::encode($data) : null,
		];
	}

	/**
	 * Inserts an activity, returning its ID.
	 *
	 * @param array<string,mixed> $columns
	 */
	private function insert(array $columns): int
	{
		$db = Craft::$app->getDb();
		$db->createCommand()->insert(self::TABLE, $columns)->execute();

		return (int)$db->getLastInsertID(self::TABLE);
	}

	/**
	 * Conditions of the filters, other than the batch, as a condition of all of them.
	 *
	 * @return array<int,mixed>
	 */
	private function conditions(ActivityFilter $filter): array
	{
		$conditions = ['and'];

		if ($filter->search !== '') {
			// PostgreSQL's `like` is case-sensitive, unlike MySQL's with Craft's collation.
			$operator = Sql::likeOperator();
			$conditions[] = [
				'or',
				[$operator, 'subjectTitle', $filter->search],
				[$operator, 'userName', $filter->search],
				[$operator, 'ipAddress', $filter->search],
			];
		}

		if ($filter->actions !== []) {
			$actions = array_merge(...array_map(fn (string $group) => ActivityGroup::from($group)->actions(), $filter->actions));
			$conditions[] = ['action' => array_map(fn (ActivityAction $action) => $action->value, $actions)];
		}

		if ($filter->subjects !== []) {
			$conditions[] = ['subject' => $filter->subjects];
		}

		if ($filter->users !== []) {
			$ids = array_map('intval', array_diff($filter->users, [ActivityFilter::USER_NONE]));
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
	 * Users by their ID, with their photos, including suspended and pending ones.
	 *
	 * @param int[] $ids
	 * @return array<int,User>
	 */
	private function users(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$users = [];
		foreach (User::find()->id(array_values(array_unique($ids)))->status(null)->with(['photo'])->all() as $user) {
			$users[(int)$user->id] = $user;
		}

		return $users;
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
		$existing = $elementIds !== [] ? array_map('intval', (new Query())
			->select(['id'])
			->from(Table::ELEMENTS)
			->where(['id' => array_values($elementIds), 'dateDeleted' => null])
			->column()) : [];

		$webUser = Craft::$app->getUser();
		$isAdmin = $webUser->getIsAdmin();

		$urls = [];
		foreach ($ids as $kind => $subjectIds) {
			$subject = ActivitySubject::from($kind);

			foreach ($subjectIds as $id) {
				if (!($subject->isElement() ? in_array($id, $existing, true) : $this->exists($subject, $id))) {
					continue;
				}

				if (isset(self::PATHS[$kind]) && $this->canOpenPage($kind)) {
					$urls[$kind][$id] = Hub::getInstance()->siteScope->pageUrl(self::PATHS[$kind] . "/$id");
				} elseif ($subject->isElement()) {
					$urls[$kind][$id] = UrlHelper::cpUrl("edit/$id");
				} elseif (isset(self::SETTINGS_PATHS[$kind]) && $isAdmin) {
					$urls[$kind][$id] = UrlHelper::cpUrl(self::SETTINGS_PATHS[$kind] . "/$id");
				} elseif (isset(self::HUB_PATHS[$kind]) && $webUser->checkPermission(self::HUB_PATHS[$kind][1])) {
					$urls[$kind][$id] = UrlHelper::cpUrl(self::HUB_PATHS[$kind][0] . "/$id");
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
		return Hub::getInstance()->access->canView(match ($kind) {
			'entry' => Page::Entries,
			'asset' => Page::Assets,
			'user' => Page::Users,
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
			ActivitySubject::Section => Craft::$app->getEntries()->getSectionById($id) !== null,
			ActivitySubject::EntryType => Craft::$app->getEntries()->getEntryTypeById($id) !== null,
			ActivitySubject::Field => Craft::$app->getFields()->getFieldById($id) !== null,
			ActivitySubject::Volume => Craft::$app->getVolumes()->getVolumeById($id) !== null,
			ActivitySubject::UserGroup => Craft::$app->getUserGroups()->getGroupById($id) !== null,
			ActivitySubject::Site => Craft::$app->getSites()->getSiteById($id) !== null,
			ActivitySubject::CategoryGroup => Craft::$app->getCategories()->getGroupById($id) !== null,
			ActivitySubject::TagGroup => Craft::$app->getTags()->getTagGroupById($id) !== null,
			ActivitySubject::GqlSchema => Craft::$app->getGql()->getSchemaById($id) !== null,
			ActivitySubject::GqlToken => Craft::$app->getGql()->getTokenById($id) !== null,
			ActivitySubject::Label => Hub::getInstance()->labels->getLabelById($id) !== null,
			ActivitySubject::Check => Hub::getInstance()->diagnostics->getDefinitionById($id) !== null,
			ActivitySubject::Filter => Hub::getInstance()->filters->getFilterById($id) !== null,
			default => false,
		};
	}

	private function currentUser(): ?User
	{
		$user = Craft::$app->getUser()->getIdentity();

		return $user instanceof User ? $user : null;
	}

	/**
	 * The user impersonating the current one, if any; the session isn't started for it, as guests on the site have none.
	 */
	private function impersonator(): ?User
	{
		$session = Craft::$app->getSession();

		if (!$session->getIsActive() || !is_numeric($id = $session->get(User::IMPERSONATE_KEY))) {
			return null;
		}

		return Craft::$app->getUsers()->getUserById((int)$id);
	}

	private function truncate(?string $value, int $length): ?string
	{
		return $value !== null && $value !== '' ? mb_substr($value, 0, $length) : null;
	}
}
