<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\fields\Assets as AssetsField;
use craft\fields\Link as LinkField;
use craft\fields\linktypes\Asset as AssetLinkType;
use SomehowDigital\Craft\Hub\helpers\Classifier;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use yii\base\Component;

/**
 * Builds a read-only overview of volumes, the file kinds they hold and the fields relating to them.
 */
class VolumeService extends Component
{
	/** @var array<string,array{volumes:array<int,VolumeInfo>,fileKinds:array<string,FileKindInfo>}> */
	private array $graphs = [];

	/**
	 * @return array<int,VolumeInfo>
	 */
	public function getVolumes(): array
	{
		return $this->getGraph()['volumes'];
	}

	public function getVolume(int $id): ?VolumeInfo
	{
		return $this->getVolumes()[$id] ?? null;
	}

	/**
	 * @return array<string,FileKindInfo> largest first
	 */
	public function getFileKinds(): array
	{
		return $this->getGraph()['fileKinds'];
	}

	/**
	 * @return array{volumes:array<int,VolumeInfo>,fileKinds:array<string,FileKindInfo>}
	 */
	private function getGraph(): array
	{
		$siteIds = Hub::getInstance()->getSiteIds();

		return $this->graphs[implode(',', $siteIds)] ??= $this->buildGraph($siteIds);
	}

	/**
	 * @param int[] $siteIds
	 * @return array{volumes:array<int,VolumeInfo>,fileKinds:array<string,FileKindInfo>}
	 */
	private function buildGraph(array $siteIds): array
	{
		$fieldsService = Craft::$app->getFields();
		$statistics = Hub::getInstance()->statistics;

		/** @var array<int,VolumeInfo> $volumes */
		$volumes = [];
		/** @var array<string,VolumeInfo> $volumesByUid */
		$volumesByUid = [];

		foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
			$info = new VolumeInfo($volume);
			$info->hasUrls = $volume->getFs()->hasUrls;
			$volumes[$volume->id] = $info;
			$volumesByUid[$volume->uid] = $info;
		}

		// Volumes → file kinds
		/** @var array<string,FileKindInfo> $fileKinds */
		$fileKinds = [];

		foreach ($statistics->getAssetRows($siteIds) as $row) {
			if (!isset($volumes[$row['volumeId']])) {
				continue;
			}

			$info = $volumes[$row['volumeId']];
			$info->stats->add($row['stats']);
			$info->kinds[$row['kind']] = ($info->kinds[$row['kind']] ?? new AssetStatistics())->add($row['stats']);

			$kind = $fileKinds[$row['kind']] ??= new FileKindInfo($row['kind']);
			$kind->stats->add($row['stats']);
			$kind->volumes[] = $info->volume;
		}

		foreach ($volumes as $info) {
			uasort($info->kinds, fn (AssetStatistics $a, AssetStatistics $b) => $b->total <=> $a->total);
		}

		uasort($fileKinds, fn (FileKindInfo $a, FileKindInfo $b) => $b->stats->total <=> $a->stats->total);

		// Assets and Link fields → volumes
		$volumeSources = [];

		foreach ($fieldsService->getFieldsByType(AssetsField::class) as $field) {
			/** @var AssetsField $field */
			$volumeSources[$field->id] = [
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
				$volumeSources[$field->id] = [
					$field,
					$linkType->sources ?? '*',
					array_keys(array_filter($volumesByUid, fn (VolumeInfo $info) => $info->hasUrls)),
				];
			}
		}

		foreach ($volumeSources as [$field, $sources, $uids]) {
			$property = $field instanceof LinkField ? 'linkFields' : 'assetFields';

			foreach (Classifier::resolveVolumeUids($sources, $uids) as $uid) {
				$info = $volumesByUid[$uid];
				$info->{$property}[] = $field;
				$info->relations->add($statistics->sumRelations($siteIds, $field->id, volumeId: $info->volume->id));
			}
		}

		uasort($volumes, fn (VolumeInfo $a, VolumeInfo $b) => strnatcasecmp((string)$a->volume->name, (string)$b->volume->name));

		return [
			'volumes' => $volumes,
			'fileKinds' => $fileKinds,
		];
	}
}
