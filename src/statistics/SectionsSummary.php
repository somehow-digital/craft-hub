<?php

namespace SomehowDigital\Craft\Hub\statistics;

use Craft;
use craft\models\Section;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\SectionType;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\Snapshot;

/**
 * Statistics of the sections: the summary cards of their list and those of a section's page.
 */
final class SectionsSummary extends Summary
{
	/**
	 * Summary cards for the sections list.
	 *
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function index(): array
	{
		$path = 'hub/structure/sections';
		$siteIds = Hub::getInstance()->siteScope->getSiteIds();
		$months = Hub::getInstance()->filters->getThreshold(CheckKind::Sections, 'stale');
		$sections = fn () => Hub::getInstance()->structure->getSections();
		// Entries of the sections of a snapshot, by the section.
		$snapshotStats = fn (Snapshot $snapshot) => $this->byId(
			array_keys($snapshot->getSections()),
			fn (int $id) => $snapshot->statistics->sumEntries($siteIds, $id),
		);
		$referenced = fn () => count(array_filter($sections(), fn (SectionInfo $info) => $info->references->references > 0));

		return [
			$this->lazyCard(Craft::t('app', 'Sections'), 'layer-group', function () use ($sections, $referenced, $path) {
				$card = $this->sectionsCard(count($sections()), count(array_filter($sections(), fn (SectionInfo $info) => $info->isEmpty())));
				$card['details'] = [
					['label' => Craft::t('hub', 'With URLs'), 'value' => count(array_filter($sections(), fn (SectionInfo $info) => $info->hasUrls))],
					['label' => Craft::t('hub', 'Referenced'), 'value' => $referenced()],
				];
				$card['table'] = $this->table(Craft::t('app', 'Section'), Craft::t('app', 'Entries'), [
					['label' => Craft::t('app', 'Type'), 'type' => 'text'],
					['label' => Craft::t('hub', 'References'), 'type' => 'number'],
					['label' => Craft::t('hub', 'Last edited'), 'type' => 'date'],
				], array_map(function (SectionInfo $info) use ($path) {
					$type = SectionType::from($info->section->type);

					return [
						'label' => Craft::t('site', $info->section->name),
						'url' => $this->hubUrl("$path/{$info->section->id}"),
						'color' => $type->color(),
						'value' => $info->stats->total,
						'cells' => [$type->label(), $info->references->references, $info->stats->lastUpdated],
					];
				}, $sections()));

				return $card;
			}, fn (Snapshot $snapshot) => $this->sectionsCard(
				count($snapshot->getSections()),
				count(array_filter($snapshotStats($snapshot), fn (EntryStatistics $stats) => $stats->total === 0)),
			)),
			$this->lazyCard(Craft::t('hub', 'Types'), 'sitemap', function () use ($sections, $path) {
				$types = array_count_values(array_map(fn (SectionInfo $info) => $info->section->type, $sections()));
				$entries = [];
				foreach ($sections() as $info) {
					$entries[$info->section->type] = ($entries[$info->section->type] ?? 0) + $info->stats->total;
				}

				return [
					...$this->sectionTypesCard($types, $path),
					'table' => $this->table(Craft::t('app', 'Type'), Craft::t('app', 'Sections'), [
						['label' => Craft::t('app', 'Entries'), 'type' => 'number'],
					], array_map(fn (SectionType $type) => [
						'label' => $type->label(),
						'url' => $this->hubUrl($path, ['type' => $type->value]),
						'color' => $type->color(),
						'value' => $types[$type->value] ?? 0,
						'cells' => [$entries[$type->value] ?? 0],
					], SectionType::cases())),
				];
			}, fn (Snapshot $snapshot) => $this->sectionTypesCard(
				array_count_values(array_map(fn (array $section) => (string)$section['type'], $snapshot->getSections())),
				$path,
			)),
			$this->lazyCard(Craft::t('app', 'Entries'), 'file-lines', function () use ($sections, $referenced) {
				$entries = new EntryStatistics();
				$references = 0;
				foreach ($sections() as $info) {
					$entries->add($info->stats);
					$references += $info->references->references;
				}

				return $this->entriesCard($entries, Craft::t('hub', '{references, number} references into {referenced, number} sections', [
					'references' => $references,
					'referenced' => $referenced(),
				]));
			}, $this->entriesHistory(nested: false)),
			$this->lazyCard(Craft::t('hub', 'Activity'), 'clock', fn () => [
				...$this->activityCard(array_map(fn (SectionInfo $info) => $info->stats, $sections()), $path, $months),
				'table' => $this->leastEditedTable(Craft::t('app', 'Section'), array_map(fn (SectionInfo $info) => [
					'label' => Craft::t('site', $info->section->name),
					'url' => $this->hubUrl("$path/{$info->section->id}"),
					'stats' => $info->stats,
				], $sections()), $months),
			], fn (Snapshot $snapshot) => $this->activityCard($snapshotStats($snapshot), $path, $months, $snapshot->date)),
		];
	}

	/**
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	public function detail(SectionInfo $info): array
	{
		$id = $info->section->id;
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->siteScope->getSiteIds();
		$path = "hub/structure/sections/$id/entries";

		return [
			$this->lazyCard(Craft::t('app', 'Entries'), 'file-lines', fn () => $this->entriesCard($info->stats, path: $path), $this->entriesHistory(sectionId: $id)),
			$this->lazyCard(Craft::t('hub', 'Entry Types'), 'shapes', function () use ($info, $id, $statistics, $siteIds) {
				$counts = [];
				$handles = [];
				foreach ($info->entryTypes as $entryType) {
					$name = Craft::t('site', $entryType->name);
					$counts[$name] = $statistics->sumEntries($siteIds, $id, $entryType->id)->total;
					$handles[$name] = $entryType->handle;
				}

				$card = $this->topCard(Craft::t('hub', 'Entry Types'), 'shapes', $counts);
				foreach ($card['segments'] as &$segment) {
					if (isset($handles[$segment['label']]) && Hub::getInstance()->access->canView(Page::Entries)) {
						$segment['url'] = $this->hubUrl('hub/data/entries', ['section' => $info->section->handle, 'type' => $handles[$segment['label']]]);
					}
				}
				unset($segment);

				$withoutEntries = count(array_filter($counts, fn (int $count) => $count === 0));
				if ($withoutEntries > 0) {
					$card['note'] = Craft::t('hub', '{count, plural, =1{One entry type has} other{# entry types have}} no entries here', ['count' => $withoutEntries]);
				}

				return $card;
			}, fn (Snapshot $snapshot) => $this->labeledTopHistory($this->entryTypeLabels(), $this->byId(
				$snapshot->getSections()[$id]['entryTypes'] ?? [],
				fn (int $typeId) => $snapshot->statistics->sumEntries($siteIds, $id, $typeId)->total,
			))),
			$this->lazyCard(Craft::t('hub', 'References'), 'link', function () use ($info, $id, $statistics, $siteIds) {
				$counts = [];
				foreach ([...$info->referenceFields, ...$info->linkFields] as $field) {
					$counts[$field->name] = $statistics->sumReferences($siteIds, $field->id, $id)->references;
				}

				$card = $this->topCard(Craft::t('hub', 'References'), 'link', $counts, sum: true);
				$card['note'] = Craft::t('hub', '{entries, plural, =0{No Entries field} =1{One Entries field} other{# Entries fields}} and {links, plural, =0{no Link field} =1{one Link field} other{# Link fields}} can relate to entries from this section', [
					'entries' => count($info->referenceFields),
					'links' => count($info->linkFields),
				]);

				return $card;
			}, fn (Snapshot $snapshot) => $this->labeledTopHistory($this->fieldLabels(), $this->byId(
				$snapshot->getSections()[$id]['fields'] ?? [],
				fn (int $fieldId) => $snapshot->statistics->sumReferences($siteIds, $fieldId, $id)->references,
			), true)),
			$this->lazyCard(Craft::t('app', 'Authors'), 'user', fn () => $this->peopleCard(Craft::t('app', 'Authors'), 'user', Hub::getInstance()->entries->getAuthors($id), 'author', $path, Craft::t('app', 'Entries'))),
		];
	}
	/**
	 * Summary card for sections, split by whether they hold entries.
	 *
	 * @return array<string,mixed>
	 */
	private function sectionsCard(int $total, int $empty): array
	{
		return [
			'label' => Craft::t('app', 'Sections'),
			'icon' => 'layer-group',
			'url' => $this->hubUrl('hub/structure/sections'),
			'total' => $total,
			'segments' => [
				['label' => Craft::t('hub', 'With entries'), 'value' => $total - $empty, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/structure/sections', ['empty' => 1])],
			],
		];
	}

	/**
	 * Summary card for sections, split by their type.
	 *
	 * @param array<string,int> $types sections by their type
	 * @return array<string,mixed>
	 */
	private function sectionTypesCard(array $types, string $path): array
	{
		return [
			'label' => Craft::t('hub', 'Types'),
			'icon' => 'sitemap',
			'url' => null,
			'total' => array_sum($types),
			'segments' => array_map(fn (SectionType $type) => [
				'label' => $type->label(),
				'value' => $types[$type->value] ?? 0,
				'color' => $type->color(),
				'icon' => $type->icon(),
				'url' => $this->hubUrl($path, ['type' => $type->value]),
			], SectionType::cases()),
		];
	}
}
