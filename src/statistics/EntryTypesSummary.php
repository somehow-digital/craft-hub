<?php

namespace SomehowDigital\Craft\Hub\statistics;

use Craft;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\helpers\Classifier;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\Snapshot;

/**
 * Statistics of the entry types: the summary cards of their list and those of an entry type's page.
 */
final class EntryTypesSummary extends Summary
{
	/**
	 * Summary cards for the entry types list.
	 *
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function index(): array
	{
		$path = 'hub/structure/entry-types';
		$siteIds = Hub::getInstance()->siteScope->getSiteIds();
		$months = Hub::getInstance()->filters->getThreshold(CheckKind::EntryTypes, 'stale');
		$entryTypes = fn () => Hub::getInstance()->structure->getEntryTypes();
		$kinds = fn () => array_map(fn (EntryTypeInfo $info) => $info->kinds, $entryTypes());

		return [
			$this->lazyCard(Craft::t('hub', 'Entry Types'), 'shapes', function () use ($entryTypes, $kinds, $path) {
				$card = $this->entryTypesCard($kinds());
				$card['table'] = $this->table(Craft::t('app', 'Entry Type'), Craft::t('app', 'Entries'), [
					['label' => Craft::t('app', 'Sections'), 'type' => 'number'],
					['label' => Craft::t('hub', 'Nested entry fields'), 'type' => 'number'],
					['label' => Craft::t('hub', 'Reference fields'), 'type' => 'number'],
					['label' => Craft::t('hub', 'Last edited'), 'type' => 'date'],
				], array_map(fn (EntryTypeInfo $info) => [
					'label' => Craft::t('site', $info->entryType->name),
					'url' => $this->hubUrl("$path/{$info->entryType->id}"),
					'color' => $info->entryType->color->value ?? 'gray',
					'value' => $info->stats->total,
					'cells' => [count($info->sections), count($info->nestedFields), count($info->referenceFields) + count($info->linkFields), $info->stats->lastUpdated],
				], $entryTypes()));

				return $card;
			}, fn (Snapshot $snapshot) => $this->entryTypesCard($this->snapshotEntryTypeKinds($snapshot, $siteIds))),
			$this->lazyCard(Craft::t('hub', 'Usage'), 'diagram-nested', fn () => $this->usageCard($kinds(), $path), fn (Snapshot $snapshot) => $this->usageCard($this->snapshotEntryTypeKinds($snapshot, $siteIds), $path)),
			$this->lazyCard(Craft::t('app', 'Entries'), 'file-lines', function () use ($entryTypes) {
				$entries = new EntryStatistics();
				foreach ($entryTypes() as $info) {
					$entries->add($info->stats);
				}

				return $this->entriesCard($entries);
			}, $this->entriesHistory()),
			$this->lazyCard(Craft::t('hub', 'Activity'), 'clock', fn () => [
				...$this->activityCard(array_map(fn (EntryTypeInfo $info) => $info->stats, $entryTypes()), $path, $months),
				'table' => $this->leastEditedTable(Craft::t('app', 'Entry Type'), array_map(fn (EntryTypeInfo $info) => [
					'label' => Craft::t('site', $info->entryType->name),
					'url' => $this->hubUrl("$path/{$info->entryType->id}"),
					'stats' => $info->stats,
				], $entryTypes()), $months),
			], fn (Snapshot $snapshot) => $this->activityCard(
				$this->byId(array_keys($snapshot->getEntryTypes()), fn (int $id) => $snapshot->statistics->sumEntries($siteIds, typeId: $id)),
				$path,
				$months,
				$snapshot->date,
			)),
		];
	}

	/**
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function detail(EntryTypeInfo $info): array
	{
		$id = $info->entryType->id;
		$path = "hub/structure/entry-types/$id/entries";
		$usage = fn (Snapshot $snapshot) => $snapshot->getEntryTypes()[$id] ?? ['sections' => 0, 'nestedFields' => 0, 'referenceFields' => 0, 'linkFields' => 0, 'fields' => []];

		return [
			$this->lazyCard(Craft::t('app', 'Entries'), 'file-lines', fn () => $this->entriesCard($info->stats, path: $path), $this->entriesHistory(typeId: $info->entryType->id)),
			$this->lazyCard(Craft::t('hub', 'Relations'), 'link', fn () => $this->relationsCard(
				count($info->sections),
				count($info->nestedFields),
				count($info->referenceFields),
				count($info->linkFields),
			), fn (Snapshot $snapshot) => $this->relationsCard(
				(int)$usage($snapshot)['sections'],
				(int)$usage($snapshot)['nestedFields'],
				(int)$usage($snapshot)['referenceFields'],
				(int)$usage($snapshot)['linkFields'],
			)),
			$this->lazyCard(Craft::t('app', 'Fields'), 'pen-ruler', function () use ($info) {
				$fields = Hub::getInstance()->structure->getFields();
				$kinds = [];
				foreach ($info->entryType->getFieldLayout()->getCustomFields() as $field) {
					$kind = isset($fields[$field->id]) ? $fields[$field->id]->getDataKind() : DataKind::Plain;
					$kinds[$kind->value] = ($kinds[$kind->value] ?? 0) + 1;
				}

				return $this->layoutFieldsCard($kinds);
			}, fn (Snapshot $snapshot) => $this->layoutFieldsCard(array_map('intval', (array)$usage($snapshot)['fields']))),
			$this->lazyCard(Craft::t('app', 'Authors'), 'user', fn () => $this->peopleCard(Craft::t('app', 'Authors'), 'user', Hub::getInstance()->entries->getAuthors(typeId: $id), 'author', $path, Craft::t('app', 'Entries'))),
		];
	}

	/**
	 * Summary card of what an entry type is related to.
	 *
	 * @return array<string,mixed>
	 */
	private function relationsCard(int $sections, int $nestedFields, int $referenceFields, int $linkFields): array
	{
		return [
			'label' => Craft::t('hub', 'Relations'),
			'icon' => 'link',
			'url' => null,
			'total' => $sections + $nestedFields + $referenceFields + $linkFields,
			'segments' => [
				['label' => Craft::t('app', 'Sections'), 'value' => $sections, 'color' => UsageKind::Section->color()],
				['label' => Craft::t('hub', 'Nested entry fields'), 'value' => $nestedFields, 'color' => UsageKind::Nested->color()],
				['label' => Craft::t('hub', 'Reference fields'), 'value' => $referenceFields, 'color' => UsageKind::Reference->color()],
				['label' => Craft::t('hub', 'Link fields'), 'value' => $linkFields, 'color' => UsageKind::Link->color()],
			],
		];
	}

	/**
	 * Summary card of the fields of an entry type's layout, split by the data they hold.
	 *
	 * @param array<string,int> $kinds fields by the data they hold, see DataKind
	 * @return array<string,mixed>
	 */
	private function layoutFieldsCard(array $kinds): array
	{
		return [
			'label' => Craft::t('app', 'Fields'),
			'icon' => 'pen-ruler',
			'url' => null,
			'total' => array_sum($kinds),
			'segments' => array_map(fn (DataKind $kind) => [
				'label' => $kind->label(),
				'value' => $kinds[$kind->value] ?? 0,
				'color' => $kind->color(),
				'icon' => $kind->icon(),
			], DataKind::cases()),
		];
	}
	/**
	 * Summary card for entry types, split by whether they are used and hold entries.
	 *
	 * @param array<int,UsageKind[]> $kinds how each entry type is used
	 * @return array<string,mixed>
	 */
	private function entryTypesCard(array $kinds): array
	{
		$unused = count(array_filter($kinds, fn (array $usage) => in_array(UsageKind::Unused, $usage, true)));
		$empty = count(array_filter($kinds, fn (array $usage) => in_array(UsageKind::Empty, $usage, true)));

		return [
			'label' => Craft::t('hub', 'Entry Types'),
			'icon' => 'shapes',
			'url' => $this->hubUrl('hub/structure/entry-types'),
			'total' => count($kinds),
			'segments' => [
				['label' => Craft::t('hub', 'In use'), 'value' => count($kinds) - $empty - $unused, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/structure/entry-types', ['kind' => UsageKind::Empty->value])],
				['label' => Craft::t('hub', 'Unused'), 'value' => $unused, 'color' => 'red', 'url' => $this->hubUrl('hub/structure/entry-types', ['kind' => UsageKind::Unused->value])],
			],
		];
	}

	/**
	 * Summary card for entry types, split by how they are used; an entry type can be used in several ways.
	 *
	 * @param array<int,UsageKind[]> $kinds how each entry type is used
	 * @return array<string,mixed>
	 */
	private function usageCard(array $kinds, string $path): array
	{
		$counts = array_count_values(array_map(fn (UsageKind $kind) => $kind->value, array_merge(...array_values($kinds))));

		return [
			'label' => Craft::t('hub', 'Usage'),
			'icon' => 'diagram-nested',
			'url' => null,
			'total' => count($kinds),
			'segments' => array_map(fn (UsageKind $kind) => [
				'label' => $kind->label(),
				'value' => $counts[$kind->value] ?? 0,
				'color' => $kind->color(),
				'icon' => $kind->icon(),
				'url' => $this->hubUrl($path, ['kind' => $kind->value]),
			], UsageKind::listed()),
		];
	}

	/**
	 * How the entry types of a snapshot were used, by their ID, on the sites in scope, see Classifier::entryTypeKinds();
	 * without whether they have URLs, which snapshots don't keep.
	 *
	 * @param int[] $siteIds
	 * @return array<int,UsageKind[]>
	 */
	private function snapshotEntryTypeKinds(Snapshot $snapshot, array $siteIds): array
	{
		$kinds = [];

		foreach ($snapshot->getEntryTypes() as $id => $usage) {
			$kinds[$id] = Classifier::entryTypeKinds(
				(int)$usage['sections'],
				(int)$usage['nestedFields'],
				(int)$usage['referenceFields'],
				(int)$usage['linkFields'],
				false,
				$snapshot->statistics->sumEntries($siteIds, typeId: $id)->total,
			);
		}

		return $kinds;
	}
}
