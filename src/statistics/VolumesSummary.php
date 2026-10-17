<?php

namespace SomehowDigital\Craft\Hub\statistics;

use Craft;
use craft\models\Volume;
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
		$volumes = fn () => Hub::getInstance()->volumes->getVolumes();

		return [
			$this->lazyCard(Craft::t('hub', 'Volumes'), 'folder-open', function () use ($volumes, $path) {
				$card = $this->volumesCard(
					count($volumes()),
					count(array_filter($volumes(), fn (VolumeInfo $info) => $info->isEmpty())),
					count(array_filter($volumes(), fn (VolumeInfo $info) => $info->hasUrls)),
				);
				$card['table'] = $this->table(Craft::t('hub', 'Volume'), Craft::t('hub', 'Assets'), [
					...$this->assetColumns(),
					['label' => Craft::t('hub', 'References'), 'type' => 'number'],
				], array_map(fn (VolumeInfo $info) => [
					'label' => Craft::t('site', $info->volume->name),
					'url' => $this->hubUrl("$path/{$info->volume->id}"),
					'color' => 'amber',
					'value' => $info->stats->total,
					'cells' => [...$this->assetCells([$info->stats])[0], $info->references->references],
				], $volumes()));

				return $card;
			}, fn (Snapshot $snapshot) => $this->volumesCard(
				count($snapshot->getVolumes()),
				count(array_filter(array_keys($snapshot->getVolumes()), fn (int $id) => $snapshot->statistics->sumAssets($siteIds, $id)->total === 0)),
				count(array_filter($snapshot->getVolumes(), fn (array $volume) => (bool)$volume['hasUrls'])),
			)),
			$this->lazyCard(Craft::t('hub', 'Assets'), 'image', fn () => $this->assetsCard($this->assetTotals(), Craft::t('hub', '{references, number} references from Assets and Link fields', [
				'references' => array_sum(array_map(fn (VolumeInfo $info) => $info->references->references, $volumes())),
			])), $this->assetsHistory()),
			$this->fileKindsCard($path),
			$this->lazyCard(Craft::t('hub', 'Alternative Text'), 'universal-access', fn () => $this->altCard($this->assetTotals(), $this->volumeStats($volumes())), $this->assetsHistory(card: fn (AssetStatistics $stats) => $this->altCard($stats))),
		];
	}

	/**
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function detail(VolumeInfo $info): array
	{
		$id = $info->volume->id;

		return [
			$this->lazyCard(Craft::t('hub', 'Assets'), 'image', fn () => $this->assetsCard($info->stats), $this->assetsHistory($id)),
			$this->lazyCard(Craft::t('hub', 'Types'), 'file', function () use ($info) {
				$fileKinds = Hub::getInstance()->volumes->getFileKinds();
				$kinds = [];
				$stats = [];
				foreach ($info->kinds as $kind => $kindStats) {
					$kinds[$fileKinds[$kind]->getLabel()] = $kindStats->total;
					$stats[$fileKinds[$kind]->getLabel()] = $kindStats;
				}

				return $this->withColumns($this->topCard(Craft::t('hub', 'Types'), 'file', $kinds), Craft::t('hub', 'Assets'), $this->assetColumns(), $this->assetCells($stats));
			}, $this->fileKindsHistory($id)),
			$this->lazyCard(Craft::t('hub', 'Alternative Text'), 'universal-access', fn () => $this->altCard($info->stats), $this->assetsHistory($id, fn (AssetStatistics $stats) => $this->altCard($stats))),
			$this->lazyCard(Craft::t('hub', 'References'), 'link', function () use ($info, $id) {
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
			}, fn (Snapshot $snapshot) => $this->labeledTopHistory($this->fieldLabels(), $this->byId(
				$snapshot->getVolumes()[$id]['fields'] ?? [],
				fn (int $fieldId) => $snapshot->statistics->sumReferences(Hub::getInstance()->siteScope->getSiteIds(), $fieldId, volumeId: $id)->references,
			), true)),
			$this->lazyCard(Craft::t('hub', 'Uploaders'), 'user', fn () => $this->peopleCard(Craft::t('hub', 'Uploaders'), 'user', Hub::getInstance()->files->getUploaders($id), 'uploader', "hub/structure/volumes/$id/assets", Craft::t('hub', 'Assets'))),
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
			'label' => Craft::t('hub', 'Volumes'),
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
