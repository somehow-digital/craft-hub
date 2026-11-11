<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use Closure;
use Craft;
use craft\base\FieldInterface;
use craft\db\Connection;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\jobs\TakeSnapshotJob;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\LayoutUsage;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use WeakMap;
use yii\base\Component;
use yii\caching\CacheInterface;

/**
 * Snapshots of the statistics, taken once a day or less often, so the statistics tabs can show how they changed.
 *
 * A snapshot keeps the counts of the entries, assets and references of all sites, by the sets of sites the items are on,
 * so they're counted for any sites later, just like the live ones (see StatisticsService::withSnapshot()), along with its
 * metrics that aren't scoped to sites (see collectMetrics()).
 *
 * Snapshots are taken in the queue, pushed by a control panel request once one is due, or by `./craft hub/snapshots`.
 *
 * @internal
 */
final class SnapshotService extends Component
{
	/** tables of the rows of the snapshots, by the part of the snapshot they are, see Snapshot */
	private const ROW_TABLES = ['entries' => Tables::SNAPSHOT_ENTRIES, 'assets' => Tables::SNAPSHOT_ASSETS, 'references' => Tables::SNAPSHOT_REFERENCES];

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

	/** @var WeakMap<Snapshot,StatisticsService>|null */
	private ?WeakMap $statisticsOf = null;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly StatisticsService $statistics,
		private readonly StructureService $structure,
		private readonly VolumeService $volumes,
		private readonly UserService $users,
		private readonly Settings $settings,
		private readonly Connection $db,
		private readonly CacheInterface $cache,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * Takes the snapshot of today, unless there is one already, which it replaces instead if asked to.
	 *
	 * @return bool whether it was taken
	 */
	public function take(bool $replace = false): bool
	{
		$date = $this->today();
		$existing = (new Query())->select(['id'])->from(Tables::SNAPSHOTS)->where(['date' => $date])->scalar();

		if ($existing !== false && $existing !== null && !$replace) {
			return false;
		}

		$statistics = $this->statistics;
		$entries = $statistics->collectEntryRows();
		$assets = $statistics->collectAssetRows();
		$references = $statistics->collectReferenceRows();
		$metrics = $this->collectMetrics();

		$db = $this->db;
		$db->transaction(function () use ($db, $date, $existing, $metrics, $entries, $assets, $references): void {
			if ($existing !== false && $existing !== null) {
				$db->createCommand()->delete(Tables::SNAPSHOTS, ['id' => (int) $existing])->execute();
			}

			$db->createCommand()->insert(Tables::SNAPSHOTS, ['date' => $date, 'metrics' => Json::encode($metrics)])->execute();
			$id = (int) $db->getLastInsertID(Tables::SNAPSHOTS);

			$this->insertRows(Tables::SNAPSHOT_ENTRIES, $id, $entries);
			$this->insertRows(Tables::SNAPSHOT_ASSETS, $id, $assets);
			$this->insertRows(Tables::SNAPSHOT_REFERENCES, $id, $references);
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

		if (!$latest instanceof DateTime) {
			return true;
		}

		$interval = max(1, $this->settings->snapshotInterval);

		return $latest->format('Y-m-d') <= $this->now()->modify("-{$interval} days")->format('Y-m-d');
	}

	/**
	 * Pushes a job taking a snapshot to the queue once one is due, checked at most once an hour.
	 */
	public function pushIfDue(): void
	{
		if (!$this->settings->enableSnapshots) {
			return;
		}

		$cache = $this->cache;

		// The cache is checked first, so most requests don't query the database at all.
		if (!$cache->add(self::CHECKED_KEY, true, self::CHECK_INTERVAL) || !$this->tableExists()) {
			return;
		}

		if ($this->isDue()) {
			Queue::push(new TakeSnapshotJob());
			$cache->set(self::CHECKED_KEY, true, self::PUSHED_INTERVAL);
		}
	}

	public function getLatestDate(): ?DateTime
	{
		$date = (new Query())->from(Tables::SNAPSHOTS)->max('[[date]]');

		return is_string($date) ? $this->toDate($date) : null;
	}

	public function getEarliestDate(): ?DateTime
	{
		$date = (new Query())->from(Tables::SNAPSHOTS)->min('[[date]]');

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

		return $this->snapshots["{$day}:{$limit}"] ??= $this->loadSnapshots($day, $limit);
	}

	/**
	 * Chart of a statistics card over a range of days: its total and the values of its segments in each snapshot of the
	 * range, then their current values, with the change of the total since the first snapshot; null without snapshots
	 * before today, or while snapshots are disabled. A range without snapshots has the current values only. Of the ranges,
	 * only those up to the first one spanning all snapshots are offered, as the longer ones would chart the same.
	 *
	 * The card in each snapshot (see BaseSummary::lazyCard()) has its total, and its segments if it has them there too;
	 * those are matched to the current ones by their label, and left out unless any snapshot has them.
	 *
	 * @param Closure(Snapshot):array{total:int|float,segments?:array<int,array<string,mixed>>} $card the card in a snapshot
	 * @param array{label:string,total:int|float,segments?:array<int,array<string,mixed>>} $current the current card
	 * @param int $range days, one of RANGES
	 * @return array{range:int,ranges:int[],since:?DateTime,change:int|float|null,dates:string[],series:array<int,array{key:string,label:string,color:?string,dashed:bool,values:array<int,int|float|null>}>}|null
	 */
	public function chart(Closure $card, array $current, int $range = self::RANGES[0]): ?array
	{
		if (!$this->settings->enableSnapshots || !$this->tableExists()) {
			return null;
		}

		$today = $this->today();
		$earliest = $this->getEarliestDate();

		if (!$earliest instanceof DateTime || $earliest->format('Y-m-d') >= $today) {
			return null;
		}

		$range = in_array($range, self::RANGES, true) ? $range : self::RANGES[0];
		$since = $range > 0 ? $this->now()->modify("-{$range} days") : null;
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
			'values' => [...array_map(fn(array $card): int|float => $card['total'], $cards), $current['total']],
		]];

		foreach ($current['segments'] ?? [] as $segment) {
			$values = array_map(function (array $card) use ($segment): int|float|null {
				foreach ($card['segments'] ?? [] as $past) {
					if ($past['label'] === $segment['label']) {
						return is_int($past['value'] ?? null) || is_float($past['value'] ?? null) ? $past['value'] : null;
					}
				}

				return null;
			}, $cards);

			if (array_filter($values, fn(mixed $value): bool => $value !== null) === []) {
				continue;
			}

			$series[] = [
				'key' => StringHelper::toKebabCase(Row::string($segment, 'label')),
				'label' => Row::string($segment, 'label'),
				'color' => Fill::text($segment['color'] ?? null),
				// Segments kept out of the bar aren't part of the total, like drafts.
				'dashed' => ($segment['bar'] ?? true) === false,
				'values' => [...$values, is_int($segment['value'] ?? null) || is_float($segment['value'] ?? null) ? $segment['value'] : null],
			];
		}

		return [
			'range' => $range,
			'ranges' => $this->ranges($earliest->diff($this->now())->days),
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
		$days = $this->settings->snapshotRetentionDays;

		if ($days <= 0 || !$this->tableExists()) {
			return 0;
		}

		$before = $this->now()->modify("-{$days} days")->format('Y-m-d');

		return $this->db->createCommand()->delete(Tables::SNAPSHOTS, ['<', 'date', $before])->execute();
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
		$fields = $this->structure->getFields();
		$ids = fn(array $models): array => array_values(array_map(fn(mixed $model): int => (int) $model->id, $models));

		return [
			'sections' => array_map(fn(SectionInfo $info): array => [
				'type' => $info->section->type,
				'entryTypes' => $ids($info->entryTypes),
				'fields' => $ids([...$info->referenceFields, ...$info->linkFields]),
			], $this->structure->getSections()),
			'entryTypes' => array_map(fn(EntryTypeInfo $info): array => [
				'sections' => count($info->sections),
				'nestedFields' => count($info->nestedFields),
				'referenceFields' => count($info->referenceFields),
				'linkFields' => count($info->linkFields),
				'fields' => array_count_values(array_map(
					fn(FieldInterface $field) => ($fields[(int) $field->id] ?? null)?->getDataKind()->value ?? DataKind::Plain->value,
					$info->entryType->getFieldLayout()->getCustomFields(),
				)),
			], $this->structure->getEntryTypes()),
			'fields' => array_map(fn(FieldInfo $info): array => [
				'type' => $info->field::class,
				'kind' => $info->getDataKind()->value,
				'entryTypes' => $ids($info->entryTypes),
				'owners' => array_count_values(array_map(fn(LayoutUsage $usage): string => $usage->ownerType, $info->layouts)),
			], $fields),
			'volumes' => array_map(fn(VolumeInfo $info): array => [
				'hasUrls' => $info->hasUrls,
				'fields' => $ids([...$info->assetFields, ...$info->linkFields]),
			], $this->volumes->getVolumes()),
			'users' => get_object_vars($this->users->getStatistics()),
			'userGroups' => array_map(fn(array $info): int => $info['users'], $this->users->getGroups()),
		];
	}

	/**
	 * Whether the tables of the snapshots exist, which they don't while Hub is being installed.
	 */
	private function tableExists(): bool
	{
		return $this->tableExists ??= $this->db->tableExists(Tables::SNAPSHOTS);
	}

	/**
	 * Ranges up to the first one spanning all snapshots, the earliest of which is as many days old.
	 *
	 * @return list<int>
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
			->from(Tables::SNAPSHOTS)
			->andFilterWhere(['>=', 'date', $since !== '' ? $since : null])
			->orderBy(['date' => SORT_ASC])
			->all();

		$count = count($results);

		// Spread evenly, keeping the first and the last one.
		if ($limit > 1 && $count > $limit) {
			$results = array_map(fn(int $i): mixed => $results[(int) round($i * ($count - 1) / ($limit - 1))], range(0, $limit - 1));
		}

		$ids = array_map(fn(array $result): int => Row::int($result, 'id'), $results);
		$parts = [];

		// Parts of all the snapshots, loaded once the first one asks for them.
		$part = function (string $name, int $id) use (&$parts, $ids): array {
			$parts[$name] ??= $name === 'metrics' ? $this->loadMetrics($ids) : $this->loadRows(self::ROW_TABLES[$name], $ids);

			return $parts[$name][$id] ?? [];
		};

		return array_map(fn(array $result): Snapshot => new Snapshot(
			Row::int($result, 'id'),
			$this->toDate(Row::string($result, 'date')),
			fn(string $name): array => $part($name, Row::int($result, 'id')),
		), $results);
	}

	/**
	 * Metrics of the snapshots, by the snapshot.
	 *
	 * @param int[] $ids
	 * @return array<int|string,array<string,mixed>>
	 */
	private function loadMetrics(array $ids): array
	{
		$metrics = (new Query())->select(['id', 'metrics'])->from(Tables::SNAPSHOTS)->where(['id' => $ids])->pairs();

		return array_map(fn(mixed $json): array => is_string($json) ? Fill::map(Json::decodeIfJson($json)) : [], $metrics);
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
			$rows[Row::int($row, 'snapshotId')][] = $row;
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
			$this->db->createCommand()
				->batchInsert($table, $columns, array_map(fn(array $row): array => [$snapshotId, ...array_values($row)], $batch))
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
		return DateTimeHelper::now(new DateTimeZone(date_default_timezone_get()));
	}

	private function toDate(string $date): DateTime
	{
		return Fill::date(DateTime::createFromFormat('!Y-m-d', substr($date, 0, 10), new DateTimeZone(date_default_timezone_get()))) ?? $this->now();
	}

	/**
	 * The statistics of a snapshot, counting the rows it kept instead of the content, summed like the live ones.
	 */
	public function getStatistics(Snapshot $snapshot): StatisticsService
	{
		$this->statisticsOf ??= new WeakMap();

		return $this->statisticsOf[$snapshot] ??= $this->statistics->withSnapshot($snapshot->parts);
	}
}
