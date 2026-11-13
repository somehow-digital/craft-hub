<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use craft\fields\Link as LinkField;
use craft\fields\linktypes\Asset as AssetLinkType;
use craft\helpers\Assets as AssetsHelper;
use craft\models\Volume;
use craft\services\Fields;
use craft\services\Volumes;
use SomehowDigital\Craft\Hub\base\FileKindsProviderInterface;
use SomehowDigital\Craft\Hub\helpers\Classifier;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\ReferenceStatistics;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use yii\base\Component;

/**
 * Builds a read-only overview of volumes, the file kinds they hold and the fields relating to them.
 *
 * @internal
 */
final class VolumeService extends Component implements FileKindsProviderInterface
{
	/** @var array<string,array<int,VolumeInfo>> */
	private array $graphs = [];

	/** @var array<string,array<string,FileKindInfo>> */
	private array $fileKinds = [];

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly SiteScopeService $siteScope,
		private readonly StatisticsService $statistics,
		private readonly Volumes $craftVolumes,
		private readonly Fields $craftFields,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * @return array<int,VolumeInfo>
	 */
	public function getVolumes(): array
	{
		return $this->getGraph();
	}

	public function getVolume(int $id): ?VolumeInfo
	{
		return $this->getVolumes()[$id] ?? null;
	}

	/**
	 * Volumes an Assets or Link field can relate to assets of.
	 *
	 * @return list<VolumeInfo>
	 */
	public function getVolumesOf(FieldInterface $field): array
	{
		$holds = fn(VolumeInfo $info): bool => in_array($field->id, array_map(fn(FieldInterface $other): int|string|null => $other->id, [...$info->assetFields, ...$info->linkFields]), true);

		return array_values(array_filter($this->getVolumes(), $holds));
	}

	/**
	 * @return array<string,FileKindInfo> largest first
	 */
	public function getFileKinds(): array
	{
		$siteIds = $this->siteScope->getSiteIds();

		return $this->fileKinds[implode(',', $siteIds)] ??= $this->buildFileKinds($siteIds);
	}

	/**
	 * The file kinds there are, largest first, for what only offers or names them. Their statistics aren't set, sparing the
	 * lists of files the cost of {@see getFileKinds()}, which looks up which of all assets are used.
	 *
	 * @return array<string,FileKindInfo>
	 */
	public function getKinds(): array
	{
		$kinds = [];
		foreach ($this->statistics->getAssetKinds($this->siteScope->getSiteIds()) as $kind) {
			$kinds[$kind] = new FileKindInfo($kind);
		}

		return $kinds;
	}

	/**
	 * All the kinds files can be of, whether there are any: unlike getKinds(), this reads nothing from the database.
	 *
	 * @return array<string,FileKindInfo>
	 */
	public function getAllKinds(): array
	{
		$kinds = [];
		foreach ([...array_keys(AssetsHelper::getFileKinds()), Asset::KIND_UNKNOWN] as $kind) {
			$kind = (string) $kind;
			$kinds[$kind] = new FileKindInfo($kind);
		}

		return $kinds;
	}

	/**
	 * All volumes in the order of their names, without what's known about them.
	 *
	 * @return list<Volume>
	 */
	public function getVolumeModels(): array
	{
		$volumes = $this->craftVolumes->getAllVolumes();
		usort($volumes, fn(Volume $a, Volume $b): int => strnatcasecmp((string) $a->name, (string) $b->name));

		return $volumes;
	}

	/**
	 * @param int[] $siteIds
	 * @return array<string,FileKindInfo>
	 */
	private function buildFileKinds(array $siteIds): array
	{
		$volumes = $this->craftVolumes;
		/** @var array<string,FileKindInfo> $fileKinds */
		$fileKinds = [];

		foreach ($this->statistics->getAssetRows($siteIds) as $row) {
			$volume = $volumes->getVolumeById($row['volumeId']);

			if (!$volume instanceof Volume) {
				continue;
			}

			$kind = $fileKinds[$row['kind']] ??= new FileKindInfo($row['kind']);
			$kind->stats->add($row['stats']);
			$kind->volumes[] = $volume;
		}

		uasort($fileKinds, fn(FileKindInfo $a, FileKindInfo $b): int => $b->stats->total <=> $a->stats->total);

		return $fileKinds;
	}

	/**
	 * @return array<int,VolumeInfo>
	 */
	private function getGraph(): array
	{
		$siteIds = $this->siteScope->getSiteIds();

		return $this->graphs[implode(',', $siteIds)] ??= $this->buildGraph($siteIds);
	}

	/**
	 * What fields relate to what volumes comes from the project config; the counts are only read when they're shown.
	 *
	 * @param int[] $siteIds
	 * @return array<int,VolumeInfo>
	 */
	private function buildGraph(array $siteIds): array
	{
		$fieldsService = $this->craftFields;
		$statistics = $this->statistics;

		/** @var array<int,VolumeInfo> $volumes */
		$volumes = [];
		/** @var array<string,VolumeInfo> $volumesByUid */
		$volumesByUid = [];

		foreach ($this->craftVolumes->getAllVolumes() as $volume) {
			$id = (int) $volume->id;
			$info = new VolumeInfo($volume);
			$info->hasUrls = $volume->getFs()->hasUrls;
			$info->lazy('stats', fn(): AssetStatistics => $statistics->sumAssets($siteIds, $id));
			$info->lazy('kinds', function () use ($statistics, $siteIds, $id): array {
				$kinds = [];
				foreach ($statistics->getAssetRows($siteIds) as $row) {
					if ($row['volumeId'] === $id) {
						$kinds[$row['kind']] = ($kinds[$row['kind']] ?? new AssetStatistics())->add($row['stats']);
					}
				}

				uasort($kinds, fn(AssetStatistics $a, AssetStatistics $b): int => $b->total <=> $a->total);

				return $kinds;
			});
			$volumes[(int) $volume->id] = $info;
			$volumesByUid[(string) $volume->uid] = $info;
		}

		// Assets and Link fields → volumes
		$volumeSources = [];

		foreach ($fieldsService->getFieldsByType(AssetsField::class) as $field) {
			/** @var AssetsField $field */
			$volumeSources[(int) $field->id] = [
				$field,
				$field->restrictLocation ? [$field->restrictedLocationSource] : $field->sources,
				array_keys($volumesByUid),
			];
		}

		foreach ($fieldsService->getFieldsByType(LinkField::class) as $field) {
			/** @var LinkField $field */
			$linkType = $field->getLinkTypes()[AssetLinkType::id()] ?? null;

			if ($linkType instanceof AssetLinkType) {
				// Asset link types store no sources when all volumes with URLs are allowed.
				$volumeSources[(int) $field->id] = [
					$field,
					$linkType->sources ?? '*',
					array_keys(array_filter($volumesByUid, fn(VolumeInfo $info): bool => $info->hasUrls)),
				];
			}
		}

		foreach ($volumeSources as [$field, $sources, $uids]) {
			foreach (Classifier::resolveVolumeUids($sources, $uids) as $uid) {
				$info = $volumesByUid[$uid];
				if ($field instanceof LinkField) {
					$info->linkFields[] = $field;
				} else {
					$info->assetFields[] = $field;
				}
			}
		}

		foreach ($volumes as $id => $info) {
			$info->lazy('references', fn(): ReferenceStatistics => array_reduce(
				[...$info->assetFields, ...$info->linkFields],
				fn(ReferenceStatistics $sum, FieldInterface $field): ReferenceStatistics => $sum->add($statistics->sumReferences($siteIds, Fill::id($field->id), volumeId: $id)),
				new ReferenceStatistics(),
			));
		}

		uasort($volumes, fn(VolumeInfo $a, VolumeInfo $b): int => strnatcasecmp((string) $a->volume->name, (string) $b->volume->name));

		return $volumes;
	}
}
