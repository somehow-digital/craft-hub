<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\FieldLayoutProviderInterface;
use craft\elements\Address;
use craft\elements\GlobalSet;
use craft\elements\User;
use craft\fields\Entries as EntriesField;
use craft\fields\Link as LinkField;
use craft\fields\linktypes\BaseElementLinkType;
use craft\fields\linktypes\BaseLinkType;
use craft\fields\linktypes\Entry as EntryLinkType;
use craft\fields\Matrix;
use craft\helpers\UrlHelper;
use craft\models\CategoryGroup;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\TagGroup;
use craft\models\Volume;
use craft\services\Categories;
use craft\services\Entries;
use craft\services\Fields;
use craft\services\Globals;
use craft\services\Sites;
use craft\services\Tags;
use craft\services\Volumes;
use SomehowDigital\Craft\Hub\base\FieldsProviderInterface;
use SomehowDigital\Craft\Hub\helpers\Classifier;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\LayoutUsage;
use SomehowDigital\Craft\Hub\models\ReferenceStatistics;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use yii\base\Component;

/**
 * Builds a read-only graph of sections, entry types and fields, and how they reference each other.
 * Their statistics are read on first access, as pages only show some of them, and some are costly to count.
 *
 * @internal
 */
final class StructureService extends Component implements FieldsProviderInterface
{
	/** @var array<string,array{sections:array<int,SectionInfo>,entryTypes:array<int,EntryTypeInfo>,fields:array<int,FieldInfo>}> */
	private array $graphs = [];

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly SiteScopeService $siteScope,
		private readonly StatisticsService $statistics,
		private readonly Entries $craftEntries,
		private readonly Fields $craftFields,
		private readonly Sites $craftSites,
		private readonly Volumes $craftVolumes,
		private readonly Categories $craftCategories,
		private readonly Tags $craftTags,
		private readonly Globals $craftGlobals,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * @return array<int,SectionInfo>
	 */
	public function getSections(): array
	{
		return $this->getGraph()['sections'];
	}

	/**
	 * @return array<int,EntryTypeInfo>
	 */
	public function getEntryTypes(): array
	{
		return $this->getGraph()['entryTypes'];
	}

	/**
	 * @return array<int,FieldInfo>
	 */
	public function getFields(): array
	{
		return $this->getGraph()['fields'];
	}

	public function getSection(int $id): ?SectionInfo
	{
		return $this->getSections()[$id] ?? null;
	}

	public function getEntryType(int $id): ?EntryTypeInfo
	{
		return $this->getEntryTypes()[$id] ?? null;
	}

	public function getField(int $id): ?FieldInfo
	{
		return $this->getFields()[$id] ?? null;
	}

	/**
	 * @return array{sections:array<int,SectionInfo>,entryTypes:array<int,EntryTypeInfo>,fields:array<int,FieldInfo>}
	 */
	private function getGraph(): array
	{
		$siteIds = $this->siteScope->getSiteIds();

		return $this->graphs[implode(',', $siteIds)] ??= $this->buildGraph($siteIds);
	}

	/**
	 * @param int[] $siteIds
	 * @return array{sections:array<int,SectionInfo>,entryTypes:array<int,EntryTypeInfo>,fields:array<int,FieldInfo>}
	 */
	private function buildGraph(array $siteIds): array
	{
		$entriesService = $this->craftEntries;
		$fieldsService = $this->craftFields;
		$statistics = $this->statistics;
		$siteUids = array_values(array_filter(array_map(fn(int $id): ?string => $this->craftSites->getSiteById($id, true)?->uid, $siteIds), is_string(...)));

		/** @var array<int,SectionInfo> $sections */
		$sections = [];
		/** @var array<int,EntryTypeInfo> $entryTypes */
		$entryTypes = [];
		/** @var array<int,FieldInfo> $fields */
		$fields = [];

		foreach ($entriesService->getAllEntryTypes() as $entryType) {
			$entryTypes[(int) $entryType->id] = new EntryTypeInfo($entryType);
		}

		/** @var array<string,FieldInfo> $fieldsByUid */
		$fieldsByUid = [];

		foreach ($fieldsService->getAllFields() as $field) {
			$fields[(int) $field->id] = $fieldsByUid[(string) $field->uid] = new FieldInfo($field);
		}

		// Sections → entry types
		$sectionTypes = [];
		$sectionsByUid = [];

		foreach ($entriesService->getAllSections() as $section) {
			$info = new SectionInfo($section);
			$siteSettings = array_intersect_key($section->getSiteSettings(), array_flip($siteIds));
			$info->hasUrls = array_filter($siteSettings, fn(mixed $settings) => $settings->hasUrls) !== [];
			$info->lazy('stats', fn(): EntryStatistics => $statistics->sumEntries($siteIds, sectionId: $section->id));

			foreach ($section->getEntryTypes() as $entryType) {
				$info->entryTypes[] = $entryType;

				if (isset($entryTypes[(int) $entryType->id])) {
					$entryTypes[(int) $entryType->id]->sections[] = $section;
					$entryTypes[(int) $entryType->id]->hasUrls = $entryTypes[(int) $entryType->id]->hasUrls || $info->hasUrls;
				}
			}

			$sections[(int) $section->id] = $info;
			$sectionTypes[(string) $section->uid] = $section->type ?? '';
			$sectionsByUid[(string) $section->uid] = $info;
		}

		$this->linkNestedFields($fields, $entryTypes, $siteIds, $siteUids);

		$this->linkReferenceFields($fields, $entryTypes, $sections, $sectionTypes, $sectionsByUid, $siteIds);

		$this->markRelational($fields, $siteIds);

		$this->placeInLayouts($fieldsByUid, $this->getLayoutOwners($entryTypes, $fields));

		$this->describeEntryTypes($entryTypes, $siteIds);

		return [
			'sections' => $this->sortByLabel($sections, fn(SectionInfo $info): string => $info->section->name ?? ''),
			'entryTypes' => $this->sortByLabel($entryTypes, fn(EntryTypeInfo $info): string => $info->entryType->name ?? ''),
			'fields' => $this->sortByLabel($fields, fn(FieldInfo $info): string => $info->field->name ?? ''),
		];
	}

	/**
	 * Nested entry fields → entry types.
	 *
	 * @param array<int,FieldInfo> $fields
	 * @param array<int,EntryTypeInfo> $entryTypes
	 * @param int[] $siteIds
	 * @param string[] $siteUids
	 */
	private function linkNestedFields(array $fields, array $entryTypes, array $siteIds, array $siteUids): void
	{
		$fieldsService = $this->craftFields;
		$statistics = $this->statistics;

		foreach ($fieldsService->getNestedEntryFieldTypes() as $type) {
			/** @var ElementContainerFieldInterface[] $nestedFields */
			$nestedFields = $fieldsService->getFieldsByType($type);

			foreach ($nestedFields as $field) {
				if (!isset($fields[(int) $field->id])) {
					continue;
				}

				$fieldInfo = $fields[(int) $field->id];
				$fieldInfo->hasUrls = $field instanceof Matrix && array_filter(
					array_intersect_key($field->siteSettings, array_flip($siteUids)),
					fn(array $settings): bool => Fill::text($settings['uriFormat']) !== null,
				) !== [];
				$fieldInfo->lazy('stats', fn(): EntryStatistics => $statistics->sumEntries($siteIds, fieldId: Fill::id($field->id)));

				foreach ($field->getFieldLayoutProviders() as $provider) {
					if (!$provider instanceof EntryType || !isset($entryTypes[(int) $provider->id])) {
						continue;
					}

					$fieldInfo->entryTypes[] = $provider;
					$entryTypes[(int) $provider->id]->nestedFields[] = $field;
					$entryTypes[(int) $provider->id]->hasUrls = $entryTypes[(int) $provider->id]->hasUrls || $fieldInfo->hasUrls;
				}

				// CKEditor fields are nested entry fields, but only hold entries once entry types are assigned.
				$fieldInfo->isNested = $fieldInfo->entryTypes !== [];
			}
		}
	}

	/**
	 * Entries and Link fields → sections → entry types.
	 *
	 * @param array<int,FieldInfo> $fields
	 * @param array<int,EntryTypeInfo> $entryTypes
	 * @param array<int,SectionInfo> $sections
	 * @param array<string,string> $sectionTypes
	 * @param array<string,SectionInfo> $sectionsByUid
	 * @param int[] $siteIds
	 */
	private function linkReferenceFields(array $fields, array $entryTypes, array $sections, array $sectionTypes, array $sectionsByUid, array $siteIds): void
	{
		$fieldsService = $this->craftFields;
		$statistics = $this->statistics;

		/** @var array<int,string|string[]|null> $entrySources */
		$entrySources = [];

		foreach ($fieldsService->getFieldsByType(EntriesField::class) as $field) {
			/** @var EntriesField $field */
			$entrySources[(int) $field->id] = $field->sources;
		}

		foreach ($fieldsService->getFieldsByType(LinkField::class) as $field) {
			/** @var LinkField $field */
			$linkType = $field->getLinkTypes()[EntryLinkType::id()] ?? null;

			if ($linkType instanceof EntryLinkType) {
				// Link types store no sources when all are allowed.
				$entrySources[(int) $field->id] = $linkType->sources ?? '*';
			}
		}

		// Entry types already holding a field, as many sections can share an entry type.
		$assigned = [];
		/** @var array<int,int[]> $sectionReferenceFieldIds section ID => IDs of the fields that can reference its entries */
		$sectionReferenceFieldIds = [];

		foreach ($entrySources as $fieldId => $sources) {
			if (!isset($fields[$fieldId])) {
				continue;
			}

			$fieldInfo = $fields[$fieldId];
			$field = $fieldInfo->field;

			foreach (Classifier::resolveSectionUids($sources, $sectionTypes) as $uid) {
				$sectionInfo = $sectionsByUid[$uid];
				$fieldInfo->sections[] = $sectionInfo->section;
				if ($field instanceof LinkField) {
					$sectionInfo->linkFields[] = $field;
				} else {
					$sectionInfo->referenceFields[] = $field;
				}

				$sectionReferenceFieldIds[(int) $sectionInfo->section->id][] = $field->id;

				foreach ($sectionInfo->entryTypes as $entryType) {
					if (isset($entryTypes[(int) $entryType->id]) && !isset($assigned[(int) $entryType->id][$fieldId])) {
						$assigned[(int) $entryType->id][$fieldId] = true;
						if ($field instanceof LinkField) {
							$entryTypes[(int) $entryType->id]->linkFields[] = $field;
						} else {
							$entryTypes[(int) $entryType->id]->referenceFields[] = $field;
						}
					}
				}
			}
		}

		foreach ($sectionReferenceFieldIds as $sectionId => $fieldIds) {
			$sections[$sectionId]->lazy('references', fn(): ReferenceStatistics => array_reduce(
				$fieldIds,
				fn(ReferenceStatistics $sum, int|string|null $fieldId): ReferenceStatistics => $sum->add($statistics->sumReferences($siteIds, Fill::id($fieldId), $sectionId)),
				new ReferenceStatistics(),
			));
		}
	}

	/**
	 * Reference counts for all relational fields.
	 *
	 * @param array<int,FieldInfo> $fields
	 * @param int[] $siteIds
	 */
	private function markRelational(array $fields, array $siteIds): void
	{
		$fieldsService = $this->craftFields;
		$statistics = $this->statistics;

		$relationalTypes = $fieldsService->getRelationalFieldTypes();

		foreach ($fields as $fieldInfo) {
			$field = $fieldInfo->field;
			$isRelational = in_array($field::class, $relationalTypes, true) || ($field instanceof LinkField && array_filter(
				$field->getLinkTypes(),
				fn(BaseLinkType $linkType): bool => $linkType instanceof BaseElementLinkType,
			) !== []);

			if ($isRelational) {
				$fieldInfo->isRelational = true;
				$fieldInfo->lazy('references', fn(): ReferenceStatistics => $statistics->sumReferences($siteIds, Fill::id($field->id)));
			}
		}
	}

	/**
	 * Fields → layouts.
	 *
	 * @param array<string,FieldInfo> $fieldsByUid
	 * @param array<string,LayoutUsage> $owners
	 */
	private function placeInLayouts(array $fieldsByUid, array $owners): void
	{
		$fieldsService = $this->craftFields;

		foreach ($fieldsService->getAllLayouts() as $layout) {
			if ($layout->type === null || !is_subclass_of($layout->type, ElementInterface::class)) {
				continue;
			}

			$instances = [];

			foreach ($layout->getCustomFieldElements() as $element) {
				$uid = $element->getFieldUid();
				$instances[$uid] = ($instances[$uid] ?? 0) + 1;
			}

			foreach ($instances as $uid => $count) {
				if (!isset($fieldsByUid[$uid])) {
					continue;
				}

				$usage = ($owners[$layout->uid] ?? $this->describeLayout($layout))->withInstances($count);
				$fieldsByUid[$uid]->layouts[] = $usage;
			}
		}
	}

	/**
	 * Entry type stats and classification.
	 *
	 * @param array<int,EntryTypeInfo> $entryTypes
	 * @param int[] $siteIds
	 */
	private function describeEntryTypes(array $entryTypes, array $siteIds): void
	{
		$statistics = $this->statistics;

		foreach ($entryTypes as $info) {
			$info->lazy('stats', fn(): EntryStatistics => $statistics->sumEntries($siteIds, typeId: $info->entryType->id));
			$info->lazy('kinds', fn(): array => Classifier::entryTypeKinds(
				count($info->sections),
				count($info->nestedFields),
				count($info->referenceFields),
				count($info->linkFields),
				$info->hasUrls,
				$info->stats->total,
			));
		}
	}

	/**
	 * Maps field layout UIDs to their owners, since saved layouts don't carry their provider.
	 *
	 * @param array<int,EntryTypeInfo> $entryTypes
	 * @param array<int,FieldInfo> $fields
	 * @return array<string,LayoutUsage>
	 */
	private function getLayoutOwners(array $entryTypes, array $fields): array
	{
		$owners = [];
		foreach ($entryTypes as $info) {
			$entryType = $info->entryType;
			$owners[$entryType->getFieldLayout()->uid] = new LayoutUsage(
				Craft::t('app', 'Entry Type'),
				$entryType->name ?? '',
				$entryType->getCpEditUrl(),
				model: $entryType,
			);
		}

		foreach ($fields as $info) {
			$field = $info->field;
			if ($field instanceof FieldLayoutProviderInterface) {
				$owners[$field->getFieldLayout()->uid] = new LayoutUsage(
					$field::displayName(),
					$field->name ?? '',
					$field->getCpEditUrl(),
					model: $field,
					color: $info->getDataKind()->color(),
				);
			}
		}

		$providers = [
			Craft::t('app', 'Volume') => $this->craftVolumes->getAllVolumes(),
			Craft::t('app', 'Category Group') => $this->craftCategories->getAllGroups(),
			Craft::t('app', 'Tag Group') => $this->craftTags->getAllTagGroups(),
			Craft::t('hub', 'Global Set') => $this->craftGlobals->getAllSets(),
		];

		foreach ($providers as $ownerType => $items) {
			foreach ($items as $provider) {
				/** @var Volume|CategoryGroup|TagGroup|GlobalSet $provider */
				$owners[$provider->getFieldLayout()->uid] = new LayoutUsage(
					$ownerType,
					$provider->name ?? '',
					$provider instanceof TagGroup ? UrlHelper::cpUrl('settings/tags/' . ($provider->id ?? '')) : $provider->getCpEditUrl(),
				);
			}
		}

		return $owners;
	}

	private function describeLayout(FieldLayout $layout): LayoutUsage
	{
		return match ($layout->type) {
			User::class => new LayoutUsage(User::pluralDisplayName(), User::pluralDisplayName(), UrlHelper::cpUrl('settings/users/fields')),
			Address::class => new LayoutUsage(Address::pluralDisplayName(), Address::pluralDisplayName(), UrlHelper::cpUrl('settings/addresses')),
			default => $layout->type === null ? new LayoutUsage('', '') : new LayoutUsage($layout->type::pluralDisplayName(), $layout->type::displayName()),
		};
	}

	/**
	 * @template T
	 * @param array<int,T> $items
	 * @param callable(T):string $label
	 * @return array<int,T>
	 */
	private function sortByLabel(array $items, callable $label): array
	{
		uasort($items, fn(mixed $a, mixed $b): int => strnatcasecmp($label($a), $label($b)));

		return $items;
	}
}
