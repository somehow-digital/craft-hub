<?php

namespace SomehowDigital\Craft\Hub\statistics;

use Craft;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\Snapshot;

/**
 * Statistics of the entries list: its summary cards, linking to its filters.
 */
final class EntriesSummary extends Summary
{
	/**
	 * Summary cards for the entries list, linking to its filters.
	 *
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function index(): array
	{
		$hub = Hub::getInstance();
		$path = 'hub/data/entries';
		$siteIds = $hub->getSiteIds();
		$months = $hub->filters->getThreshold(CheckKind::Entries, 'stale');

		// Like the list, the entries of both sections and nested entry fields are counted.
		$sumEntries = fn () => $hub->statistics->sumEntries($siteIds);

		return [
			$this->lazyCard(Craft::t('hub', 'Entries'), 'file-lines', function () use ($hub, $siteIds, $sumEntries, $path) {
				$stats = $sumEntries();
				$nested = 0;
				foreach ($hub->statistics->getEntryRows($siteIds) as $row) {
					$nested += $row['sectionId'] === null ? $row['stats']->total : 0;
				}

				$card = $this->entriesCard($stats, path: $path);
				$card['details'] = [
					['label' => Craft::t('hub', 'In sections'), 'value' => $stats->total - $nested],
					['label' => Craft::t('hub', 'In nested entry fields'), 'value' => $nested],
					...$card['details'],
				];

				return $card;
			}, $this->entriesHistory()),
			$this->lazyCard(Craft::t('hub', 'Sections'), 'layer-group', function () use ($hub, $path) {
				$counts = [];
				$handles = [];
				$stats = [];
				foreach ($hub->structure->getSections() as $info) {
					$name = Craft::t('site', $info->section->name);
					$counts[$name] = $info->stats->total;
					$handles[$name] = $info->section->handle;
					$stats[$name] = $info->stats;
				}

				return $this->withColumns(
					$this->topCard(Craft::t('hub', 'Sections'), 'layer-group', $counts, 'section', path: $path, filterValues: $handles),
					Craft::t('hub', 'Entries'),
					$this->entryColumns(),
					$this->entryCells($stats),
				);
			}, fn (Snapshot $snapshot) => $this->labeledTopHistory($this->sectionLabels(), $this->byId(
				array_keys($snapshot->getSections()),
				fn (int $id) => $snapshot->statistics->sumEntries($siteIds, $id)->total,
			))),
			$this->lazyCard(Craft::t('hub', 'Entry Types'), 'shapes', function () use ($hub, $siteIds, $path) {
				$totals = [];
				foreach ($hub->statistics->getEntryRows($siteIds) as $row) {
					$totals[$row['typeId']] = ($totals[$row['typeId']] ?? new EntryStatistics())->add($row['stats']);
				}

				$counts = [];
				$handles = [];
				$stats = [];
				foreach ($hub->structure->getEntryTypes() as $info) {
					if (($totals[$info->entryType->id] ?? null)?->total > 0) {
						$name = Craft::t('site', $info->entryType->name);
						$counts[$name] = $totals[$info->entryType->id]->total;
						$handles[$name] = $info->entryType->handle;
						$stats[$name] = $totals[$info->entryType->id];
					}
				}

				return $this->withColumns(
					$this->topCard(Craft::t('hub', 'Entry Types'), 'shapes', $counts, 'type', path: $path, filterValues: $handles),
					Craft::t('hub', 'Entries'),
					$this->entryColumns(),
					$this->entryCells($stats),
				);
			}, function (Snapshot $snapshot) use ($siteIds) {
				$totals = [];
				foreach ($snapshot->statistics->getEntryRows($siteIds) as $row) {
					$totals[$row['typeId']] = ($totals[$row['typeId']] ?? 0) + $row['stats']->total;
				}

				return $this->labeledTopHistory($this->entryTypeLabels(), array_filter($totals));
			}),
			$this->lazyCard(Craft::t('hub', 'Authors'), 'user', fn () => $this->peopleCard(Craft::t('hub', 'Authors'), 'user', $hub->entries->getAuthors(), 'author', $path, Craft::t('hub', 'Entries'))),
			$this->lazyCard(Craft::t('hub', 'Activity'), 'clock', function () use ($hub, $sumEntries, $path, $months) {
				$stats = $sumEntries();
				$stale = $hub->review->countStale($months);
				$items = array_map(fn (SectionInfo $info) => [
					'label' => Craft::t('site', $info->section->name),
					'url' => $this->hubUrl($path, ['section' => $info->section->handle]),
					'stats' => $info->stats,
				], $hub->structure->getSections());

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
					'table' => $this->leastEditedTable(Craft::t('hub', 'Section'), $items, $months),
				];
			}, $this->entriesHistory()),
		];
	}
}
