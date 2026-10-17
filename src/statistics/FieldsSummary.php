<?php

namespace SomehowDigital\Craft\Hub\statistics;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\models\EntryType;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\services\StatisticsService;

/**
 * Statistics of the fields: the summary cards of their list and those of a field's page.
 */
final class FieldsSummary extends Summary
{
	/**
	 * Summary cards for the fields list.
	 *
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function index(): array
	{
		$path = 'hub/structure/fields';
		$siteIds = Hub::getInstance()->siteScope->getSiteIds();
		$fields = fn () => Hub::getInstance()->structure->getFields();

		return [
			$this->lazyCard(Craft::t('hub', 'Fields'), 'pen-ruler', function () use ($fields) {
				$instances = 0;
				$layouts = 0;
				foreach ($fields() as $info) {
					$instances += $info->getInstanceCount();
					$layouts += count($info->layouts);
				}

				$card = $this->fieldsCard(
					count($fields()),
					count(array_filter($fields(), fn (FieldInfo $info) => $info->isEmpty())),
					count(array_filter($fields(), fn (FieldInfo $info) => $info->isUnused())),
				);
				$card['details'] = [
					['label' => Craft::t('hub', 'Instances'), 'value' => $instances],
					['label' => Craft::t('hub', 'Placements in field layouts'), 'value' => $layouts],
					['label' => Craft::t('hub', 'Instances per field'), 'value' => $fields() !== [] ? round($instances / count($fields()), 1) : 0],
				];

				return $card;
			}, function (Snapshot $snapshot) use ($siteIds) {
				$empty = 0;
				$unused = 0;
				foreach ($snapshot->getFields() as $id => $field) {
					// Like FieldInfo::isEmpty(), only nested and relational fields placed somewhere are empty.
					if ($field['owners'] === []) {
						$unused++;
					} elseif ($field['kind'] === DataKind::Nested->value) {
						$empty += $snapshot->statistics->sumEntries($siteIds, fieldId: $id)->total === 0 ? 1 : 0;
					} elseif ($field['kind'] === DataKind::Relational->value) {
						$empty += $snapshot->statistics->sumReferences($siteIds, $id)->references === 0 ? 1 : 0;
					}
				}

				return $this->fieldsCard(count($snapshot->getFields()), $empty, $unused);
			}),
			$this->lazyCard(Craft::t('hub', 'Data'), 'database', function () use ($fields, $path) {
				$kinds = array_count_values(array_map(fn (FieldInfo $info) => $info->getDataKind()->value, $fields()));
				$nestedEntries = 0;
				$references = 0;
				foreach ($fields() as $info) {
					$nestedEntries += $info->isNested ? $info->stats->total : 0;
					$references += $info->isRelational ? $info->references->references : 0;
				}

				return [
					...$this->dataCard($kinds, $path),
					'note' => Craft::t('hub', '{entries, number} nested entries · {references, number} references', [
						'entries' => $nestedEntries,
						'references' => $references,
					]),
				];
			}, fn (Snapshot $snapshot) => $this->dataCard(
				array_count_values(array_map(fn (array $field) => (string)$field['kind'], $snapshot->getFields())),
				$path,
			)),
			$this->lazyCard(Craft::t('hub', 'Field Types'), 'puzzle-piece', function () use ($fields, $path) {
				$counts = array_count_values(array_map(fn (FieldInfo $info) => $info->getTypeName(), $fields()));
				$cells = [];
				foreach ($fields() as $info) {
					$type = $info->getTypeName();
					$cells[$type] ??= [0, 0, 0];
					$cells[$type][$info->isUnused() ? 1 : 0]++;
					$cells[$type][2] += $info->getInstanceCount();
				}

				return $this->withColumns(
					$this->topCard(Craft::t('hub', 'Field Types'), 'puzzle-piece', $counts, 'type', path: $path),
					Craft::t('hub', 'Fields'),
					[
						['label' => Craft::t('hub', 'In use'), 'type' => 'number'],
						['label' => Craft::t('hub', 'Unused'), 'type' => 'number'],
						['label' => Craft::t('hub', 'Instances'), 'type' => 'number'],
					],
					$cells,
				);
			}, fn (Snapshot $snapshot) => $this->topHistory(array_count_values(array_map(
				// Labeled by the name of the type now, as the language of the snapshot may be another one.
				fn (array $field) => is_subclass_of($field['type'], FieldInterface::class) ? $field['type']::displayName() : (string)$field['type'],
				$snapshot->getFields(),
			)))),
			$this->lazyCard(Craft::t('hub', 'Relations'), 'link', function () use ($fields, $path) {
				$counts = [];
				$owners = [];
				foreach ($fields() as $info) {
					foreach ($info->layouts as $usage) {
						$counts[$usage->ownerType] = ($counts[$usage->ownerType] ?? 0) + 1;
						$owners[$usage->ownerType][$info->field->id] = true;
					}
				}

				return $this->withColumns(
					$this->topCard(Craft::t('hub', 'Relations'), 'link', $counts, 'owner', path: $path, sum: true),
					Craft::t('hub', 'Field layouts'),
					[['label' => Craft::t('hub', 'Fields'), 'type' => 'number']],
					array_map(fn (array $ids) => [count($ids)], $owners),
				);
			}, function (Snapshot $snapshot) {
				$counts = [];
				foreach ($snapshot->getFields() as $field) {
					foreach ($field['owners'] as $ownerType => $layouts) {
						$counts[$ownerType] = ($counts[$ownerType] ?? 0) + (int)$layouts;
					}
				}

				return $this->topHistory($counts, true);
			}),
		];
	}

	/**
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function detail(FieldInfo $info): array
	{
		$siteIds = Hub::getInstance()->siteScope->getSiteIds();
		$summary = [
			$this->lazyCard(Craft::t('hub', 'Relations'), 'link', function () use ($info) {
				$owners = [];
				foreach ($info->layouts as $usage) {
					$owners[$usage->ownerType] = ($owners[$usage->ownerType] ?? 0) + 1;
				}

				$card = $this->topCard(Craft::t('hub', 'Relations'), 'link', $owners, sum: true);
				$card['note'] = Craft::t('hub', '{count, plural, =0{No instances} =1{One instance} other{# instances}}', ['count' => $info->getInstanceCount()]);

				return $card;
			}, fn (Snapshot $snapshot) => $this->topHistory(
				array_map('intval', (array)($snapshot->getFields()[$info->field->id]['owners'] ?? [])),
				true,
			)),
		];

		if ($info->isNested) {
			$summary[] = $this->lazyCard(Craft::t('hub', 'Entries'), 'file-lines', fn () => $this->entriesCard($info->stats, path: "hub/structure/fields/{$info->field->id}/entries"), $this->entriesHistory(fieldId: $info->field->id));
			$summary[] = $this->lazyCard(Craft::t('hub', 'Entry Types'), 'shapes', fn () => $this->nestedEntryTypesCard(
				Hub::getInstance()->statistics,
				array_map(fn (EntryType $entryType) => $entryType->id, $info->entryTypes),
				$info->field->id,
				$siteIds,
			), fn (Snapshot $snapshot) => $this->nestedEntryTypesCard(
				$snapshot->statistics,
				array_map('intval', $snapshot->getFields()[$info->field->id]['entryTypes'] ?? []),
				$info->field->id,
				$siteIds,
			));
		}

		if ($info->isRelational) {
			$summary[] = $this->lazyCard(Craft::t('hub', 'References'), DataKind::Relational->icon(), fn () => [
				'label' => Craft::t('hub', 'References'),
				'icon' => DataKind::Relational->icon(),
				'url' => null,
				'total' => $info->references->references,
				'segments' => [],
				'note' => Craft::t('hub', '{sources, number} source elements · {targets, number} referenced elements', [
					'sources' => $info->references->sources,
					'targets' => $info->references->targets,
				]),
			], fn (Snapshot $snapshot) => ['total' => $snapshot->statistics->sumReferences($siteIds, $info->field->id)->references]);
		}

		return $summary;
	}

	/**
	 * Summary card of the entry types of a nested entry field, split by whether the field holds entries of them, counted by
	 * the live statistics or those of a snapshot.
	 *
	 * @param int[] $entryTypeIds
	 * @param int[] $siteIds
	 * @return array<string,mixed>
	 */
	private function nestedEntryTypesCard(StatisticsService $statistics, array $entryTypeIds, int $fieldId, array $siteIds): array
	{
		$withEntries = count(array_filter(
			$entryTypeIds,
			fn (int $typeId) => $statistics->sumEntries($siteIds, null, $typeId, $fieldId)->total > 0,
		));

		return [
			'label' => Craft::t('hub', 'Entry Types'),
			'icon' => 'shapes',
			'url' => null,
			'total' => count($entryTypeIds),
			'segments' => [
				['label' => Craft::t('hub', 'With entries'), 'value' => $withEntries, 'color' => 'green'],
				['label' => Craft::t('hub', 'No entries here'), 'value' => count($entryTypeIds) - $withEntries, 'color' => 'orange'],
			],
		];
	}
	/**
	 * Summary card for fields, split by whether they are placed and empty.
	 *
	 * @return array<string,mixed>
	 */
	private function fieldsCard(int $total, int $empty, int $unused): array
	{
		return [
			'label' => Craft::t('hub', 'Fields'),
			'icon' => 'pen-ruler',
			'url' => $this->hubUrl('hub/structure/fields'),
			'total' => $total,
			'segments' => [
				['label' => Craft::t('hub', 'In use'), 'value' => $total - $empty - $unused, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/structure/fields', ['empty' => 1])],
				['label' => Craft::t('hub', 'Unused'), 'value' => $unused, 'color' => 'red', 'url' => $this->hubUrl('hub/structure/fields', ['unused' => 1])],
			],
		];
	}

	/**
	 * Summary card for fields, split by the data they hold.
	 *
	 * @param array<string,int> $kinds fields by the data they hold, see DataKind
	 * @return array<string,mixed>
	 */
	private function dataCard(array $kinds, string $path): array
	{
		return [
			'label' => Craft::t('hub', 'Data'),
			'icon' => 'database',
			'url' => null,
			'total' => array_sum($kinds),
			'segments' => array_map(fn (DataKind $kind) => [
				'label' => $kind->label(),
				'value' => $kinds[$kind->value] ?? 0,
				'color' => $kind->color(),
				'icon' => $kind->icon(),
				'url' => $this->hubUrl($path, ['kind' => $kind->value]),
			], DataKind::cases()),
		];
	}
}
