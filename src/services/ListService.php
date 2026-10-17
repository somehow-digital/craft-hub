<?php

namespace SomehowDigital\Craft\Hub\services;

use DateTime;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use yii\base\Component;

/**
 * Filters and sorts the lists of sections, entry types, fields and volumes.
 * Unlike entries and files, there are few of them and their details are computed anyway, so they are filtered in memory.
 */
class ListService extends Component
{
	public const SORT_NAME = 'name';
	public const SORT_TYPE = 'type';
	public const SORT_FILESYSTEM = 'fs';
	public const SORT_ENTRIES = 'entries';
	public const SORT_ASSETS = 'assets';
	public const SORT_RELATIONS = 'relations';
	public const SORT_REFERENCES = 'references';
	public const SORT_LAYOUTS = 'layouts';
	public const SORT_DATA = 'data';
	public const SORT_SIZE = 'size';
	public const SORT_ALT = 'alt';
	public const SORT_UPDATED = 'updated';
	public const SORT_UPLOADED = 'uploaded';
	public const SORT_DIAGNOSTICS = 'diagnostics';

	public const URLS_YES = 'yes';
	public const URLS_NO = 'no';

	/**
	 * @param string $sort one of the columns of {@see getSectionSortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 * @return array<int,SectionInfo>
	 */
	public function getSections(SectionFilter $filter, string $sort = self::SORT_NAME, int $direction = SORT_ASC): array
	{
		$checks = Hub::getInstance()->checks;
		$months = Hub::getInstance()->settings->staleThresholdMonths;

		$sections = array_filter(Hub::getInstance()->structure->getSections(), fn (SectionInfo $info) => $this->matchesSearch($filter->search, $info->section->name, $info->section->handle)
			&& $this->matchesAny($filter->types, [$info->section->type])
			&& $this->matchesUrls($filter->urls, $info->hasUrls)
			&& $this->inRange($info->stats->total, $filter->entriesMin, $filter->entriesMax)
			&& $this->inRange($info->references->references, $filter->referencesMin, $filter->referencesMax)
			&& $this->matchesAny($filter->activities, [$info->stats->getActivity($months)])
			&& (!$filter->empty || $info->isEmpty())
			&& ($filter->diagnoses === [] || $this->matchesAny($filter->diagnoses, [$checks->diagnosis($checks->forSection($info))])));

		return $this->sort($sections, $direction, fn (SectionInfo $info) => match ($sort) {
			self::SORT_TYPE => $info->section->type,
			self::SORT_ENTRIES => $info->stats->total,
			self::SORT_REFERENCES => $info->references->references,
			self::SORT_UPDATED => $info->stats->lastUpdated?->getTimestamp() ?? 0,
			self::SORT_DIAGNOSTICS => $checks->rank($checks->forSection($info)),
			default => $info->section->name,
		});
	}

	/**
	 * Columns the sections can be sorted by, with the direction they sort in first: names ascending, numbers and dates descending.
	 *
	 * @return array<string,int> column => `SORT_ASC` or `SORT_DESC`
	 */
	public function getSectionSortColumns(): array
	{
		return [
			self::SORT_NAME => SORT_ASC,
			self::SORT_TYPE => SORT_ASC,
			self::SORT_ENTRIES => SORT_DESC,
			self::SORT_REFERENCES => SORT_DESC,
			self::SORT_UPDATED => SORT_DESC,
			self::SORT_DIAGNOSTICS => SORT_DESC,
		];
	}

	/**
	 * @param string $sort one of the columns of {@see getEntryTypeSortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 * @return array<int,EntryTypeInfo>
	 */
	public function getEntryTypes(EntryTypeFilter $filter, string $sort = self::SORT_NAME, int $direction = SORT_ASC): array
	{
		$checks = Hub::getInstance()->checks;
		$months = Hub::getInstance()->settings->staleThresholdMonths;

		$entryTypes = array_filter(Hub::getInstance()->structure->getEntryTypes(), fn (EntryTypeInfo $info) => $this->matchesSearch($filter->search, $info->entryType->name, $info->entryType->handle)
			&& $this->matchesAny($filter->kinds, $info->getKindValues())
			&& $this->inRange($info->stats->total, $filter->entriesMin, $filter->entriesMax)
			&& $this->inRange(count($info->sections) + $info->getRelationCount(), $filter->relationsMin, $filter->relationsMax)
			&& $this->matchesAny($filter->activities, [$info->stats->getActivity($months)])
			&& ($filter->diagnoses === [] || $this->matchesAny($filter->diagnoses, [$checks->diagnosis($checks->forEntryType($info))])));

		return $this->sort($entryTypes, $direction, fn (EntryTypeInfo $info) => match ($sort) {
			self::SORT_ENTRIES => $info->stats->total,
			self::SORT_RELATIONS => count($info->sections) + $info->getRelationCount(),
			self::SORT_UPDATED => $info->stats->lastUpdated?->getTimestamp() ?? 0,
			self::SORT_DIAGNOSTICS => $checks->rank($checks->forEntryType($info)),
			default => $info->entryType->name,
		});
	}

	/**
	 * Columns the entry types can be sorted by, with the direction they sort in first: names ascending, numbers and dates descending.
	 *
	 * @return array<string,int> column => `SORT_ASC` or `SORT_DESC`
	 */
	public function getEntryTypeSortColumns(): array
	{
		return [
			self::SORT_NAME => SORT_ASC,
			self::SORT_RELATIONS => SORT_DESC,
			self::SORT_ENTRIES => SORT_DESC,
			self::SORT_UPDATED => SORT_DESC,
			self::SORT_DIAGNOSTICS => SORT_DESC,
		];
	}

	/**
	 * @param string $sort one of the columns of {@see getFieldSortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 * @return array<int,FieldInfo>
	 */
	public function getFields(FieldFilter $filter, string $sort = self::SORT_NAME, int $direction = SORT_ASC): array
	{
		$checks = Hub::getInstance()->checks;

		$fields = array_filter(Hub::getInstance()->structure->getFields(), fn (FieldInfo $info) => $this->matchesSearch($filter->search, (string)$info->field->name, (string)$info->field->handle)
			&& $this->matchesAny($filter->types, [mb_strtolower($info->getTypeName())])
			&& $this->matchesAny($filter->kinds, [$info->getDataKind()->value])
			&& $this->matchesAny($filter->owners, array_map('mb_strtolower', $info->getOwnerTypes()))
			&& $this->inRange(count($info->layouts), $filter->layoutsMin, $filter->layoutsMax)
			&& (!$filter->unused || $info->isUnused())
			&& (!$filter->empty || $info->isEmpty())
			&& ($filter->diagnoses === [] || $this->matchesAny($filter->diagnoses, [$checks->diagnosis($checks->forField($info))])));

		return $this->sort($fields, $direction, fn (FieldInfo $info) => match ($sort) {
			self::SORT_TYPE => $info->getTypeName(),
			self::SORT_LAYOUTS => count($info->layouts),
			self::SORT_DATA => $this->getFieldData($info) ?? -1,
			self::SORT_DIAGNOSTICS => $checks->rank($checks->forField($info)),
			default => (string)$info->field->name,
		});
	}

	/**
	 * Columns the fields can be sorted by, with the direction they sort in first: names ascending, numbers descending.
	 *
	 * @return array<string,int> column => `SORT_ASC` or `SORT_DESC`
	 */
	public function getFieldSortColumns(): array
	{
		return [
			self::SORT_NAME => SORT_ASC,
			self::SORT_TYPE => SORT_ASC,
			self::SORT_LAYOUTS => SORT_DESC,
			self::SORT_DATA => SORT_DESC,
			self::SORT_DIAGNOSTICS => SORT_DESC,
		];
	}

	/**
	 * Number of nested entries or references a field holds, or null for fields holding plain values.
	 */
	public function getFieldData(FieldInfo $info): ?int
	{
		return match (true) {
			$info->isNested => $info->stats->total,
			$info->isRelational => $info->references->references,
			default => null,
		};
	}

	/**
	 * @param string $sort one of the columns of {@see getVolumeSortColumns()}
	 * @param int $direction `SORT_ASC` or `SORT_DESC`
	 * @return array<int,VolumeInfo>
	 */
	public function getVolumes(VolumeFilter $filter, string $sort = self::SORT_NAME, int $direction = SORT_ASC): array
	{
		$checks = Hub::getInstance()->checks;
		$megabytes = fn (?float $value) => $value !== null ? (int)round($value * 1024 * 1024) : null;

		$volumes = array_filter(Hub::getInstance()->volumes->getVolumes(), fn (VolumeInfo $info) => $this->matchesSearch($filter->search, $info->volume->name, $info->volume->handle)
			&& $this->matchesAny($filter->kinds, $info->getKindValues())
			&& $this->matchesUrls($filter->urls, $info->hasUrls)
			&& $this->inRange($info->stats->total, $filter->assetsMin, $filter->assetsMax)
			&& $this->inRange($info->stats->size, $megabytes($filter->sizeMin), $megabytes($filter->sizeMax))
			&& $this->inRange($info->stats->missingAlt, $filter->altMin, $filter->altMax)
			&& $this->inDateRange($info->stats->lastUploaded, $filter->uploadedFrom, $filter->uploadedTo)
			&& (!$filter->empty || $info->isEmpty())
			&& ($filter->diagnoses === [] || $this->matchesAny($filter->diagnoses, [$checks->diagnosis($checks->forVolume($info))])));

		return $this->sort($volumes, $direction, fn (VolumeInfo $info) => match ($sort) {
			self::SORT_FILESYSTEM => $info->volume->getFs()->name,
			self::SORT_ASSETS => $info->stats->total,
			self::SORT_SIZE => $info->stats->size,
			self::SORT_ALT => $info->stats->missingAlt,
			self::SORT_REFERENCES => $info->references->references,
			self::SORT_UPLOADED => $info->stats->lastUploaded?->getTimestamp() ?? 0,
			self::SORT_DIAGNOSTICS => $checks->rank($checks->forVolume($info)),
			default => $info->volume->name,
		});
	}

	/**
	 * Columns the volumes can be sorted by, with the direction they sort in first: names ascending, numbers and dates descending.
	 *
	 * @return array<string,int> column => `SORT_ASC` or `SORT_DESC`
	 */
	public function getVolumeSortColumns(): array
	{
		return [
			self::SORT_NAME => SORT_ASC,
			self::SORT_FILESYSTEM => SORT_ASC,
			self::SORT_ASSETS => SORT_DESC,
			self::SORT_SIZE => SORT_DESC,
			self::SORT_ALT => SORT_DESC,
			self::SORT_REFERENCES => SORT_DESC,
			self::SORT_UPLOADED => SORT_DESC,
			self::SORT_DIAGNOSTICS => SORT_DESC,
		];
	}

	/**
	 * Sorts by the given value, keeping the order of items with the same value; strings sort naturally, ignoring case.
	 *
	 * @template T
	 * @param array<int,T> $items
	 * @param callable(T):(string|int) $value
	 * @return array<int,T>
	 */
	private function sort(array $items, int $direction, callable $value): array
	{
		uasort($items, function ($a, $b) use ($value, $direction) {
			$x = $value($a);
			$y = $value($b);
			$result = is_string($x) && is_string($y) ? strnatcasecmp($x, $y) : $x <=> $y;

			return $direction === SORT_ASC ? $result : -$result;
		});

		return $items;
	}

	private function matchesSearch(string $search, string ...$values): bool
	{
		return $search === '' || mb_stripos(implode(' ', $values), $search) !== false;
	}

	/**
	 * Whether any of the item's values is one of the filtered ones.
	 *
	 * @param string[] $filtered
	 * @param string[] $values
	 */
	private function matchesAny(array $filtered, array $values): bool
	{
		return $filtered === [] || array_intersect($values, $filtered) !== [];
	}

	private function matchesUrls(?string $urls, bool $hasUrls): bool
	{
		return $urls === null || ($urls === self::URLS_YES) === $hasUrls;
	}

	private function inRange(int $value, int|float|null $min, int|float|null $max): bool
	{
		return ($min === null || $value >= $min) && ($max === null || $value <= $max);
	}

	/**
	 * Whether the date is between the days, both included; items without a date only match open ranges.
	 */
	private function inDateRange(?DateTime $date, ?DateTime $from, ?DateTime $to): bool
	{
		if ($from === null && $to === null) {
			return true;
		}

		return $date !== null
			&& ($from === null || $date >= (clone $from)->setTime(0, 0))
			&& ($to === null || $date < (clone $to)->setTime(0, 0)->modify('+1 day'));
	}
}
