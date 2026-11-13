<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use Closure;
use craft\base\Model;
use DateTime;
use SomehowDigital\Craft\Hub\helpers\Fill;

/**
 * The statistics as they were on a day, see SnapshotService: the counts of the entries, assets and references, summed like
 * the live ones by SnapshotService::getStatistics(), and further counts that aren't scoped to sites, like those of the users.
 *
 * Its parts are loaded once asked for, so a card only loads the parts it counts.
 *
 * @internal
 */
final class Snapshot extends Model
{
	/** @var array<string,mixed>|null */
	private ?array $metrics = null;

	/**
	 * @param Closure(string):array<int|string,mixed> $parts a part of the snapshot: its `metrics` (see
	 * SnapshotService::collectMetrics()), or the rows of its `entries`, `assets` or `references`
	 */
	public function __construct(
		public readonly int $id,
		public readonly DateTime $date,
		public readonly Closure $parts,
	) {
		parent::__construct();
	}

	/**
	 * Sections of the snapshot by their ID, see SnapshotService::collectMetrics().
	 *
	 * @return array<int,array{type:string,entryTypes:int[],fields:int[]}>
	 */
	public function getSections(): array
	{
		/** @var array<int,array{type:string,entryTypes:int[],fields:int[]}> $result */
		$result = $this->metric('sections');

		return $result;
	}

	/**
	 * @return array<int,array{sections:int,nestedFields:int,referenceFields:int,linkFields:int,fields:array<string,int>}>
	 */
	public function getEntryTypes(): array
	{
		/** @var array<int,array{sections:int,nestedFields:int,referenceFields:int,linkFields:int,fields:array<string,int>}> $result */
		$result = $this->metric('entryTypes');

		return $result;
	}

	/**
	 * @return array<int,array{type:string,kind:string,entryTypes:int[],owners:array<string,int>}>
	 */
	public function getFields(): array
	{
		/** @var array<int,array{type:string,kind:string,entryTypes:int[],owners:array<string,int>}> $result */
		$result = $this->metric('fields');

		return $result;
	}

	/**
	 * @return array<int,array{hasUrls:bool,fields:int[]}>
	 */
	public function getVolumes(): array
	{
		/** @var array<int,array{hasUrls:bool,fields:int[]}> $result */
		$result = $this->metric('volumes');

		return $result;
	}

	/**
	 * Users of the groups by their ID.
	 *
	 * @return array<int,int>
	 */
	public function getUserGroups(): array
	{
		return Fill::counts($this->metric('userGroups'));
	}

	public function getUsers(): UserStatistics
	{
		$stats = new UserStatistics();

		$users = $this->metric('users');
		$stats->total = Fill::int($users['total'] ?? 0);
		$stats->active = Fill::int($users['active'] ?? 0);
		$stats->pending = Fill::int($users['pending'] ?? 0);
		$stats->suspended = Fill::int($users['suspended'] ?? 0);
		$stats->locked = Fill::int($users['locked'] ?? 0);
		$stats->inactive = Fill::int($users['inactive'] ?? 0);
		$stats->admins = Fill::int($users['admins'] ?? 0);
		$stats->recentLogin = Fill::int($users['recentLogin'] ?? 0);
		$stats->idle = Fill::int($users['idle'] ?? 0);
		$stats->twoFactor = Fill::int($users['twoFactor'] ?? 0);
		$stats->adminsWithoutTwoFactor = Fill::int($users['adminsWithoutTwoFactor'] ?? 0);
		$stats->unverifiedEmail = Fill::int($users['unverifiedEmail'] ?? 0);

		return $stats;
	}

	/**
	 * @return array<mixed>
	 */
	private function metric(string $name): array
	{
		$this->metrics ??= Fill::map(($this->parts)('metrics'));

		return Fill::list($this->metrics[$name] ?? []);
	}
}
