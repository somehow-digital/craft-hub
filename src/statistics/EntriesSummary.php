<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\statistics;

use Craft;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\FilterService;
use SomehowDigital\Craft\Hub\services\ReviewService;
use SomehowDigital\Craft\Hub\services\SiteScopeService;
use SomehowDigital\Craft\Hub\services\SnapshotService;
use SomehowDigital\Craft\Hub\services\StatisticsService;
use SomehowDigital\Craft\Hub\services\StructureService;
use SomehowDigital\Craft\Hub\services\VolumeService;

/**
 * Statistics of the entries list: its summary cards, linking to its filters.
 *
 * @internal
 */
final class EntriesSummary extends BaseSummary
{
	public function __construct(
		SiteScopeService $siteScope,
		SnapshotService $snapshots,
		StructureService $structure,
		VolumeService $volumes,
		StatisticsService $statistics,
		private readonly EntryService $entries,
		private readonly FilterService $filters,
		private readonly ReviewService $review,
	) {
		parent::__construct($siteScope, $snapshots, $structure, $volumes, $statistics);
	}

	/**
	 * Summary cards for the entries list, linking to its filters.
	 *
	 * @return list<array<string,mixed>> see lazyCard()
	 */
	public function index(): array
	{
		$path = 'hub/data/entries';
		$siteIds = $this->siteScope->getSiteIds();
		$months = $this->filters->getThreshold(CheckKind::Entries, 'stale');

		// Like the list, the entries of both sections and nested entry fields are counted.
		$sumEntries = fn(): EntryStatistics => $this->statistics->sumEntries($siteIds);

		return [
			$this->lazyCard(Craft::t('app', 'Entries'), 'file-lines', function () use ($siteIds, $sumEntries, $path): array {
				$stats = $sumEntries();
				$nested = 0;
				foreach ($this->statistics->getEntryRows($siteIds) as $row) {
					$nested += $row['sectionId'] === null ? $row['stats']->total : 0;
				}

				$card = $this->entriesCard($stats, path: $path);
				$card['details'] = [
					['label' => Craft::t('hub', 'In sections'), 'value' => $stats->total - $nested],
					['label' => Craft::t('hub', 'In nested entry fields'), 'value' => $nested],
					...Fill::list($card['details'] ?? []),
				];

				return $card;
			}, $this->entriesHistory()),
			$this->lazyCard(Craft::t('app', 'Sections'), 'layer-group', function () use ($path): array {
				$counts = [];
				$handles = [];
				$stats = [];
				foreach ($this->structure->getSections() as $info) {
					$name = Craft::t('site', $info->section->name ?? '');
					$counts[$name] = $info->stats->total;
					$handles[$name] = $info->section->handle ?? '';
					$stats[$name] = $info->stats;
				}

				return $this->withColumns(
					$this->topCard(Craft::t('app', 'Sections'), 'layer-group', $counts, 'section', path: $path, filterValues: $handles),
					Craft::t('app', 'Entries'),
					$this->entryColumns(),
					$this->entryCells($stats),
				);
			}, fn(Snapshot $snapshot): array => $this->labeledTopHistory($this->sectionLabels(), $this->byId(
				array_keys($snapshot->getSections()),
				fn(int $id): int => $this->snapshots->getStatistics($snapshot)->sumEntries($siteIds, $id)->total,
			))),
			$this->lazyCard(Craft::t('hub', 'Entry Types'), 'shapes', function () use ($siteIds, $path): array {
				$totals = [];
				foreach ($this->statistics->getEntryRows($siteIds) as $row) {
					$totals[$row['typeId']] = ($totals[$row['typeId']] ?? new EntryStatistics())->add($row['stats']);
				}

				$counts = [];
				$handles = [];
				$stats = [];
				foreach ($this->structure->getEntryTypes() as $info) {
					if (($totals[(int) $info->entryType->id]->total ?? 0) > 0) {
						$name = Craft::t('site', $info->entryType->name ?? '');
						$counts[$name] = $totals[(int) $info->entryType->id]->total;
						$handles[$name] = $info->entryType->handle ?? '';
						$stats[$name] = $totals[(int) $info->entryType->id];
					}
				}

				return $this->withColumns(
					$this->topCard(Craft::t('hub', 'Entry Types'), 'shapes', $counts, 'type', path: $path, filterValues: $handles),
					Craft::t('app', 'Entries'),
					$this->entryColumns(),
					$this->entryCells($stats),
				);
			}, function (Snapshot $snapshot) use ($siteIds): array {
				$totals = [];
				foreach ($this->snapshots->getStatistics($snapshot)->getEntryRows($siteIds) as $row) {
					$totals[$row['typeId']] = ($totals[$row['typeId']] ?? 0) + $row['stats']->total;
				}

				return $this->labeledTopHistory($this->entryTypeLabels(), Fill::truthy($totals));
			}),
			$this->lazyCard(Craft::t('app', 'Authors'), 'user', fn(): array => $this->peopleCard(Craft::t('app', 'Authors'), 'user', $this->entries->getAuthors(), 'author', $path, Craft::t('app', 'Entries'))),
			$this->lazyCard(Craft::t('hub', 'Activity'), 'clock', function () use ($sumEntries, $path, $months): array {
				$stats = $sumEntries();
				$stale = $this->review->countStale($months);
				$items = array_map(fn(SectionInfo $info): array => [
					'label' => Craft::t('site', $info->section->name ?? ''),
					'url' => $this->hubUrl($path, ['section' => $info->section->handle]),
					'stats' => $info->stats,
				], $this->structure->getSections());

				return [
					'label' => Craft::t('hub', 'Activity'),
					'icon' => 'clock',
					'url' => null,
					'total' => $stats->total,
					'segments' => [
						['label' => Craft::t('hub', 'Recently edited'), 'value' => max(0, $stats->total - $stale), 'color' => 'green'],
						['label' => Craft::t('hub', 'Stale'), 'value' => $stale, 'color' => 'orange', 'url' => $this->hubUrl($path, ['stale' => 1])],
					],
					'note' => Craft::t('hub', 'Stale: not edited for {months, plural, =1{one month} other{# months}}', ['months' => $months]),
					'table' => $this->leastEditedTable(Craft::t('app', 'Section'), $items, $months),
				];
			}, $this->entriesHistory()),
		];
	}
}
