<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use SomehowDigital\Craft\Hub\controllers\BaseController;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\helpers\Classifier;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use yii\base\Component;

/**
 * Checks listed on the review tab of the detail pages, failed ones first.
 */
class CheckService extends Component
{
	/** @var array<class-string,FieldInterface[]>|null */
	private ?array $fieldsByClass = null;

	/**
	 * @return Check[]
	 */
	public function forSection(SectionInfo $info): array
	{
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->getSiteIds();
		$id = $info->section->id;

		$checks = [];

		$checks[] = new Check(
			Craft::t('hub', 'Entries'),
			Craft::t('hub', 'This section has no entries. Add content or delete the section.'),
			Craft::t('hub', 'This section has {count, plural, =1{one entry} other{# entries}}.', ['count' => $info->stats->total]),
			Severity::Warning,
			'box-open',
			!$info->isEmpty(),
		);

		$check = new Check(
			Craft::t('hub', 'Entry types'),
			Craft::t('hub', 'These entry types have no entries in this section. Remove them from the section if they are not needed.'),
			$info->isEmpty()
				? Craft::t('hub', 'Not checked, as the section has no entries.')
				: Craft::t('hub', 'Each entry type of the section has entries in it.'),
			Severity::Notice,
			'shapes',
			true,
		);
		if (!$info->isEmpty()) {
			$check->items = array_values(array_filter(
				$info->entryTypes,
				fn (EntryType $entryType) => $statistics->sumEntries($siteIds, $id, $entryType->id)->total === 0,
			));
			$check->passed = $check->items === [];
		}
		$checks[] = $check;

		if (!$info->hasUrls) {
			$checks[] = new Check(
				Craft::t('hub', 'Reachability'),
				Craft::t('hub', 'Entries have no URLs and no Entries or Link field can relate to them. Unless templates query them directly, they are not shown anywhere.'),
				Craft::t('hub', 'Entries have no URLs, but {count, plural, =1{one Entries or Link field} other{# Entries or Link fields}} can relate to them.', [
					'count' => count($info->referenceFields) + count($info->linkFields),
				]),
				Severity::Notice,
				'eye-slash',
				$info->referenceFields !== [] || $info->linkFields !== [],
			);
			$checks[] = $this->unreferencedCheck(sectionId: $id);
		}

		return $this->sort([...$checks, ...$this->entryChecks($info->stats, $id)]);
	}

	/**
	 * @return Check[]
	 */
	public function forEntryType(EntryTypeInfo $info): array
	{
		$structure = Hub::getInstance()->structure;
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->getSiteIds();
		$id = $info->entryType->id;

		$checks = [];

		$checks[] = new Check(
			Craft::t('hub', 'Usage'),
			Craft::t('hub', 'This entry type is not assigned to any section or nested entry field. Delete it if it is no longer needed.'),
			Craft::t('hub', 'Assigned to {sections, plural, =0{no section} =1{one section} other{# sections}} and {fields, plural, =0{no nested entry field} =1{one nested entry field} other{# nested entry fields}}.', [
				'sections' => count($info->sections),
				'fields' => count($info->nestedFields),
			]),
			Severity::Error,
			'ghost',
			!$info->hasKind(UsageKind::Unused),
		);

		$checks[] = new Check(
			Craft::t('hub', 'Entries'),
			Craft::t('hub', 'This entry type is assigned, but no entries exist. Create entries or remove the entry type.'),
			$info->hasKind(UsageKind::Unused)
				? Craft::t('hub', 'Not checked, as the entry type is not assigned.')
				: Craft::t('hub', '{count, plural, =1{One entry} other{# entries}} of this type exist.', ['count' => $info->stats->total]),
			Severity::Warning,
			'box-open',
			!$info->hasKind(UsageKind::Empty),
		);

		$check = new Check(
			Craft::t('hub', 'Assignments'),
			Craft::t('hub', 'This entry type has no entries in these sections and fields. Remove it from them if it is not needed there.'),
			$info->stats->total > 0
				? Craft::t('hub', 'The entry type has entries in each section and field it is assigned to.')
				: Craft::t('hub', 'Not checked, as no entries of this type exist.'),
			Severity::Notice,
			'diagram-nested',
			true,
		);
		if ($info->stats->total > 0) {
			$check->items = [
				...array_filter($info->sections, fn (Section $section) => $statistics->sumEntries($siteIds, $section->id, $id)->total === 0),
				...array_filter($info->nestedFields, fn (FieldInterface $field) => $statistics->sumEntries($siteIds, null, $id, $field->id)->total === 0),
			];
			$check->passed = $check->items === [];
		}
		$checks[] = $check;

		$fields = $structure->getFields();
		$check = new Check(
			Craft::t('hub', 'Fields'),
			Craft::t('hub', 'These fields of the layout hold no data anywhere. Remove them from the layout or delete them.'),
			Craft::t('hub', 'No nested entry or relation field of the layout is empty. Other fields are not checked.'),
			Severity::Notice,
			'pen-ruler',
			true,
		);
		$check->items = array_values(array_filter(
			$info->entryType->getFieldLayout()->getCustomFields(),
			fn (FieldInterface $field) => isset($fields[$field->id]) && $fields[$field->id]->isEmpty(),
		));
		$check->passed = $check->items === [];
		$checks[] = $check;

		if ($info->sections !== []) {
			$checks[] = $this->unreferencedCheck(typeId: $id);
			$checks = [...$checks, ...$this->entryChecks($info->stats, typeId: $id)];
		}

		return $this->sort($checks);
	}

	/**
	 * @return Check[]
	 */
	public function forField(FieldInfo $info): array
	{
		$structure = Hub::getInstance()->structure;
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->getSiteIds();
		$field = $info->field;

		$checks = [];

		$checks[] = new Check(
			Craft::t('hub', 'Usage'),
			Craft::t('hub', 'This field is not placed in any field layout. Delete it if it is no longer needed.'),
			Craft::t('hub', 'Placed in {count, plural, =1{one field layout} other{# field layouts}}.', ['count' => count($info->layouts)]),
			Severity::Error,
			'ghost',
			!$info->isUnused(),
		);

		if ($info->isNested || $info->isRelational) {
			$checks[] = new Check(
				Craft::t('hub', 'Content'),
				Craft::t('hub', 'This field is placed in field layouts, but holds no data. Remove it from the layouts or delete it.'),
				match (true) {
					$info->isUnused() => Craft::t('hub', 'Not checked, as the field is not placed in any field layout.'),
					$info->isNested => Craft::t('hub', 'The field holds {count, plural, =1{one nested entry} other{# nested entries}}.', ['count' => $info->stats->total]),
					default => Craft::t('hub', 'The field holds {count, plural, =1{one relation} other{# relations}}.', ['count' => $info->relations->relations]),
				},
				Severity::Warning,
				'box-open',
				!$info->isEmpty(),
			);
		}

		if ($info->isNested) {
			$check = new Check(
				Craft::t('hub', 'Entry types'),
				Craft::t('hub', 'These entry types are offered by the field, but have no entries in it. Remove them from the field if they are not needed.'),
				$info->stats->total > 0
					? Craft::t('hub', 'Each entry type offered by the field has entries in it.')
					: Craft::t('hub', 'Not checked, as the field holds no entries.'),
				Severity::Notice,
				'shapes',
				true,
			);
			if ($info->stats->total > 0) {
				$check->items = array_values(array_filter(
					$info->entryTypes,
					fn (EntryType $entryType) => $statistics->sumEntries($siteIds, null, $entryType->id, $field->id)->total === 0,
				));
				$check->passed = $check->items === [];
			}
			$checks[] = $check;
		}

		if ($info->sections !== []) {
			$sections = $structure->getSections();
			$check = new Check(
				Craft::t('hub', 'Sources'),
				Craft::t('hub', 'The field can reference entries from these sections, but they have no entries.'),
				Craft::t('hub', '{count, plural, =1{The section} other{Each of the # sections}} the field can reference entries from has entries.', ['count' => count($info->sections)]),
				Severity::Notice,
				'layer-group',
				true,
			);
			$check->items = array_values(array_filter(
				$info->sections,
				fn (Section $section) => isset($sections[$section->id]) && $sections[$section->id]->isEmpty(),
			));
			$check->passed = $check->items === [];
			$checks[] = $check;
		}

		$check = new Check(
			Craft::t('hub', 'Similar fields'),
			Craft::t('hub', 'These fields are of the same type and have similar handles. Consider consolidating them into one field.'),
			Craft::t('hub', 'No other {type} field has a similar handle.', ['type' => $info->getTypeName()]),
			Severity::Notice,
			'clone',
			true,
		);
		foreach ($this->getFieldsByClass()[$field::class] ?? [] as $other) {
			if ($other->id !== $field->id && Classifier::similarHandles($field->handle, $other->handle)) {
				$check->items[] = $other;
			}
		}
		$check->passed = $check->items === [];
		$checks[] = $check;

		if (!$info->isUnused()) {
			$checks[] = new Check(
				Craft::t('hub', 'Instructions'),
				Craft::t('hub', 'This field has no instructions. Instructions help editors fill it in correctly.'),
				Craft::t('hub', 'The field has instructions for editors.'),
				Severity::Notice,
				'circle-info',
				trim((string)$field->instructions) !== '',
			);
		}

		return $this->sort($checks);
	}

	/**
	 * @return Check[]
	 */
	public function forVolume(VolumeInfo $info): array
	{
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;
		$settings = Hub::getInstance()->settings;
		$id = $info->volume->id;

		$checks = [];

		$checks[] = new Check(
			Craft::t('hub', 'Assets'),
			Craft::t('hub', 'This volume has no assets. Delete it if it is no longer needed.'),
			Craft::t('hub', 'This volume has {count, plural, =1{one asset} other{# assets}}.', ['count' => $info->stats->total]),
			Severity::Notice,
			'box-open',
			!$info->isEmpty(),
		);

		$checks[] = new Check(
			Craft::t('hub', 'Relations'),
			Craft::t('hub', 'No Assets or Link field can relate to assets from this volume, so they can only be used in rich text.'),
			Craft::t('hub', '{count, plural, =1{One Assets or Link field} other{# Assets or Link fields}} can relate to assets from this volume.', [
				'count' => count($info->assetFields) + count($info->linkFields),
			]),
			Severity::Notice,
			'link-slash',
			$info->assetFields !== [] || $info->linkFields !== [],
		);

		$checks[] = $this->countCheck(
			Craft::t('hub', 'Usage'),
			Craft::t('hub', 'Assets that are not related to through any relation field. Delete those that are no longer needed. References from rich text fields are not taken into account here.'),
			Craft::t('hub', 'Each asset is related to through a relation field.'),
			Severity::Warning,
			'link-slash',
			$info->stats->getUnused(),
			BaseController::LIST_UNUSED,
		);

		$checks[] = $this->countCheck(
			Craft::t('hub', 'Alternative text'),
			Craft::t('hub', 'Images without alternative text. Add a short description, so the images are accessible.'),
			$info->stats->images > 0
				? Craft::t('hub', 'Each of the {count, plural, =1{image} other{# images}} has alternative text.', ['count' => $info->stats->images])
				: Craft::t('hub', 'Not checked, as the volume has no images.'),
			Severity::Warning,
			'universal-access',
			$info->stats->missingAlt,
			BaseController::LIST_MISSING_ALT,
		);

		$checks[] = $this->countCheck(
			Craft::t('hub', 'File size'),
			Craft::t('hub', 'Files larger than {size} MB. Compress or resize them, as large files slow down uploads, backups and transforms.', ['size' => $settings->largeFileThresholdMb]),
			Craft::t('hub', 'No file is larger than {size} MB.', ['size' => $settings->largeFileThresholdMb]),
			Severity::Warning,
			'weight-hanging',
			$review->countLargeFiles($id),
			BaseController::LIST_LARGE,
		);

		$checks[] = $this->countCheck(
			Craft::t('hub', 'Image dimensions'),
			Craft::t('hub', 'Images wider or taller than {pixels, number} px. Downscale them, as larger images are rarely needed.', ['pixels' => $settings->largeImageThresholdPx]),
			Craft::t('hub', 'No image is wider or taller than {pixels, number} px.', ['pixels' => $settings->largeImageThresholdPx]),
			Severity::Notice,
			'expand',
			$review->countLargeImages($id),
		);

		return $this->sort($checks);
	}

	/**
	 * @param int $relations number of elements relating to the file
	 * @param Asset[] $sites the file on each of the counted sites
	 * @param array<int,array<string,mixed>> $transforms
	 * @return Check[]
	 */
	public function forFile(Asset $asset, int $relations, array $sites, array $transforms): array
	{
		$settings = Hub::getInstance()->settings;
		$formatter = Craft::$app->getFormatter();
		$isImage = $asset->kind === Asset::KIND_IMAGE;

		$checks = [];

		$checks[] = new Check(
			Craft::t('hub', 'Usage'),
			Craft::t('hub', 'No element relates to this file through a relation field. Delete it if it is no longer needed. References from rich text fields are not taken into account here.'),
			Craft::t('hub', '{count, plural, =1{One element relates} other{# elements relate}} to this file.', ['count' => $relations]),
			Severity::Warning,
			'link-slash',
			$relations > 0,
		);

		if ($isImage) {
			$missing = array_values(array_filter($sites, fn (Asset $variant) => trim((string)$variant->alt) === ''));
			$checks[] = new Check(
				Craft::t('hub', 'Alternative text'),
				count($missing) === count($sites)
					? Craft::t('hub', 'This image has no alternative text. Add a short description, so the image is accessible.')
					: Craft::t('hub', 'This image has no alternative text on these sites: {sites}.', [
						'sites' => implode(', ', array_map(fn (Asset $variant) => Craft::t('site', $variant->getSite()->getName()), $missing)),
					]),
				Craft::t('hub', 'The image has alternative text on {count, plural, =1{its site} other{all # sites}}.', ['count' => count($sites)]),
				count($missing) === count($sites) ? Severity::Warning : Severity::Notice,
				'universal-access',
				$missing === [],
			);
		}

		$checks[] = new Check(
			Craft::t('hub', 'File size'),
			Craft::t('hub', 'This file is larger than {size} MB. Compress or resize it, as large files slow down uploads, backups and transforms.', ['size' => $settings->largeFileThresholdMb]),
			Craft::t('hub', 'The file has {size}, which is within {limit} MB.', [
				'size' => $formatter->asShortSize((int)$asset->size, 1),
				'limit' => $settings->largeFileThresholdMb,
			]),
			Severity::Warning,
			'weight-hanging',
			(int)$asset->size <= $settings->getLargeFileThreshold(),
		);

		if ($isImage) {
			$checks[] = new Check(
				Craft::t('hub', 'Image dimensions'),
				Craft::t('hub', 'This image is wider or taller than {pixels, number} px. Downscale it, as larger images are rarely needed.', ['pixels' => $settings->largeImageThresholdPx]),
				Craft::t('hub', 'The image has {width, number} × {height, number} px, which is within {pixels, number} px.', [
					'width' => (int)$asset->width,
					'height' => (int)$asset->height,
					'pixels' => $settings->largeImageThresholdPx,
				]),
				Severity::Notice,
				'expand',
				(int)$asset->width <= $settings->largeImageThresholdPx && (int)$asset->height <= $settings->largeImageThresholdPx,
			);
		}

		$checks[] = new Check(
			Craft::t('hub', 'Filename'),
			Craft::t('hub', 'The filename is a camera, screenshot or download default. A descriptive filename helps editors find the file and search engines understand it.'),
			Craft::t('hub', 'The filename is not a camera, screenshot or download default.'),
			Severity::Notice,
			'file-pen',
			!Classifier::isGenericFilename($asset->filename),
		);

		if ($transforms !== []) {
			$failed = count(array_filter($transforms, fn (array $transform) => $transform['error']));
			$check = new Check(
				Craft::t('hub', 'Transforms'),
				Craft::t('hub', 'Some image transforms failed to generate. Check the file and the transform settings.'),
				Craft::t('hub', '{count, plural, =1{The transform was} other{All # transforms were}} generated without errors.', ['count' => count($transforms)]),
				Severity::Warning,
				'crop',
				$failed === 0,
			);
			$check->count = $failed ?: null;
			$checks[] = $check;
		}

		return $this->sort($checks);
	}

	/**
	 * @param int $relations number of elements relating to the entry
	 * @return Check[]
	 */
	public function forEntry(Entry $entry, int $relations, bool $stale): array
	{
		return $this->forEntries([$entry], [$entry->id => $relations], [$entry->id => $stale])[$entry->id];
	}

	/**
	 * Checks of several entries, with the related elements and drafts of all of them loaded at once.
	 *
	 * @param Entry[] $entries
	 * @param array<int,int> $relations entry ID => number of elements relating to the entry
	 * @param array<int,bool> $stale entry ID => whether the entry is stale
	 * @return array<int,Check[]> keyed by entry ID
	 */
	public function forEntries(array $entries, array $relations, array $stale): array
	{
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;
		$months = Hub::getInstance()->settings->staleThresholdMonths;

		$missingAlt = $review->getRelatedMissingAlt($entries);
		$unavailable = $review->getRelatedUnavailable($entries);
		$drafts = $review->getDraftCounts($entries);

		$results = [];

		foreach ($entries as $entry) {
			$checks = [];

			// Entries with URLs can be reached without being referenced, as on the review lists.
			if (!$entry->getUrl()) {
				$checks[] = new Check(
					Craft::t('hub', 'References'),
					Craft::t('hub', 'This entry has no URL and no element relates to it. Unless templates query it directly, it is not shown anywhere. Links from rich text fields are not taken into account here.'),
					Craft::t('hub', 'The entry has no URL, but {count, plural, =1{one element relates} other{# elements relate}} to it.', ['count' => $relations[$entry->id] ?? 0]),
					Severity::Warning,
					'link-slash',
					($relations[$entry->id] ?? 0) > 0,
				);
			}

			$checks[] = new Check(
				Craft::t('hub', 'Activity'),
				Craft::t('hub', 'This entry has not been updated for {months, plural, =1{one month} other{# months}}. Check whether it is still accurate.', ['months' => $months]),
				Craft::t('hub', 'The entry has been updated within the last {months, plural, =1{month} other{# months}}.', ['months' => $months]),
				Severity::Notice,
				'clock',
				!($stale[$entry->id] ?? false),
			);

			$check = new Check(
				Craft::t('hub', 'Images'),
				Craft::t('hub', 'These images used by the entry or its nested entries have no alternative text.'),
				Craft::t('hub', 'No image used by the entry or its nested entries is missing alternative text.'),
				Severity::Warning,
				'universal-access',
				true,
			);
			$check->items = $missingAlt[$entry->id] ?? [];
			$check->passed = $check->items === [];
			$checks[] = $check;

			$check = new Check(
				Craft::t('hub', 'Linked entries'),
				Craft::t('hub', 'The entry or its nested entries relate to these entries, which are disabled, pending or expired. Visitors may run into missing content.'),
				Craft::t('hub', 'No entry related to by the entry or its nested entries is disabled, pending or expired.'),
				Severity::Warning,
				'link-slash',
				true,
			);
			$check->items = $unavailable[$entry->id] ?? [];
			$check->passed = $check->items === [];
			$checks[] = $check;

			$draftCount = $drafts[$entry->id] ?? 0;
			$check = new Check(
				Craft::t('hub', 'Drafts'),
				Craft::t('hub', 'This entry has drafts. Publish or discard them, so changes don’t get lost.'),
				Craft::t('hub', 'The entry has no drafts.'),
				Severity::Notice,
				'pen-to-square',
				$draftCount === 0,
			);
			$check->count = $draftCount ?: null;
			$checks[] = $check;

			$results[$entry->id] = $this->sort($checks);
		}

		return $results;
	}

	/**
	 * Checks on the entries of a section or entry type.
	 *
	 * @return Check[]
	 */
	private function entryChecks(EntryStatistics $stats, ?int $sectionId = null, ?int $typeId = null): array
	{
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;
		$months = Hub::getInstance()->settings->staleThresholdMonths;

		return [
			$this->countCheck(
				Craft::t('hub', 'Activity'),
				Craft::t('hub', 'Entries that have not been updated for {months, plural, =1{one month} other{# months}}. Check whether they are still accurate.', ['months' => $months]),
				Craft::t('hub', 'Each entry has been updated within the last {months, plural, =1{month} other{# months}}.', ['months' => $months]),
				Severity::Notice,
				'clock',
				$review->countStale($months, $sectionId, $typeId),
				BaseController::LIST_STALE,
			),
			$this->countCheck(
				Craft::t('hub', 'Disabled entries'),
				Craft::t('hub', 'Disabled entries. Delete those that are no longer needed.'),
				Craft::t('hub', 'Each entry is enabled.'),
				Severity::Notice,
				'eye-slash',
				$stats->getDisabled(),
			),
			$this->countCheck(
				Craft::t('hub', 'Drafts'),
				Craft::t('hub', 'Drafts of entries. Publish or discard them, so changes don’t get lost.'),
				Craft::t('hub', 'No entry has drafts.'),
				Severity::Notice,
				'pen-to-square',
				$stats->drafts,
			),
		];
	}

	private function unreferencedCheck(?int $sectionId = null, ?int $typeId = null): Check
	{
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;

		return $this->countCheck(
			Craft::t('hub', 'References'),
			Craft::t('hub', 'Entries without URLs that no element relates to. Unless templates query them directly, they are not shown anywhere. Links from rich text fields are not taken into account here.'),
			Craft::t('hub', 'Each entry without a URL is related to through a relation field.'),
			Severity::Warning,
			'link-slash',
			$review->countUnreferenced($sectionId, $typeId),
			BaseController::LIST_UNREFERENCED,
		);
	}

	/**
	 * A check that fails when any element is affected, linking to the review list of them.
	 */
	private function countCheck(string $heading, string $description, string $passedDescription, Severity $severity, string $icon, int $count, ?string $list = null): Check
	{
		$check = new Check($heading, $description, $passedDescription, $severity, $icon, $count === 0);
		$check->count = $count ?: null;

		if ($count > 0 && $list !== null) {
			$check->url = UrlHelper::url(Craft::$app->getRequest()->getPathInfo(), [
				Hub::SITE_PARAM => Hub::getInstance()->getSiteParam(),
				'list' => $list,
			]) . '#review';
		}

		return $check;
	}

	/**
	 * All fields grouped by class, so similar handles are only compared between fields of the same type.
	 *
	 * @return array<class-string,FieldInterface[]>
	 */
	private function getFieldsByClass(): array
	{
		if ($this->fieldsByClass === null) {
			$this->fieldsByClass = [];

			foreach (Hub::getInstance()->structure->getFields() as $info) {
				$this->fieldsByClass[$info->field::class][] = $info->field;
			}
		}

		return $this->fieldsByClass;
	}

	/**
	 * Failed checks first, by severity; passed checks keep their order.
	 *
	 * @param Check[] $checks
	 * @return Check[]
	 */
	private function sort(array $checks): array
	{
		usort($checks, fn (Check $a, Check $b) => [$a->passed, $a->passed ? 0 : $a->severity->rank()] <=> [$b->passed, $b->passed ? 0 : $b->severity->rank()]);

		return $checks;
	}
}
