<?php

namespace SomehowDigital\Craft\Hub\services;

use Closure;
use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\jobs\TakeSnapshot;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\LayoutUsage;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use yii\base\Component;

/**
 * Snapshots of the statistics, taken once a day or less often, so the statistics tabs can show how they changed.
 *
 * A snapshot keeps the counts of the entries, assets and references of all sites, by the sets of sites the items are on,
 * so they're counted for any sites later, just like the live ones (see StatisticsService::fromSnapshot()), along with its
 * metrics that aren't scoped to sites (see collectMetrics()).
 *
 * Snapshots are taken in the queue, pushed by a control panel request once one is due, or by `./craft hub/snapshots`.
 */
class SnapshotService extends Component
{
	public const TABLE = '{{%hub_snapshots}}';
	public const TABLE_ENTRIES = '{{%hub_snapshot_entries}}';
	public const TABLE_ASSETS = '{{%hub_snapshot_assets}}';
	public const TABLE_REFERENCES = '{{%hub_snapshot_references}}';

	/** tables of the rows of the snapshots, by the part of the snapshot they are, see Snapshot */
	private const ROW_TABLES = ['entries' => self::TABLE_ENTRIES, 'assets' => self::TABLE_ASSETS, 'references' => self::TABLE_REFERENCES];

	/** days the charts of the statistics cards can span, the first by default; 0 spans all snapshots */
	public const RANGES = [30, 90, 365, 0];

	/** most snapshots a chart shows, so long ranges load only some of them, spread evenly */
	public const MAX_POINTS = 90;

	/** cache key of the time a request last checked whether a snapshot is due, so not every request checks */
	private const CHECKED_KEY = 'hub:snapshots:checked';

	/** seconds until the next request checks whether a snapshot is due */
	private const CHECK_INTERVAL = 3600;

	/** seconds until the next request checks again after pushing a snapshot, so it isn't pushed again while the queue is busy */
	private const PUSHED_INTERVAL = 6 * 3600;

	private const BATCH_SIZE = 500;

	/** @var array<string,array<int,Snapshot>> snapshots since a day, by the day and the most loaded */
	private array $snapshots = [];

	private ?bool $tableExists = null;

	/**
	 * Takes the snapshot of today, unless there is one already, which it replaces instead if asked to.
	 *
	 * @return bool whether it was taken
	 */
	public function take(bool $replace = false): bool
	{
		$date = $this->today();
		$existing = (new Query())->select(['id'])->from(self::TABLE)->where(['date' => $date])->scalar();

		if ($existing !== false && $existing !== null && !$replace) {
			return false;
		}

		$statistics = new StatisticsService();
		$entries = $statistics->collectEntryRows();
		$assets = $statistics->collectAssetRows();
		$references = $statistics->collectReferenceRows();
		$metrics = $this->collectMetrics();

		$db = Craft::$app->getDb();
		$db->transaction(function () use ($db, $date, $existing, $metrics, $entries, $assets, $references) {
			if ($existing !== false && $existing !== null) {
				$db->createCommand()->delete(self::TABLE, ['id' => (int)$existing])->execute();
			}

			$db->createCommand()->insert(self::TABLE, ['date' => $date, 'metrics' => Json::encode($metrics)])->execute();
			$id = (int)$db->getLastInsertID(self::TABLE);

			$this->insertRows(self::TABLE_ENTRIES, $id, $entries);
			$this->insertRows(self::TABLE_ASSETS, $id, $assets);
			$this->insertRows(self::TABLE_REFERENCES, $id, $references);
		});

		$this->snapshots = [];

		return true;
	}

	/**
	 * Whether a snapshot is due: there is none yet, or the last one is as old as the interval of the snapshots.
	 */
	public function isDue(): bool
	{
		$latest = $this->getLatestDate();

		if ($latest === null) {
			return true;
		}

		$interval = max(1, Hub::getInstance()->settings->snapshotInterval);

		return $latest->format('Y-m-d') <= $this->now()->modify("-$interval days")->format('Y-m-d');
	}

	/**
	 * Pushes a job taking a snapshot to the queue once one is due, checked at most once an hour.
	 */
	public function pushIfDue(): void
	{
		if (!Hub::getInstance()->settings->enableSnapshots) {
			return;
		}

		$cache = Craft::$app->getCache();

		// The cache is checked first, so most requests don't query the database at all.
		if (!$cache->add(self::CHECKED_KEY, true, self::CHECK_INTERVAL) || !$this->tableExists()) {
			return;
		}

		if ($this->isDue()) {
			Queue::push(new TakeSnapshot());
			$cache->set(self::CHECKED_KEY, true, self::PUSHED_INTERVAL);
		}
	}

	public function getLatestDate(): ?DateTime
	{
		$date = (new Query())->from(self::TABLE)->max('[[date]]');

		return is_string($date) ? $this->toDate($date) : null;
	}

	public function getEarliestDate(): ?DateTime
	{
		$date = (new Query())->from(self::TABLE)->min('[[date]]');

		return is_string($date) ? $this->toDate($date) : null;
	}

	/**
	 * Snapshots since a day, or all, the oldest first, each with the statistics of its rows; at most as many as asked for,
	 * spread evenly from the first to the last one. Their parts are loaded for all of them at once, once one asks for them.
	 *
	 * @return array<int,Snapshot>
	 */
	public function getSnapshots(?DateTime $since, int $limit = 0): array
	{
		$day = $since?->format('Y-m-d') ?? '';

		return $this->snapshots["$day:$limit"] ??= $this->loadSnapshots($day, $limit);
	}

	/**
	 * Chart of a statistics card over a range of days: its total and the values of its segments in each snapshot of the
	 * range, then their current values, with the change of the total since the first snapshot; null without snapshots
	 * before today, or while snapshots are disabled. A range without snapshots has the current values only. Of the ranges,
	 * only those up to the first one spanning all snapshots are offered, as the longer ones would chart the same.
	 *
	 * The card in each snapshot (see BaseController::lazyCard()) has its total, and its segments if it has them there too;
	 * those are matched to the current ones by their label, and left out unless any snapshot has them.
	 *
	 * @param Closure(Snapshot):array{total:int|float,segments?:array<int,array<string,mixed>>} $card the card in a snapshot
	 * @param array{label:string,total:int|float,segments?:array<int,array<string,mixed>>} $current the current card
	 * @param int $range days, one of RANGES
	 * @return array{range:int,ranges:int[],since:?DateTime,change:int|float|null,dates:string[],series:array<int,array{key:string,label:string,color:?string,dashed:bool,values:array<int,int|float|null>}>}|null
	 */
	public function chart(Closure $card, array $current, int $range = self::RANGES[0]): ?array
	{
		if (!Hub::getInstance()->settings->enableSnapshots || !$this->tableExists()) {
			return null;
		}

		$today = $this->today();
		$earliest = $this->getEarliestDate();

		if ($earliest === null || $earliest->format('Y-m-d') >= $today) {
			return null;
		}

		$range = in_array($range, self::RANGES, true) ? $range : self::RANGES[0];
		$since = $range > 0 ? $this->now()->modify("-$range days") : null;
		$dates = [];
		$cards = [];

		foreach ($this->getSnapshots($since, self::MAX_POINTS) as $snapshot) {
			// The current values stand for today.
			if ($snapshot->date->format('Y-m-d') === $today) {
				continue;
			}

			$dates[] = $snapshot->date->format('Y-m-d');
			$cards[] = $card($snapshot);
		}

		$series = [[
			'key' => 'total',
			'label' => Craft::t('hub', 'Total'),
			'color' => null,
			'dashed' => false,
			'values' => [...array_map(fn (array $card) => $card['total'], $cards), $current['total']],
		]];

		foreach ($current['segments'] ?? [] as $segment) {
			$values = array_map(function (array $card) use ($segment) {
				foreach ($card['segments'] ?? [] as $past) {
					if ($past['label'] === $segment['label']) {
						return $past['value'];
					}
				}

				return null;
			}, $cards);

			if (array_filter($values, fn ($value) => $value !== null) === []) {
				continue;
			}

			$series[] = [
				'key' => StringHelper::toKebabCase((string)$segment['label']),
				'label' => (string)$segment['label'],
				'color' => $segment['color'] ?? null,
				// Segments kept out of the bar aren't part of the total, like drafts.
				'dashed' => !($segment['bar'] ?? true),
				'values' => [...$values, $segment['value']],
			];
		}

		return [
			'range' => $range,
			'ranges' => $this->ranges((int)$earliest->diff($this->now())->days),
			'since' => $dates !== [] ? $this->toDate($dates[0]) : null,
			'change' => $cards !== [] ? $current['total'] - $cards[0]['total'] : null,
			'dates' => [...$dates, $today],
			'series' => $series,
		];
	}

	/**
	 * Deletes the snapshots older than the retention, along with Craft's garbage; none while they're kept forever.
	 *
	 * @return int how many were deleted
	 */
	public function deleteExpired(): int
	{
		$days = Hub::getInstance()->settings->snapshotRetentionDays;

		if ($days <= 0 || !$this->tableExists()) {
			return 0;
		}

		$before = $this->now()->modify("-$days days")->format('Y-m-d');

		return Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['<', 'date', $before])->execute();
	}

	/**
	 * Metrics of the snapshot that aren't scoped to sites: how the sections, entry types, fields and volumes are set up, by
	 * their ID, so the cards counting them by how they're used are counted in a snapshot too, for any sites, along with its
	 * rows (see Snapshot::getSections()), the statistics of the users and the users of each group:
	 * - sections: their type, their entry types, and the Entries and Link fields that can reference their entries
	 * - entry types: how many sections and fields use them, and their fields by the data they hold
	 * - fields: their class, the data they hold, their entry types, and their field layouts by the type of their owner
	 * - volumes: whether they have URLs, and the Assets and Link fields that can relate to their assets
	 *
	 * @return array<string,mixed>
	 */
	public function collectMetrics(): array
	{
		$hub = Hub::getInstance();
		$fields = $hub->structure->getFields();
		$ids = fn (array $models) => array_values(array_map(fn ($model) => (int)$model->id, $models));

		return [
			'sections' => array_map(fn (SectionInfo $info) => [
				'type' => $info->section->type,
				'entryTypes' => $ids($info->entryTypes),
				'fields' => $ids([...$info->referenceFields, ...$info->linkFields]),
			], $hub->structure->getSections()),
			'entryTypes' => array_map(fn (EntryTypeInfo $info) => [
				'sections' => count($info->sections),
				'nestedFields' => count($info->nestedFields),
				'referenceFields' => count($info->referenceFields),
				'linkFields' => count($info->linkFields),
				'fields' => array_count_values(array_map(
					fn (FieldInterface $field) => ($fields[$field->id] ?? null)?->getDataKind()->value ?? DataKind::Plain->value,
					$info->entryType->getFieldLayout()->getCustomFields(),
				)),
			], $hub->structure->getEntryTypes()),
			'fields' => array_map(fn (FieldInfo $info) => [
				'type' => get_class($info->field),
				'kind' => $info->getDataKind()->value,
				'entryTypes' => $ids($info->entryTypes),
				'owners' => array_count_values(array_map(fn (LayoutUsage $usage) => $usage->ownerType, $info->layouts)),
			], $fields),
			'volumes' => array_map(fn (VolumeInfo $info) => [
				'hasUrls' => $info->hasUrls,
				'fields' => $ids([...$info->assetFields, ...$info->linkFields]),
			], $hub->volumes->getVolumes()),
			'users' => get_object_vars($hub->users->getStatistics()),
			'userGroups' => array_map(fn (array $info) => $info['users'], $hub->users->getGroups()),
		];
	}

	/**
	 * Whether the tables of the snapshots exist, which they don't while Hub is being installed.
	 */
	protected function tableExists(): bool
	{
		return $this->tableExists ??= Craft::$app->getDb()->tableExists(self::TABLE);
	}

	/**
	 * Ranges up to the first one spanning all snapshots, the earliest of which is as many days old.
	 *
	 * @return int[]
	 */
	private function ranges(int $days): array
	{
		$ranges = [];

		foreach (self::RANGES as $range) {
			$ranges[] = $range;

			if ($range === 0 || $range >= $days) {
				break;
			}
		}

		return $ranges;
	}

	/**
	 * @return array<int,Snapshot>
	 */
	private function loadSnapshots(string $since, int $limit): array
	{
		$results = (new Query())
			->select(['id', 'date'])
			->from(self::TABLE)
			->andFilterWhere(['>=', 'date', $since !== '' ? $since : null])
			->orderBy(['date' => SORT_ASC])
			->all();

		$count = count($results);

		// Spread evenly, keeping the first and the last one.
		if ($limit > 1 && $count > $limit) {
			$results = array_map(fn (int $i) => $results[(int)round($i * ($count - 1) / ($limit - 1))], range(0, $limit - 1));
		}

		$ids = array_map(fn (array $result) => (int)$result['id'], $results);
		$parts = [];

		// Parts of all the snapshots, loaded once the first one asks for them.
		$part = function (string $name, int $id) use (&$parts, $ids): array {
			$parts[$name] ??= $name === 'metrics' ? $this->loadMetrics($ids) : $this->loadRows(self::ROW_TABLES[$name], $ids);

			return $parts[$name][$id] ?? [];
		};

		return array_map(fn (array $result) => new Snapshot(
			(int)$result['id'],
			$this->toDate((string)$result['date']),
			fn (string $name) => $part($name, (int)$result['id']),
		), $results);
	}

	/**
	 * Metrics of the snapshots, by the snapshot.
	 *
	 * @param int[] $ids
	 * @return array<int,array<string,mixed>>
	 */
	private function loadMetrics(array $ids): array
	{
		$metrics = (new Query())->select(['id', 'metrics'])->from(self::TABLE)->where(['id' => $ids])->pairs();

		return array_map(fn ($json) => is_string($json) && is_array($decoded = Json::decodeIfJson($json)) ? $decoded : [], $metrics);
	}

	/**
	 * Rows of the snapshots in a table, by the snapshot.
	 *
	 * @param int[] $ids
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	private function loadRows(string $table, array $ids): array
	{
		$rows = [];

		foreach ((new Query())->from($table)->where(['snapshotId' => $ids])->all() as $row) {
			$rows[(int)$row['snapshotId']][] = $row;
		}

		return $rows;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function insertRows(string $table, int $snapshotId, array $rows): void
	{
		if ($rows === []) {
			return;
		}

		$columns = ['snapshotId', ...array_keys($rows[0])];

		foreach (array_chunk($rows, self::BATCH_SIZE) as $batch) {
			Craft::$app->getDb()->createCommand()
				->batchInsert($table, $columns, array_map(fn (array $row) => [$snapshotId, ...array_values($row)], $batch))
				->execute();
		}
	}

	/**
	 * Today, in the time zone of the system, which snapshots are kept by.
	 */
	private function today(): string
	{
		return $this->now()->format('Y-m-d');
	}

	private function now(): DateTime
	{
		return DateTimeHelper::now(new DateTimeZone(Craft::$app->getTimeZone()));
	}

	private function toDate(string $date): DateTime
	{
		return DateTime::createFromFormat('!Y-m-d', substr($date, 0, 10), new DateTimeZone(Craft::$app->getTimeZone())) ?: $this->now();
	}
}
