<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\statistics;

use Craft;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\SiteScopeService;
use SomehowDigital\Craft\Hub\services\SnapshotService;
use SomehowDigital\Craft\Hub\services\StatisticsService;
use SomehowDigital\Craft\Hub\services\StructureService;
use SomehowDigital\Craft\Hub\services\VolumeService;

/**
 * Statistics of the assets list: its summary cards, linking to its filters.
 *
 * @internal
 */
final class FilesSummary extends BaseSummary
{
	public function __construct(
		SiteScopeService $siteScope,
		SnapshotService $snapshots,
		StructureService $structure,
		VolumeService $volumes,
		StatisticsService $statistics,
		private readonly FileService $files,
	) {
		parent::__construct($siteScope, $snapshots, $structure, $volumes, $statistics);
	}

	/**
	 * Summary cards for the files list, linking to its filters.
	 *
	 * @return list<array<string,mixed>> see lazyCard()
	 */
	public function index(): array
	{
		$path = 'hub/data/assets';
		$siteIds = $this->siteScope->getSiteIds();
		$volumes = fn(): array => $this->volumes->getVolumes();

		return [
			$this->lazyCard(Craft::t('app', 'Assets'), 'image', function () use ($path): array {
				$card = $this->assetsCard($this->assetTotals());
				$card = $this->withSegmentUrl($card, 0, $this->hubUrl($path, ['usage' => FileService::USAGE_USED]));

				return $this->withSegmentUrl($card, 1, $this->hubUrl($path, ['usage' => FileService::USAGE_UNUSED]));
			}, $this->assetsHistory()),
			$this->lazyCard(Craft::t('app', 'Volumes'), 'folder-open', function () use ($volumes, $path): array {
				$counts = [];
				$handles = [];
				foreach ($volumes() as $info) {
					$name = Craft::t('site', $info->volume->name ?? '');
					$counts[$name] = $info->stats->total;
					$handles[$name] = $info->volume->handle ?? '';
				}

				return $this->withColumns(
					$this->topCard(Craft::t('app', 'Volumes'), 'folder-open', $counts, 'volume', path: $path, filterValues: $handles),
					Craft::t('app', 'Assets'),
					$this->assetColumns(),
					$this->assetCells($this->volumeStats($volumes())),
				);
			}, fn(Snapshot $snapshot): array => $this->labeledTopHistory($this->volumeLabels(), $this->byId(
				array_keys($snapshot->getVolumes()),
				fn(int $id): int => $this->snapshots->getStatistics($snapshot)->sumAssets($siteIds, $id)->total,
			))),
			$this->fileKindsCard($path),
			$this->lazyCard(Craft::t('app', 'Alternative Text'), 'universal-access', function () use ($volumes, $path): array {
				$card = $this->altCard($this->assetTotals(), $this->volumeStats($volumes()));
				return $this->withSegmentUrl($card, 1, $this->hubUrl($path, ['alt' => 1]));
			}, $this->assetsHistory(card: fn(AssetStatistics $stats): array => $this->altCard($stats))),
			$this->lazyCard(Craft::t('hub', 'Extensions'), 'file-code', function () use ($path): array {
				$extensions = $this->files->getExtensions();

				return $this->withColumns(
					// Uppercased like they are commonly referred to, and filtered by their lowercase.
					$this->topCard(Craft::t('hub', 'Extensions'), 'file-code', array_combine(array_map(mb_strtoupper(...), array_keys($extensions)), $extensions), 'extension', path: $path),
					Craft::t('app', 'Assets'),
					[],
					[],
				);
			}),
			$this->lazyCard(Craft::t('hub', 'Uploaders'), 'user', fn(): array => $this->peopleCard(Craft::t('hub', 'Uploaders'), 'user', $this->files->getUploaders(), 'uploader', $path, Craft::t('app', 'Assets'))),
		];
	}
}
