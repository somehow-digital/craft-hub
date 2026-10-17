<?php

namespace SomehowDigital\Craft\Hub\models;

use Closure;
use DateTime;
use SomehowDigital\Craft\Hub\services\StatisticsService;

/**
 * The statistics as they were on a day, see SnapshotService: the counts of the entries, assets and references, summed like
 * the live ones by the statistics of the snapshot, and further counts that aren't scoped to sites, like those of the users.
 *
 * Its parts are loaded once asked for, so a card only loads the parts it counts.
 */
class Snapshot
{
	public readonly StatisticsService $statistics;

	/** @var array<string,mixed>|null */
	private ?array $metrics = null;

	/**
	 * @param Closure(string):array<int|string,mixed> $parts a part of the snapshot: its `metrics` (see
	 * SnapshotService::collectMetrics()), or the rows of its `entries`, `assets` or `references`
	 */
	public function __construct(
		public readonly int $id,
		public readonly DateTime $date,
		private readonly Closure $parts,
	) {
		$this->statistics = StatisticsService::fromSnapshot($parts);
	}

	/**
	 * Sections of the snapshot by their ID, see SnapshotService::collectMetrics().
	 *
	 * @return array<int,array{type:string,entryTypes:int[],fields:int[]}>
	 */
	public function getSections(): array
	{
		return $this->metric('sections');
	}

	/**
	 * @return array<int,array{sections:int,nestedFields:int,referenceFields:int,linkFields:int,fields:array<string,int>}>
	 */
	public function getEntryTypes(): array
	{
		return $this->metric('entryTypes');
	}

	/**
	 * @return array<int,array{type:string,kind:string,entryTypes:int[],owners:array<string,int>}>
	 */
	public function getFields(): array
	{
		return $this->metric('fields');
	}

	/**
	 * @return array<int,array{hasUrls:bool,fields:int[]}>
	 */
	public function getVolumes(): array
	{
		return $this->metric('volumes');
	}

	/**
	 * Users of the groups by their ID.
	 *
	 * @return array<int,int>
	 */
	public function getUserGroups(): array
	{
		return array_map('intval', $this->metric('userGroups'));
	}

	public function getUsers(): UserStatistics
	{
		$stats = new UserStatistics();

		foreach ($this->metric('users') as $property => $value) {
			if (property_exists($stats, $property)) {
				$stats->$property = (int)$value;
			}
		}

		return $stats;
	}

	/**
	 * @return array<int|string,mixed>
	 */
	private function metric(string $name): array
	{
		$this->metrics ??= ($this->parts)('metrics');

		return (array)($this->metrics[$name] ?? []);
	}
}
