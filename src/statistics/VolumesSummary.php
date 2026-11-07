<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\statistics;

use Craft;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * Statistics of the volumes: the summary cards of their list and those of a volume's page.
 */
final class VolumesSummary extends Summary
{
	/**
	 * Summary cards for the volumes list.
	 *
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function index(): array
	{
		$path = 'hub/structure/volumes';
		$siteIds = Hub::getInstance()->siteScope->getSiteIds();
		$volumes = fn() => Hub::getInstance()->volumes->getVolumes();

		return [
			$this->lazyCard(Craft::t('app', 'Volumes'), 'folder-open', function () use ($volumes, $path): array {
				$card = $this->volumesCard(
					count($volumes()),
					count(array_filter($volumes(), fn(VolumeInfo $info): bool => $info->isEmpty())),
					count(array_filter($volumes(), fn(VolumeInfo $info): bool => $info->hasUrls)),
				);
				$card['table'] = $this->table(Craft::t('app', 'Volume'), Craft::t('app', 'Assets'), [
					...$this->assetColumns(),
					['label' => Craft::t('hub', 'References'), 'type' => 'number'],
				], array_map(fn(VolumeInfo $info): array => [
					'label' => Craft::t('site', $info->volume->name),
					'url' => $this->hubUrl("{$path}/{$info->volume->id}"),
					'color' => 'amber',
					'value' => $info->stats->total,
					'cells' => [...$this->assetCells([$info->stats])[0], $info->references->references],
				], $volumes()));

				return $card;
			}, fn(Snapshot $snapshot): array => $this->volumesCard(
				count($snapshot->getVolumes()),
				count(array_filter(array_keys($snapshot->getVolumes()), fn(int $id): bool => $snapshot->statistics->sumAssets($siteIds, $id)->total === 0)),
				count(array_filter($snapshot->getVolumes(), fn(array $volume): bool => (bool) $volume['hasUrls'])),
			)),
			$this->lazyCard(Craft::t('app', 'Assets'), 'image', fn(): array => $this->assetsCard($this->assetTotals(), Craft::t('hub', '{references, number} references from Assets and Link fields', [
				'references' => array_sum(array_map(fn(VolumeInfo $info): int => $info->references->references, $volumes())),
			])), $this->assetsHistory()),
			$this->fileKindsCard($path),
			$this->lazyCard(Craft::t('app', 'Alternative Text'), 'universal-access', fn(): array => $this->altCard($this->assetTotals(), $this->volumeStats($volumes())), $this->assetsHistory(card: fn(AssetStatistics $stats): array => $this->altCard($stats))),
		];
	}

	/**
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function detail(VolumeInfo $info): array
	{
		$id = $info->volume->id;

		return [
			$this->lazyCard(Craft::t('app', 'Assets'), 'image', fn(): array => $this->assetsCard($info->stats), $this->assetsHistory($id)),
			$this->lazyCard(Craft::t('hub', 'Types'), 'file', function () use ($info): array {
				$fileKinds = Hub::getInstance()->volumes->getFileKinds();
				$kinds = [];
				$stats = [];
				foreach ($info->kinds as $kind => $kindStats) {
					$kinds[$fileKinds[$kind]->getLabel()] = $kindStats->total;
					$stats[$fileKinds[$kind]->getLabel()] = $kindStats;
				}

				return $this->withColumns($this->topCard(Craft::t('hub', 'Types'), 'file', $kinds), Craft::t('app', 'Assets'), $this->assetColumns(), $this->assetCells($stats));
			}, $this->fileKindsHistory($id)),
			$this->lazyCard(Craft::t('app', 'Alternative Text'), 'universal-access', fn(): array => $this->altCard($info->stats), $this->assetsHistory($id, fn(AssetStatistics $stats): array => $this->altCard($stats))),
			$this->lazyCard(Craft::t('hub', 'References'), 'link', function () use ($info, $id): array {
				$statistics = Hub::getInstance()->statistics;
				$siteIds = Hub::getInstance()->siteScope->getSiteIds();
				$counts = [];
				foreach ([...$info->assetFields, ...$info->linkFields] as $field) {
					$counts[$field->name] = $statistics->sumReferences($siteIds, $field->id, volumeId: $id)->references;
				}

				$card = $this->topCard(Craft::t('hub', 'References'), 'link', $counts, sum: true);
				$card['note'] = Craft::t('hub', '{assets, plural, =0{No Assets field} =1{One Assets field} other{# Assets fields}} and {links, plural, =0{no Link field} =1{one Link field} other{# Link fields}} can relate to assets from this volume', [
					'assets' => count($info->assetFields),
					'links' => count($info->linkFields),
				]);

				return $card;
			}, fn(Snapshot $snapshot): array => $this->labeledTopHistory($this->fieldLabels(), $this->byId(
				$snapshot->getVolumes()[$id]['fields'] ?? [],
				fn(int $fieldId): int => $snapshot->statistics->sumReferences(Hub::getInstance()->siteScope->getSiteIds(), $fieldId, volumeId: $id)->references,
			), true)),
			$this->lazyCard(Craft::t('hub', 'Uploaders'), 'user', fn(): array => $this->peopleCard(Craft::t('hub', 'Uploaders'), 'user', Hub::getInstance()->files->getUploaders($id), 'uploader', "hub/structure/volumes/{$id}/assets", Craft::t('app', 'Assets'))),
		];
	}

	/**
	 * Summary card for volumes, split by whether they hold assets.
	 *
	 * @return array<string,mixed>
	 */
	private function volumesCard(int $total, int $empty, int $withUrls): array
	{
		return [
			'label' => Craft::t('app', 'Volumes'),
			'icon' => 'folder-open',
			'url' => $this->hubUrl('hub/structure/volumes'),
			'total' => $total,
			'segments' => [
				['label' => Craft::t('hub', 'With assets'), 'value' => $total - $empty, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/structure/volumes', ['empty' => 1])],
			],
			'details' => [
				['label' => Craft::t('hub', 'With URLs'), 'value' => $withUrls],
			],
		];
	}
}
