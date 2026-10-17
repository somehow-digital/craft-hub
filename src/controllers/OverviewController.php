<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use SomehowDigital\Craft\Hub\services\FileService;
use yii\web\Response;

class OverviewController extends BaseController
{
	public function actionIndex(): Response
	{
		$hub = Hub::getInstance();
		$structure = $hub->structure;
		$sections = $structure->getSections();
		$entryTypes = $structure->getEntryTypes();
		$fields = $structure->getFields();
		$volumeInfos = $hub->volumes->getVolumes();
		$checks = $hub->checks;

		$reviews = [
			$this->reviewCard(Craft::t('hub', 'Sections'), 'layer-group', 'hub/entries/sections', array_map(fn (SectionInfo $info) => $checks->forSection($info), $sections)),
			$this->reviewCard(Craft::t('hub', 'Entry Types'), 'shapes', 'hub/entries/types', array_map(fn (EntryTypeInfo $info) => $checks->forEntryType($info), $entryTypes)),
			$this->reviewCard(Craft::t('hub', 'Fields'), 'pen-ruler', 'hub/entries/fields', array_map(fn (FieldInfo $info) => $checks->forField($info), $fields)),
			$this->reviewCard(Craft::t('hub', 'Volumes'), 'folder-open', 'hub/assets/volumes', array_map(fn (VolumeInfo $info) => $checks->forVolume($info), $volumeInfos)),
		];

		$entries = new EntryStatistics();
		foreach ($sections as $info) {
			$entries->add($info->stats);
		}

		$assets = new AssetStatistics();
		foreach ($volumeInfos as $info) {
			$assets->add($info->stats);
		}

		$tasks = $this->tasks($sections, $entryTypes, $fields, $volumeInfos, $entries, $assets);

		$entriesCard = $this->entriesCard($entries);
		$entriesCard['url'] = $this->hubUrl('hub/entries/entries');

		$assetsCard = $this->assetsCard($assets);
		$assetsCard['url'] = $this->hubUrl('hub/assets/files');

		return $this->renderTemplate('hub/pages/overview', [
			'reviews' => $reviews,
			'reviewCount' => array_sum(array_map(fn (array $card) => $card['total'] - $card['passed'], $reviews)),
			'tasks' => $tasks,
			'taskCount' => count(array_filter(array_merge(...array_column($tasks, 'items')), fn (array $item) => $item['value'] > 0)),
			'inventory' => [
				$entriesCard,
				$assetsCard,
				$this->sectionsCard($sections),
				$this->entryTypesCard($entryTypes),
				$this->fieldsCard($fields),
				$this->volumesCard($volumeInfos),
			],
		]);
	}

	/**
	 * Summary card splitting items by the most urgent of their failed checks, noting the most common one.
	 *
	 * @param array<int,Check[]> $reviews checks of each item, failed ones first
	 * @return array<string,mixed>
	 */
	private function reviewCard(string $label, string $icon, string $path, array $reviews): array
	{
		$counts = array_fill_keys(array_map(fn (Severity $severity) => $severity->value, Severity::cases()), 0);
		$headings = [];
		$passed = 0;

		foreach ($reviews as $checks) {
			$failed = array_values(array_filter($checks, fn (Check $check) => !$check->passed));

			if ($failed === []) {
				$passed++;
				continue;
			}

			$counts[$failed[0]->severity->value]++;

			foreach ($failed as $check) {
				$headings[$check->heading] = ($headings[$check->heading] ?? 0) + 1;
			}
		}

		arsort($headings);

		return [
			'label' => $label,
			'icon' => $icon,
			'url' => $this->hubUrl($path),
			'total' => count($reviews),
			'passed' => $passed,
			'segments' => [
				...array_map(fn (Severity $severity) => [
					'label' => $severity->label(),
					'value' => $counts[$severity->value],
					'color' => $severity->color(),
				], Severity::cases()),
				['label' => Craft::t('hub', 'Passed'), 'value' => $passed, 'color' => 'green'],
			],
			'note' => $headings === []
				? Craft::t('hub', 'All checks passed')
				: Craft::t('hub', 'Most common issue: {check} ({count, number})', [
					'check' => array_key_first($headings),
					'count' => reset($headings),
				]),
		];
	}

	/**
	 * Counts of the content and structure worth reviewing, grouped by what they concern and linking to the matching lists.
	 * Within a group, items with something to do come first, the most urgent first.
	 *
	 * @param SectionInfo[] $sections
	 * @param EntryTypeInfo[] $entryTypes
	 * @param FieldInfo[] $fields
	 * @param VolumeInfo[] $volumes
	 * @return array<int,array<string,mixed>>
	 */
	private function tasks(array $sections, array $entryTypes, array $fields, array $volumes, EntryStatistics $entries, AssetStatistics $assets): array
	{
		$hub = Hub::getInstance();
		$settings = $hub->settings;
		$review = $hub->review;

		$groups = [
			[
				'heading' => Craft::t('hub', 'Entries'),
				'icon' => 'file-lines',
				'items' => [
					[
						'label' => Craft::t('hub', 'Unreferenced entries'),
						'description' => Craft::t('hub', 'Without URLs and not related to by any element.'),
						'icon' => 'link-slash',
						'severity' => Severity::Warning,
						'value' => $review->countUnreferenced(),
						'url' => $this->hubUrl('hub/entries/sections', ['urls' => 'no']),
					],
					[
						'label' => Craft::t('hub', 'Stale entries'),
						'description' => Craft::t('hub', 'Not updated for {months, plural, =1{one month} other{# months}}.', ['months' => $settings->staleThresholdMonths]),
						'icon' => 'clock',
						'severity' => Severity::Notice,
						'value' => $review->countStale($settings->staleThresholdMonths),
						'url' => $this->hubUrl('hub/entries/entries', ['stale' => 1]),
					],
					[
						'label' => Craft::t('hub', 'Drafts'),
						'description' => Craft::t('hub', 'Publish or discard them, so changes don’t get lost.'),
						'icon' => 'pen-to-square',
						'severity' => Severity::Notice,
						'value' => $entries->drafts,
						'url' => null,
					],
					[
						'label' => Craft::t('hub', 'Disabled entries'),
						'description' => Craft::t('hub', 'Delete those that are no longer needed.'),
						'icon' => 'eye-slash',
						'severity' => Severity::Notice,
						'value' => $entries->getDisabled(),
						'url' => $this->hubUrl('hub/entries/entries', ['status' => Entry::STATUS_DISABLED]),
					],
				],
			],
			[
				'heading' => Craft::t('hub', 'Assets'),
				'icon' => 'image',
				'items' => [
					[
						'label' => Craft::t('hub', 'Unused assets'),
						'description' => Craft::t('hub', 'Not related to through any relation field.'),
						'icon' => 'link-slash',
						'severity' => Severity::Warning,
						'value' => $assets->getUnused(),
						'url' => $this->hubUrl('hub/assets/files', ['usage' => FileService::USAGE_UNUSED]),
					],
					[
						'label' => Craft::t('hub', 'Missing alt text'),
						'description' => Craft::t('hub', 'Images without alternative text.'),
						'icon' => 'universal-access',
						'severity' => Severity::Warning,
						'value' => $assets->missingAlt,
						'url' => $this->hubUrl('hub/assets/files', ['alt' => 1]),
					],
					[
						'label' => Craft::t('hub', 'Large files'),
						'description' => Craft::t('hub', 'Larger than {size} MB.', ['size' => $settings->largeFileThresholdMb]),
						'icon' => 'weight-hanging',
						'severity' => Severity::Warning,
						'value' => $review->countLargeFiles(),
						'url' => $this->hubUrl('hub/assets/files', ['sort' => FileService::SORT_SIZE]),
					],
					[
						'label' => Craft::t('hub', 'Oversized images'),
						'description' => Craft::t('hub', 'Wider or taller than {pixels, number} px.', ['pixels' => $settings->largeImageThresholdPx]),
						'icon' => 'expand',
						'severity' => Severity::Notice,
						'value' => $review->countLargeImages(),
						'url' => $this->hubUrl('hub/assets/files', ['sort' => FileService::SORT_DIMENSIONS]),
					],
				],
			],
			[
				'heading' => Craft::t('hub', 'Structure'),
				'icon' => 'sitemap',
				'items' => [
					[
						'label' => Craft::t('hub', 'Unused entry types'),
						'description' => Craft::t('hub', 'Not assigned to any section or nested entry field.'),
						'icon' => 'ghost',
						'severity' => Severity::Error,
						'value' => count(array_filter($entryTypes, fn (EntryTypeInfo $info) => $info->hasKind(UsageKind::Unused))),
						'url' => $this->hubUrl('hub/entries/types', ['kind' => UsageKind::Unused->value]),
					],
					[
						'label' => Craft::t('hub', 'Unused fields'),
						'description' => Craft::t('hub', 'Not placed in any field layout.'),
						'icon' => 'ghost',
						'severity' => Severity::Error,
						'value' => count(array_filter($fields, fn (FieldInfo $info) => $info->isUnused())),
						'url' => $this->hubUrl('hub/entries/fields', ['unused' => 1]),
					],
					[
						'label' => Craft::t('hub', 'Empty sections'),
						'description' => Craft::t('hub', 'Sections without any entries.'),
						'icon' => 'box-open',
						'severity' => Severity::Warning,
						'value' => count(array_filter($sections, fn (SectionInfo $info) => $info->isEmpty())),
						'url' => $this->hubUrl('hub/entries/sections', ['empty' => 1]),
					],
					[
						'label' => Craft::t('hub', 'Empty entry types'),
						'description' => Craft::t('hub', 'Assigned somewhere, but no entries of this type exist.'),
						'icon' => 'box-open',
						'severity' => Severity::Warning,
						'value' => count(array_filter($entryTypes, fn (EntryTypeInfo $info) => $info->hasKind(UsageKind::Empty))),
						'url' => $this->hubUrl('hub/entries/types', ['kind' => UsageKind::Empty->value]),
					],
					[
						'label' => Craft::t('hub', 'Empty fields'),
						'description' => Craft::t('hub', 'Nested entry fields without entries and relation fields without relations.'),
						'icon' => 'link-slash',
						'severity' => Severity::Warning,
						'value' => count(array_filter($fields, fn (FieldInfo $info) => $info->isEmpty())),
						'url' => $this->hubUrl('hub/entries/fields', ['empty' => 1]),
					],
					[
						'label' => Craft::t('hub', 'Empty volumes'),
						'description' => Craft::t('hub', 'Volumes without any assets.'),
						'icon' => 'box-open',
						'severity' => Severity::Warning,
						'value' => count(array_filter($volumes, fn (VolumeInfo $info) => $info->isEmpty())),
						'url' => $this->hubUrl('hub/assets/volumes', ['empty' => 1]),
					],
				],
			],
		];

		foreach ($groups as &$group) {
			usort($group['items'], fn (array $a, array $b) => [$a['value'] === 0, $a['severity']->rank()] <=> [$b['value'] === 0, $b['severity']->rank()]);
		}

		return $groups;
	}
}
