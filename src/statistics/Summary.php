<?php

namespace SomehowDigital\Craft\Hub\statistics;

use Closure;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\StringHelper;
use craft\models\Volume;
use DateTime;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\SnapshotService;
use SomehowDigital\Craft\Hub\services\StatisticsService;

/**
 * Summary cards of a statistics tab, which break down what a list or a page holds (see the statistics component).
 * Each card is lazy (see lazyCard()): it's rendered with only its label and icon until its panel is opened, which loads
 * its data, and charted by its history in the snapshots taken, see SnapshotService::chart(). The cards shared by the
 * tabs are built here, those of a single kind by its summary.
 */
abstract class Summary
{
	/**
	 * CP URL to a Hub page that keeps the selected sites.
	 *
	 * @param array<string,mixed> $params
	 */
	protected function hubUrl(string $path, array $params = []): string
	{
		return Hub::getInstance()->pageUrl($path, $params);
	}

	/**
	 * Card of a statistics tab whose panel loads its data once opened, rendered with only its label and icon until then,
	 * so a tab only queries the statistics of the cards opened (see renderCard()).
	 *
	 * @param Closure():array<string,mixed> $load the card's data, see the statistics component
	 * @param (Closure(Snapshot):array<string,mixed>)|null $history the card in a snapshot, its total and possibly its segments, for
	 * the chart of the card, see SnapshotService::chart()
	 * @return array{key:string,label:string,icon:string,load:Closure,history?:Closure}
	 */
	protected function lazyCard(string $label, string $icon, Closure $load, ?Closure $history = null): array
	{
		return [
			'key' => StringHelper::toKebabCase($label),
			'label' => $label,
			'icon' => $icon,
			'load' => $load,
			...($history !== null ? ['history' => $history] : []),
		];
	}

	/**
	 * Summary card for entries, split by status like Craft's.
	 *
	 * @param string|null $path entry list page path, to link the statuses to the list filtered by them
	 * @return array<string,mixed>
	 */
	protected function entriesCard(EntryStatistics $stats, ?string $note = null, ?string $path = null): array
	{
		$url = fn (array $params) => $path !== null ? $this->hubUrl($path, $params) : null;

		return [
			'label' => Craft::t('hub', 'Entries'),
			'icon' => 'file-lines',
			'url' => null,
			'total' => $stats->total,
			'segments' => [
				['label' => Craft::t('app', 'Live'), 'value' => $stats->getLive(), 'color' => 'green', 'url' => $url(['status' => Entry::STATUS_LIVE])],
				['label' => Craft::t('app', 'Pending'), 'value' => $stats->pending, 'color' => 'orange', 'url' => $url(['status' => Entry::STATUS_PENDING])],
				['label' => Craft::t('app', 'Expired'), 'value' => $stats->expired, 'color' => 'red', 'url' => $url(['status' => Entry::STATUS_EXPIRED])],
				['label' => Craft::t('app', 'Disabled'), 'value' => $stats->getDisabled(), 'color' => 'gray', 'url' => $url(['status' => Entry::STATUS_DISABLED])],
				// Drafts are not part of the total, so they are listed but kept out of the bar.
				['label' => Craft::t('hub', 'Drafts'), 'value' => $stats->drafts, 'color' => 'amber', 'bar' => false, 'url' => $url(['drafts' => 1])],
			],
			'note' => $note,
			'details' => [
				['label' => Craft::t('hub', 'Last edited'), 'value' => $stats->lastUpdated, 'type' => 'date'],
			],
		];
	}

	/**
	 * Summary card for assets, split by whether they are referenced.
	 *
	 * @return array<string,mixed>
	 */
	protected function assetsCard(AssetStatistics $stats, ?string $note = null): array
	{
		return [
			'label' => Craft::t('hub', 'Assets'),
			'icon' => 'image',
			'url' => null,
			'total' => $stats->total,
			'segments' => [
				['label' => Craft::t('hub', 'With references'), 'value' => $stats->used, 'color' => 'green'],
				['label' => Craft::t('hub', 'Without references'), 'value' => $stats->getUnused(), 'color' => 'orange'],
			],
			'note' => $note,
			'details' => [
				['label' => Craft::t('hub', 'Total size'), 'value' => $stats->size, 'type' => 'size'],
				['label' => Craft::t('hub', 'Size without references'), 'value' => $stats->unusedSize, 'type' => 'size'],
				['label' => Craft::t('hub', 'Average size'), 'value' => $stats->getAverageSize(), 'type' => 'size'],
				['label' => Craft::t('hub', 'Images'), 'value' => $stats->images],
				['label' => Craft::t('hub', 'Last uploaded'), 'value' => $stats->lastUploaded, 'type' => 'date'],
			],
		];
	}

	/**
	 * Summary card for images, split by whether they have alternative text, with a table of the volumes holding images.
	 *
	 * @param array<string,AssetStatistics> $volumes stats of the volumes by their name
	 * @return array<string,mixed>
	 */
	protected function altCard(AssetStatistics $stats, array $volumes = []): array
	{
		$card = [
			'label' => Craft::t('hub', 'Alternative Text'),
			'icon' => 'universal-access',
			'url' => null,
			'total' => $stats->images,
			'segments' => [
				['label' => Craft::t('hub', 'With alternative text'), 'value' => $stats->images - $stats->missingAlt, 'color' => 'green'],
				['label' => Craft::t('hub', 'Missing'), 'value' => $stats->missingAlt, 'color' => 'orange'],
			],
			'note' => Craft::t('hub', 'Images only'),
		];

		$volumes = array_filter($volumes, fn (AssetStatistics $volume) => $volume->images > 0);

		if ($volumes !== []) {
			$card['table'] = $this->table(Craft::t('hub', 'Volume'), Craft::t('hub', 'Images'), [
				['label' => Craft::t('hub', 'Missing'), 'type' => 'number'],
				['label' => Craft::t('hub', 'Coverage'), 'type' => 'percent'],
			], array_map(fn (AssetStatistics $volume, string $name) => [
				'label' => $name,
				'color' => $volume->missingAlt > 0 ? 'orange' : 'green',
				'value' => $volume->images,
				'cells' => [$volume->missingAlt, ($volume->images - $volume->missingAlt) / $volume->images],
			], $volumes, array_keys($volumes)));
		}

		return $card;
	}

	/**
	 * Summary card splitting items by how recently their entries were updated.
	 *
	 * @param array<int,EntryStatistics> $stats keyed like the list it summarizes
	 * @param string $path list page path for filter links
	 * @param int $months months without edits after which the items are stale
	 * @param DateTime|null $at the day the items are stale by, like that of a snapshot; today by default
	 * @return array<string,mixed>
	 */
	protected function activityCard(array $stats, string $path, int $months, ?DateTime $at = null): array
	{
		$counts = [EntryStatistics::ACTIVITY_RECENT => 0, EntryStatistics::ACTIVITY_STALE => 0, EntryStatistics::ACTIVITY_NONE => 0];

		foreach ($stats as $item) {
			$counts[$item->getActivity($months, $at)]++;
		}

		return [
			'label' => Craft::t('hub', 'Activity'),
			'icon' => 'clock',
			'url' => null,
			'total' => count($stats),
			'segments' => [
				['label' => Craft::t('hub', 'Recently edited'), 'value' => $counts[EntryStatistics::ACTIVITY_RECENT], 'color' => 'green', 'url' => $this->hubUrl($path, ['activity' => EntryStatistics::ACTIVITY_RECENT])],
				['label' => Craft::t('hub', 'Stale'), 'value' => $counts[EntryStatistics::ACTIVITY_STALE], 'color' => 'orange', 'url' => $this->hubUrl($path, ['activity' => EntryStatistics::ACTIVITY_STALE])],
				['label' => Craft::t('hub', 'No entries'), 'value' => $counts[EntryStatistics::ACTIVITY_NONE], 'color' => 'gray', 'url' => $this->hubUrl($path, ['activity' => EntryStatistics::ACTIVITY_NONE])],
			],
			'note' => Craft::t('hub', 'Stale: not edited for {months, plural, =1{one month} other{# months}}', ['months' => $months]),
		];
	}

	/**
	 * Summary card showing the largest groups of a list, with the rest combined, and a table of all of them.
	 *
	 * @param array<string,int> $counts group label => count
	 * @param string|null $filter query parameter that filters the list by a group's lowercased label
	 * @param string|null $path list page path for filter links, needed along with the filter
	 * @param array<string,string> $filterValues group label => filter value, for groups not filtered by their lowercased label
	 * @param bool $sum whether the total sums the counts, for cards naming what is counted, rather than counting the groups
	 * @return array<string,mixed>
	 */
	protected function topCard(string $label, string $icon, array $counts, ?string $filter = null, int $limit = 4, ?string $path = null, array $filterValues = [], bool $sum = false): array
	{
		arsort($counts);
		$top = array_slice($counts, 0, $limit, true);
		$colors = ['blue', 'violet', 'teal', 'amber'];
		$segments = [];
		$rows = [];

		foreach (array_keys($counts) as $i => $group) {
			$row = [
				'label' => (string)$group,
				'value' => $counts[$group],
				// The rest is gray in the bar, so its groups are in the table.
				'color' => $i < $limit ? $colors[$i % count($colors)] : 'gray',
				'url' => $filter !== null && $path !== null ? $this->hubUrl($path, [$filter => $filterValues[$group] ?? mb_strtolower((string)$group)]) : null,
			];

			if ($i < $limit) {
				$segments[] = $row;
			}

			$rows[] = [...$row, 'cells' => []];
		}

		if (count($counts) > $limit) {
			$segments[] = [
				'label' => Craft::t('hub', '{count, number} more', ['count' => count($counts) - $limit]),
				'value' => array_sum($counts) - array_sum($top),
				'color' => 'gray',
				// Listed after the groups, however large it is.
				'last' => true,
			];
		}

		return [
			'label' => $label,
			'icon' => $icon,
			'url' => null,
			'total' => $sum ? array_sum($counts) : count($counts),
			'segments' => $segments,
			'table' => $rows !== [] ? $this->table($label, Craft::t('hub', 'Count'), [], $rows) : null,
		];
	}

	/**
	 * Top card of the people who wrote entries or uploaded files, with how many, linking to the list filtered by them.
	 *
	 * @param array<int,array{user:User,count:int}> $people keyed by user ID, see EntryService::getAuthors()
	 * @param string $filter query parameter filtering the list by a user's ID
	 * @param string|null $path list page path for filter links
	 * @return array<string,mixed>
	 */
	protected function peopleCard(string $label, string $icon, array $people, string $filter, ?string $path, string $unit): array
	{
		$counts = [];
		$ids = [];

		foreach ($people as $id => $person) {
			$name = (string)$person['user']->getName();

			// People sharing a name are told apart by their username.
			if (isset($counts[$name])) {
				$name = sprintf('%s (%s)', $name, $person['user']->username);
			}

			$counts[$name] = $person['count'];
			$ids[$name] = (string)$id;
		}

		return $this->withColumns(
			$this->topCard($label, $icon, $counts, $path !== null ? $filter : null, path: $path, filterValues: $ids),
			$unit,
			[],
			[],
		);
	}

	/**
	 * Table of a summary card, its rows breaking down what the card counts.
	 * Rows with a value are sorted by it, largest first, and get a share of their sum.
	 *
	 * @param string $heading header of the column naming the rows
	 * @param string|null $unit header of the column of the rows' values, which they have only along with it
	 * @param array<int,array{label:string,type:string}> $columns further columns, `type` being one of `number`, `date`, `size`,
	 * `percent` (a fraction) or `text`
	 * @param array<int,array{label:string,url?:?string,color?:string,value?:int,cells:array<int,mixed>}> $rows
	 * @return array<string,mixed>
	 */
	protected function table(string $heading, ?string $unit, array $columns, array $rows): array
	{
		$rows = array_values($rows);

		if ($unit !== null) {
			usort($rows, fn (array $a, array $b) => $b['value'] <=> $a['value']);
		}

		return [
			'heading' => $heading,
			'unit' => $unit,
			'columns' => $columns,
			'rows' => $rows,
		];
	}

	/**
	 * Adds columns to the table of a top card, with the cells of its rows by their label.
	 *
	 * @param array<string,mixed> $card
	 * @param string $unit header of the column of the counts
	 * @param array<int,array{label:string,type:string}> $columns see table()
	 * @param array<string,array<int,mixed>> $cells group label => cells
	 * @return array<string,mixed>
	 */
	protected function withColumns(array $card, string $unit, array $columns, array $cells): array
	{
		if ($card['table'] === null) {
			return $card;
		}

		$card['table']['unit'] = $unit;
		$card['table']['columns'] = $columns;

		foreach ($card['table']['rows'] as &$row) {
			$row['cells'] = $cells[$row['label']] ?? array_fill(0, count($columns), null);
		}

		return $card;
	}

	/**
	 * Table of the items whose entries were edited least recently, for an activity card; items without entries are left out.
	 *
	 * @param string $heading see table()
	 * @param array<int,array{label:string,url:?string,stats:EntryStatistics}> $items
	 * @param int $months months without edits after which the items are stale
	 * @return array<string,mixed>|null
	 */
	protected function leastEditedTable(string $heading, array $items, int $months, int $limit = 10): ?array
	{
		$items = array_filter($items, fn (array $item) => $item['stats']->lastUpdated !== null);

		if ($items === []) {
			return null;
		}

		usort($items, fn (array $a, array $b) => $a['stats']->lastUpdated <=> $b['stats']->lastUpdated);

		return [
			...$this->table($heading, null, [
				['label' => Craft::t('hub', 'Entries'), 'type' => 'number'],
				['label' => Craft::t('hub', 'Last edited'), 'type' => 'date'],
			], array_map(fn (array $item) => [
				'label' => $item['label'],
				'url' => $item['url'],
				'color' => $item['stats']->getActivity($months) === EntryStatistics::ACTIVITY_STALE ? 'orange' : 'green',
				'cells' => [$item['stats']->total, $item['stats']->lastUpdated],
			], array_slice($items, 0, $limit))),
			'caption' => Craft::t('hub', 'Least recently edited'),
		];
	}

	/**
	 * Columns of a table breaking down the entries of its rows, see entryCells().
	 *
	 * @return array<int,array{label:string,type:string}>
	 */
	protected function entryColumns(): array
	{
		return [
			['label' => Craft::t('hub', 'Enabled'), 'type' => 'number'],
			['label' => Craft::t('hub', 'Drafts'), 'type' => 'number'],
			['label' => Craft::t('hub', 'Last edited'), 'type' => 'date'],
		];
	}

	/**
	 * @param array<array-key,EntryStatistics> $stats
	 * @return array<array-key,array<int,mixed>>
	 */
	protected function entryCells(array $stats): array
	{
		return array_map(fn (EntryStatistics $item) => [$item->enabled, $item->drafts, $item->lastUpdated], $stats);
	}

	/**
	 * Columns of a table breaking down the assets of its rows, see assetCells().
	 *
	 * @return array<int,array{label:string,type:string}>
	 */
	protected function assetColumns(): array
	{
		return [
			['label' => Craft::t('hub', 'Size'), 'type' => 'size'],
			['label' => Craft::t('hub', 'Without references'), 'type' => 'number'],
			['label' => Craft::t('hub', 'Last uploaded'), 'type' => 'date'],
		];
	}

	/**
	 * @param array<array-key,AssetStatistics> $stats
	 * @return array<array-key,array<int,mixed>>
	 */
	protected function assetCells(array $stats): array
	{
		return array_map(fn (AssetStatistics $item) => [$item->size, $item->getUnused(), $item->lastUploaded], $stats);
	}

	/**
	 * The assets of all volumes, on the sites in scope.
	 */
	protected function assetTotals(): AssetStatistics
	{
		$totals = new AssetStatistics();

		foreach (Hub::getInstance()->volumes->getVolumes() as $info) {
			$totals->add($info->stats);
		}

		return $totals;
	}

	/**
	 * Types card of the assets, by their file kind, linking to the list on the page filtered by them.
	 *
	 * @param string $path list page path for filter links
	 * @return array{key:string,label:string,icon:string,load:Closure,history?:Closure}
	 */
	protected function fileKindsCard(string $path): array
	{
		return $this->lazyCard(Craft::t('hub', 'Types'), 'file', function () use ($path) {
			[$kinds, $values, $stats] = $this->fileKindCounts(Hub::getInstance()->volumes->getFileKinds());

			return $this->withColumns(
				$this->topCard(Craft::t('hub', 'Types'), 'file', $kinds, 'kind', path: $path, filterValues: $values),
				Craft::t('hub', 'Assets'),
				$this->assetColumns(),
				$this->assetCells($stats),
			);
		}, $this->fileKindsHistory());
	}

	/**
	 * Stats of volumes by their name.
	 *
	 * @param VolumeInfo[] $volumes
	 * @return array<string,AssetStatistics>
	 */
	protected function volumeStats(array $volumes): array
	{
		$stats = [];

		foreach ($volumes as $info) {
			$stats[Craft::t('site', $info->volume->name)] = $info->stats;
		}

		return $stats;
	}

	/**
	 * Assets of each file kind, their filter values and stats, by the kind's label.
	 *
	 * @param FileKindInfo[] $fileKinds
	 * @return array{array<string,int>,array<string,string>,array<string,AssetStatistics>}
	 */
	private function fileKindCounts(array $fileKinds): array
	{
		$counts = [];
		$values = [];
		$stats = [];

		foreach ($fileKinds as $info) {
			$counts[$info->getLabel()] = $info->stats->total;
			$values[$info->getLabel()] = $info->kind;
			$stats[$info->getLabel()] = $info->stats;
		}

		return [$counts, $values, $stats];
	}

	/**
	 * Entries card of the entries summed by the criteria in a snapshot, on the sites in scope, see lazyCard(); only section
	 * entries or nested entries when asked for, see StatisticsService::getEntryRows().
	 *
	 * @return Closure(Snapshot):array<string,mixed>
	 */
	protected function entriesHistory(?int $sectionId = null, ?int $typeId = null, ?int $fieldId = null, ?bool $nested = null): Closure
	{
		$siteIds = Hub::getInstance()->getSiteIds();

		if ($nested !== null) {
			return fn (Snapshot $snapshot) => $this->entriesCard(array_reduce(
				$snapshot->statistics->getEntryRows($siteIds, $nested),
				fn (EntryStatistics $sum, array $row) => $sum->add($row['stats']),
				new EntryStatistics(),
			));
		}

		return fn (Snapshot $snapshot) => $this->entriesCard($snapshot->statistics->sumEntries($siteIds, $sectionId, $typeId, $fieldId));
	}

	/**
	 * A card of the assets of a volume, or of all, in a snapshot, on the sites in scope, see lazyCard(); the assets card
	 * unless another one is given.
	 *
	 * @param (Closure(AssetStatistics):array<string,mixed>)|null $card
	 * @return Closure(Snapshot):array<string,mixed>
	 */
	protected function assetsHistory(?int $volumeId = null, ?Closure $card = null): Closure
	{
		$siteIds = Hub::getInstance()->getSiteIds();
		$card ??= fn (AssetStatistics $stats) => $this->assetsCard($stats);

		return fn (Snapshot $snapshot) => $card($snapshot->statistics->sumAssets($siteIds, $volumeId));
	}

	/**
	 * A top card in a snapshot (see topCard()): its total and a segment for each of its groups, so whichever are the largest
	 * now are matched.
	 *
	 * @param array<string,int> $counts group label => count
	 * @param bool $sum see topCard()
	 * @return array{total:int,segments:array<int,array{label:string,value:int}>}
	 */
	protected function topHistory(array $counts, bool $sum = false): array
	{
		return [
			'total' => $sum ? array_sum($counts) : count($counts),
			'segments' => array_map(fn (string|int $label, int $value) => ['label' => (string)$label, 'value' => $value], array_keys($counts), $counts),
		];
	}

	/**
	 * A top card in a snapshot (see topHistory()) of the counts of items by their ID, labeled by their current labels; items
	 * deleted since are labeled by their ID, so they're counted, but matched to none of the current segments.
	 *
	 * @param array<int,string> $labels current labels by ID
	 * @param array<int,int> $counts counts by ID
	 * @param bool $sum see topCard()
	 * @return array{total:int,segments:array<int,array{label:string,value:int}>}
	 */
	protected function labeledTopHistory(array $labels, array $counts, bool $sum = false): array
	{
		$labeled = [];

		foreach ($counts as $id => $count) {
			$label = $labels[$id] ?? "#$id";
			$labeled[$label] = ($labeled[$label] ?? 0) + $count;
		}

		return $this->topHistory($labeled, $sum);
	}

	/**
	 * A value for each of the IDs, keyed by them.
	 *
	 * @template T
	 * @param int[] $ids
	 * @param Closure(int):T $value
	 * @return array<int,T>
	 */
	protected function byId(array $ids, Closure $value): array
	{
		return array_combine($ids, array_map($value, $ids));
	}

	/**
	 * Types card of the assets of a volume, or of all, in a snapshot, on the sites in scope, see lazyCard().
	 *
	 * @return Closure(Snapshot):array<string,mixed>
	 */
	protected function fileKindsHistory(?int $volumeId = null): Closure
	{
		$siteIds = Hub::getInstance()->getSiteIds();

		return function (Snapshot $snapshot) use ($siteIds, $volumeId) {
			$counts = [];
			foreach ($snapshot->statistics->getAssetRows($siteIds) as $row) {
				if ($volumeId === null || $row['volumeId'] === $volumeId) {
					$label = (new FileKindInfo($row['kind']))->getLabel();
					$counts[$label] = ($counts[$label] ?? 0) + $row['stats']->total;
				}
			}

			return $this->topHistory($counts);
		};
	}

	/**
	 * Current labels of the sections by their ID, like the cards label them.
	 *
	 * @return array<int,string>
	 */
	protected function sectionLabels(): array
	{
		return array_map(fn (SectionInfo $info) => Craft::t('site', $info->section->name), Hub::getInstance()->structure->getSections());
	}

	/**
	 * @return array<int,string>
	 */
	protected function entryTypeLabels(): array
	{
		return array_map(fn (EntryTypeInfo $info) => Craft::t('site', $info->entryType->name), Hub::getInstance()->structure->getEntryTypes());
	}

	/**
	 * @return array<int,string>
	 */
	protected function volumeLabels(): array
	{
		return array_map(fn (VolumeInfo $info) => Craft::t('site', $info->volume->name), Hub::getInstance()->volumes->getVolumes());
	}

	/**
	 * Current names of the fields by their ID, like the reference cards label them.
	 *
	 * @return array<int,string>
	 */
	protected function fieldLabels(): array
	{
		return array_map(fn (FieldInfo $info) => (string)$info->field->name, Hub::getInstance()->structure->getFields());
	}
}
