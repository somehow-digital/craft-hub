<?php

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
use craft\fields\linktypes\Entry as EntryLinkType;
use craft\fields\Matrix;
use craft\helpers\Component as ComponentHelper;
use craft\helpers\UrlHelper;
use craft\models\CategoryGroup;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\TagGroup;
use craft\models\Volume;
use SomehowDigital\Craft\Hub\helpers\Classifier;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\LayoutUsage;
use SomehowDigital\Craft\Hub\models\ReferenceStatistics;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use yii\base\Component;

/**
 * Builds a read-only graph of sections, entry types and fields, and how they reference each other.
 * Their statistics are read on first access, as pages only show some of them, and some are costly to count.
 */
class StructureService extends Component
{
	/** @var array<string,array{sections:array<int,SectionInfo>,entryTypes:array<int,EntryTypeInfo>,fields:array<int,FieldInfo>}> */
	private array $graphs = [];

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
		$siteIds = Hub::getInstance()->getSiteIds();

		return $this->graphs[implode(',', $siteIds)] ??= $this->buildGraph($siteIds);
	}

	/**
	 * @param int[] $siteIds
	 * @return array{sections:array<int,SectionInfo>,entryTypes:array<int,EntryTypeInfo>,fields:array<int,FieldInfo>}
	 */
	private function buildGraph(array $siteIds): array
	{
		$entriesService = Craft::$app->getEntries();
		$fieldsService = Craft::$app->getFields();
		$statistics = Hub::getInstance()->statistics;
		$siteUids = array_filter(array_map(fn (int $id) => Craft::$app->getSites()->getSiteById($id, true)?->uid, $siteIds));

		/** @var array<int,SectionInfo> $sections */
		$sections = [];
		/** @var array<int,EntryTypeInfo> $entryTypes */
		$entryTypes = [];
		/** @var array<int,FieldInfo> $fields */
		$fields = [];

		foreach ($entriesService->getAllEntryTypes() as $entryType) {
			$entryTypes[$entryType->id] = new EntryTypeInfo($entryType);
		}

		/** @var array<string,FieldInfo> $fieldsByUid */
		$fieldsByUid = [];

		foreach ($fieldsService->getAllFields() as $field) {
			$fields[$field->id] = $fieldsByUid[$field->uid] = new FieldInfo($field);
		}

		// Sections → entry types
		$sectionTypes = [];
		$sectionsByUid = [];

		foreach ($entriesService->getAllSections() as $section) {
			$info = new SectionInfo($section);
			$siteSettings = array_intersect_key($section->getSiteSettings(), array_flip($siteIds));
			$info->hasUrls = array_filter($siteSettings, fn ($settings) => $settings->hasUrls) !== [];
			$info->lazy('stats', fn () => $statistics->sumEntries($siteIds, sectionId: $section->id));

			foreach ($section->getEntryTypes() as $entryType) {
				$info->entryTypes[] = $entryType;

				if (isset($entryTypes[$entryType->id])) {
					$entryTypes[$entryType->id]->sections[] = $section;
					$entryTypes[$entryType->id]->hasUrls = $entryTypes[$entryType->id]->hasUrls || $info->hasUrls;
				}
			}

			$sections[$section->id] = $info;
			$sectionTypes[$section->uid] = $section->type;
			$sectionsByUid[$section->uid] = $info;
		}

		// Nested entry fields → entry types
		foreach ($fieldsService->getNestedEntryFieldTypes() as $type) {
			/** @var ElementContainerFieldInterface[] $nestedFields */
			$nestedFields = $fieldsService->getFieldsByType($type);

			foreach ($nestedFields as $field) {
				if (!isset($fields[$field->id])) {
					continue;
				}

				$fieldInfo = $fields[$field->id];
				$fieldInfo->hasUrls = $field instanceof Matrix && array_filter(
					array_intersect_key($field->siteSettings, array_flip($siteUids)),
					fn (array $settings) => !empty($settings['uriFormat']),
				) !== [];
				$fieldInfo->lazy('stats', fn () => $statistics->sumEntries($siteIds, fieldId: $field->id));

				foreach ($field->getFieldLayoutProviders() as $provider) {
					if (!$provider instanceof EntryType || !isset($entryTypes[$provider->id])) {
						continue;
					}

					$fieldInfo->entryTypes[] = $provider;
					$entryTypes[$provider->id]->nestedFields[] = $field;
					$entryTypes[$provider->id]->hasUrls = $entryTypes[$provider->id]->hasUrls || $fieldInfo->hasUrls;
				}

				// CKEditor fields are nested entry fields, but only hold entries once entry types are assigned.
				$fieldInfo->isNested = $fieldInfo->entryTypes !== [];
			}
		}

		// Entries and Link fields → sections → entry types
		$entrySources = [];

		foreach ($fieldsService->getFieldsByType(EntriesField::class) as $field) {
			/** @var EntriesField $field */
			$entrySources[$field->id] = $field->sources;
		}

		foreach ($fieldsService->getFieldsByType(LinkField::class) as $field) {
			/** @var LinkField $field */
			$linkType = $field->getLinkTypes()[EntryLinkType::id()] ?? null;

			if ($linkType instanceof EntryLinkType) {
				// Link types store no sources when all are allowed.
				$entrySources[$field->id] = $linkType->sources ?? '*';
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
			$property = $field instanceof LinkField ? 'linkFields' : 'referenceFields';

			foreach (Classifier::resolveSectionUids($sources, $sectionTypes) as $uid) {
				$sectionInfo = $sectionsByUid[$uid];
				$fieldInfo->sections[] = $sectionInfo->section;
				$sectionInfo->{$property}[] = $field;
				$sectionReferenceFieldIds[$sectionInfo->section->id][] = $field->id;

				foreach ($sectionInfo->entryTypes as $entryType) {
					if (isset($entryTypes[$entryType->id]) && !isset($assigned[$entryType->id][$fieldId])) {
						$assigned[$entryType->id][$fieldId] = true;
						$entryTypes[$entryType->id]->{$property}[] = $field;
					}
				}
			}
		}

		foreach ($sectionReferenceFieldIds as $sectionId => $fieldIds) {
			$sections[$sectionId]->lazy('references', fn () => array_reduce(
				$fieldIds,
				fn (ReferenceStatistics $sum, int $fieldId) => $sum->add($statistics->sumReferences($siteIds, $fieldId, $sectionId)),
				new ReferenceStatistics(),
			));
		}

		// Reference counts for all relational fields (entries, assets, categories, …), including Link fields linking to elements
		$relationalTypes = $fieldsService->getRelationalFieldTypes();

		foreach ($fields as $fieldInfo) {
			$field = $fieldInfo->field;
			$isRelational = in_array(get_class($field), $relationalTypes, true) || ($field instanceof LinkField && array_filter(
				$field->getLinkTypes(),
				fn ($linkType) => $linkType instanceof BaseElementLinkType,
			) !== []);

			if ($isRelational) {
				$fieldInfo->isRelational = true;
				$fieldInfo->lazy('references', fn () => $statistics->sumReferences($siteIds, $field->id));
			}
		}

		// Fields → layouts
		$owners = $this->getLayoutOwners($entryTypes, $fields);

		foreach ($fieldsService->getAllLayouts() as $layout) {
			if (!ComponentHelper::validateComponentClass($layout->type, ElementInterface::class)) {
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

				$usage = clone ($owners[$layout->uid] ?? $this->describeLayout($layout));
				$usage->instances = $count;
				$fieldsByUid[$uid]->layouts[] = $usage;
			}
		}

		// Entry type stats and classification
		foreach ($entryTypes as $info) {
			$info->lazy('stats', fn () => $statistics->sumEntries($siteIds, typeId: $info->entryType->id));
			$info->lazy('kinds', fn () => Classifier::entryTypeKinds(
				count($info->sections),
				count($info->nestedFields),
				count($info->referenceFields),
				count($info->linkFields),
				$info->hasUrls,
				$info->stats->total,
			));
		}

		return [
			'sections' => $this->sortByLabel($sections, fn (SectionInfo $info) => $info->section->name),
			'entryTypes' => $this->sortByLabel($entryTypes, fn (EntryTypeInfo $info) => $info->entryType->name),
			'fields' => $this->sortByLabel($fields, fn (FieldInfo $info) => $info->field->name),
		];
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
				Craft::t('hub', 'Entry Type'),
				$entryType->name,
				$entryType->getCpEditUrl(),
				model: $entryType,
			);
		}

		foreach ($fields as $info) {
			$field = $info->field;
			if ($field instanceof FieldLayoutProviderInterface) {
				$owners[$field->getFieldLayout()->uid] = new LayoutUsage(
					$field::displayName(),
					$field->name,
					$field->getCpEditUrl(),
					model: $field,
					color: $info->getDataKind()->color(),
				);
			}
		}

		$providers = [
			Craft::t('app', 'Volume') => Craft::$app->getVolumes()->getAllVolumes(),
			Craft::t('app', 'Category Group') => Craft::$app->getCategories()->getAllGroups(),
			Craft::t('app', 'Tag Group') => Craft::$app->getTags()->getAllTagGroups(),
			Craft::t('app', 'Global Set') => Craft::$app->getGlobals()->getAllSets(),
		];

		foreach ($providers as $ownerType => $items) {
			foreach ($items as $provider) {
				/** @var Volume|CategoryGroup|TagGroup|GlobalSet $provider */
				$owners[$provider->getFieldLayout()->uid] = new LayoutUsage(
					$ownerType,
					$provider->name,
					$provider instanceof TagGroup ? UrlHelper::cpUrl('settings/tags/' . $provider->id) : $provider->getCpEditUrl(),
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
			default => new LayoutUsage($layout->type::pluralDisplayName(), $layout->type::displayName()),
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
		uasort($items, fn ($a, $b) => strnatcasecmp($label($a), $label($b)));

		return $items;
	}
}
