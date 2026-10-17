<?php

namespace SomehowDigital\Craft\Hub\statistics;

use Craft;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\services\FileService;

/**
 * Statistics of the assets list: its summary cards, linking to its filters.
 */
final class FilesSummary extends Summary
{
	/**
	 * Summary cards for the files list, linking to its filters.
	 *
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function index(): array
	{
		$hub = Hub::getInstance();
		$path = 'hub/data/assets';
		$siteIds = $hub->getSiteIds();
		$volumes = fn () => $hub->volumes->getVolumes();

		return [
			$this->lazyCard(Craft::t('hub', 'Assets'), 'image', function () use ($path) {
				$card = $this->assetsCard($this->assetTotals());
				$card['segments'][0]['url'] = $this->hubUrl($path, ['usage' => FileService::USAGE_USED]);
				$card['segments'][1]['url'] = $this->hubUrl($path, ['usage' => FileService::USAGE_UNUSED]);

				return $card;
			}, $this->assetsHistory()),
			$this->lazyCard(Craft::t('hub', 'Volumes'), 'folder-open', function () use ($volumes, $path) {
				$counts = [];
				$handles = [];
				foreach ($volumes() as $info) {
					$name = Craft::t('site', $info->volume->name);
					$counts[$name] = $info->stats->total;
					$handles[$name] = $info->volume->handle;
				}

				return $this->withColumns(
					$this->topCard(Craft::t('hub', 'Volumes'), 'folder-open', $counts, 'volume', path: $path, filterValues: $handles),
					Craft::t('hub', 'Assets'),
					$this->assetColumns(),
					$this->assetCells($this->volumeStats($volumes())),
				);
			}, fn (Snapshot $snapshot) => $this->labeledTopHistory($this->volumeLabels(), $this->byId(
				array_keys($snapshot->getVolumes()),
				fn (int $id) => $snapshot->statistics->sumAssets($siteIds, $id)->total,
			))),
			$this->fileKindsCard($path),
			$this->lazyCard(Craft::t('hub', 'Alternative Text'), 'universal-access', function () use ($volumes, $path) {
				$card = $this->altCard($this->assetTotals(), $this->volumeStats($volumes()));
				$card['segments'][1]['url'] = $this->hubUrl($path, ['alt' => 1]);

				return $card;
			}, $this->assetsHistory(card: fn (AssetStatistics $stats) => $this->altCard($stats))),
			$this->lazyCard(Craft::t('hub', 'Extensions'), 'file-code', function () use ($hub, $path) {
				$extensions = $hub->files->getExtensions();

				return $this->withColumns(
					// Uppercased like they are commonly referred to, and filtered by their lowercase.
					$this->topCard(Craft::t('hub', 'Extensions'), 'file-code', array_combine(array_map('mb_strtoupper', array_keys($extensions)), $extensions), 'extension', path: $path),
					Craft::t('hub', 'Assets'),
					[],
					[],
				);
			}),
			$this->lazyCard(Craft::t('hub', 'Uploaders'), 'user', fn () => $this->peopleCard(Craft::t('hub', 'Uploaders'), 'user', $hub->files->getUploaders(), 'uploader', $path, Craft::t('hub', 'Assets'))),
		];
	}
}
