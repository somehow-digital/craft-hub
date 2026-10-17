<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use DateTime;
use DateTimeZone;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\SectionType;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\ReviewService;
use yii\web\Response;

abstract class BaseController extends Controller
{
	public const LIST_UNREFERENCED = 'unreferenced';
	public const LIST_STALE = 'stale';
	public const LIST_UNUSED = 'unused';
	public const LIST_MISSING_ALT = 'alt';
	public const LIST_LARGE = 'large';

	public const ACTIVITY_RECENT = 'recent';
	public const ACTIVITY_STALE = 'stale';
	public const ACTIVITY_NONE = 'none';

	protected array|int|bool $allowAnonymous = false;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) {
			return false;
		}

		$this->requireCpRequest();
		$this->requirePermission(Hub::PERMISSION_ACCESS);

		return true;
	}

	/**
	 * Adds the site scope to every Hub template.
	 */
	public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
	{
		$hub = Hub::getInstance();

		return parent::renderTemplate($template, [
			'selectedSites' => $hub->getSites(),
			'hubSite' => $hub->getSiteParam(),
			'siteIds' => $hub->getSiteIds(),
			...$variables,
		], $templateMode);
	}

	/**
	 * CP URL to a Hub page that keeps the selected sites.
	 *
	 * @param array<string,mixed> $params
	 */
	protected function hubUrl(string $path, array $params = []): string
	{
		return UrlHelper::cpUrl($path, [Hub::SITE_PARAM => Hub::getInstance()->getSiteParam(), ...$params]);
	}

	/**
	 * Link to a filtered list: a relative preset on the list page itself, or a full URL from other pages.
	 *
	 * @param string|null $path list page path, or null when linking from the list page itself
	 * @param array<string,mixed> $params
	 */
	protected function filterUrl(?string $path, array $params): string
	{
		return $path === null ? '?' . http_build_query($params) : $this->hubUrl($path, $params);
	}

	/**
	 * Comma-separated values of a filter, like `?volume=images,documents`, optionally limited to the allowed ones.
	 *
	 * @param string[]|null $allowed
	 * @return string[]
	 */
	protected function listParam(string $name, ?array $allowed = null): array
	{
		$value = Craft::$app->getRequest()->getQueryParam($name, '');
		$values = array_values(array_unique(array_filter(array_map('trim', explode(',', is_string($value) ? $value : '')), fn (string $item) => $item !== '')));

		return $allowed !== null ? array_values(array_intersect($values, $allowed)) : $values;
	}

	/**
	 * Comma-separated IDs of a filter, like `?author=1,4`.
	 *
	 * @return int[]
	 */
	protected function idsParam(string $name): array
	{
		return array_values(array_filter(array_map('intval', $this->listParam($name)), fn (int $id) => $id > 0));
	}

	/**
	 * A non-negative number of a range filter, or null if it is missing or invalid.
	 */
	protected function numberParam(string $name): ?float
	{
		$value = Craft::$app->getRequest()->getQueryParam($name);

		return is_string($value) && is_numeric($value) && (float)$value >= 0 ? (float)$value : null;
	}

	/**
	 * A whole non-negative number of a range filter, or null if it is missing or invalid.
	 */
	protected function intParam(string $name): ?int
	{
		$value = $this->numberParam($name);

		return $value !== null ? (int)floor($value) : null;
	}

	/**
	 * A day of a date range filter, as sent by date inputs (`Y-m-d`), in the user's time zone.
	 */
	protected function dateParam(string $name): ?DateTime
	{
		$value = Craft::$app->getRequest()->getQueryParam($name);

		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return null;
		}

		return DateTime::createFromFormat('!Y-m-d', $value, new DateTimeZone(Craft::$app->getTimeZone())) ?: null;
	}

	/**
	 * Link and current direction of each sortable column header, for lists sorted on the server.
	 * Clicking the sorted column reverses it, any other column sorts in its natural direction.
	 * The filters are kept, the page is reset, and the default sort is left out of the URL.
	 *
	 * @param array<string,int> $sortColumns column => natural direction
	 * @return array<string,array{url:string,direction:string|null}>
	 */
	protected function sortHeaders(string $path, array $sortColumns, string $defaultSort, string $sort, int $direction): array
	{
		$params = Craft::$app->getRequest()->getQueryParamsWithoutPath();
		unset($params['sort'], $params['dir'], $params['page']);

		$headers = [];
		foreach ($sortColumns as $column => $natural) {
			$next = $column === $sort ? ($direction === SORT_ASC ? SORT_DESC : SORT_ASC) : $natural;
			$query = $params;

			if ($column !== $defaultSort || $next !== $natural) {
				$query['sort'] = $column;
			}

			if ($next !== $natural) {
				$query['dir'] = $next === SORT_ASC ? 'asc' : 'desc';
			}

			$headers[$column] = [
				'url' => UrlHelper::cpUrl($path, $query),
				'direction' => $column === $sort ? ($direction === SORT_ASC ? 'asc' : 'desc') : null,
			];
		}

		return $headers;
	}

	/**
	 * Summary cards for the entries list, linking to its filters.
	 *
	 * @param SectionInfo[] $sections
	 * @return array<int,array<string,mixed>>
	 */
	protected function entriesSummary(array $sections): array
	{
		$hub = Hub::getInstance();
		$path = 'hub/entries/entries';
		$months = $hub->settings->staleThresholdMonths;

		$stats = new EntryStatistics();
		$counts = [];
		$handles = [];
		foreach ($sections as $info) {
			$stats->add($info->stats);
			$name = Craft::t('site', $info->section->name);
			$counts[$name] = $info->stats->total;
			$handles[$name] = $info->section->handle;
		}

		// Entry types are counted by their section entries only, as nested entries aren't listed.
		$typeTotals = [];
		foreach ($hub->statistics->getEntryRows($hub->getSiteIds()) as $row) {
			if ($row['sectionId'] !== null) {
				$typeTotals[$row['typeId']] = ($typeTotals[$row['typeId']] ?? 0) + $row['stats']->total;
			}
		}

		$typeCounts = [];
		$typeHandles = [];
		foreach ($hub->structure->getEntryTypes() as $info) {
			if (($typeTotals[$info->entryType->id] ?? 0) > 0) {
				$name = Craft::t('site', $info->entryType->name);
				$typeCounts[$name] = $typeTotals[$info->entryType->id];
				$typeHandles[$name] = $info->entryType->handle;
			}
		}

		$entriesCard = $this->entriesCard($stats);
		$entriesCard['segments'][2]['url'] = $this->hubUrl($path, ['status' => Entry::STATUS_DISABLED]);

		$stale = $hub->review->countStale($months);

		return [
			$entriesCard,
			$this->topCard(Craft::t('hub', 'Sections'), 'layer-group', $counts, 'section', path: $path, filterValues: $handles),
			$this->topCard(Craft::t('hub', 'Entry Types'), 'shapes', $typeCounts, 'type', path: $path, filterValues: $typeHandles),
			[
				'label' => Craft::t('hub', 'Activity'),
				'icon' => 'clock',
				'url' => null,
				'total' => $stats->total,
				'segments' => [
					['label' => Craft::t('hub', 'Recently updated'), 'value' => max(0, $stats->total - $stale), 'color' => 'green'],
					['label' => Craft::t('hub', 'Stale'), 'value' => $stale, 'color' => 'orange', 'url' => $this->hubUrl($path, ['stale' => 1])],
				],
				'note' => Craft::t('hub', 'Stale: not updated for {months, plural, =1{one month} other{# months}}', ['months' => $months]),
			],
		];
	}

	/**
	 * Summary cards for the sections list.
	 *
	 * @param SectionInfo[] $sections
	 * @param string|null $path list page path for filter links, or null on the list page itself
	 * @return array<int,array<string,mixed>>
	 */
	protected function sectionsSummary(array $sections, ?string $path = null): array
	{
		$stats = array_map(fn (SectionInfo $info) => $info->stats, $sections);
		$types = array_count_values(array_map(fn (SectionInfo $info) => $info->section->type, $sections));
		$referenced = count(array_filter($sections, fn (SectionInfo $info) => $info->relations->relations > 0));
		$withUrls = count(array_filter($sections, fn (SectionInfo $info) => $info->hasUrls));

		$entries = new EntryStatistics();
		$relations = 0;
		foreach ($sections as $info) {
			$entries->add($info->stats);
			$relations += $info->relations->relations;
		}

		$sectionsCard = $this->sectionsCard($sections);
		$sectionsCard['note'] = Craft::t('hub', '{count, plural, =0{None} =1{One} other{#}} with URLs', ['count' => $withUrls]);

		return [
			$sectionsCard,
			[
				'label' => Craft::t('hub', 'Types'),
				'icon' => 'sitemap',
				'url' => null,
				'total' => count($sections),
				'segments' => array_map(fn (SectionType $type) => [
					'label' => $type->label(),
					'value' => $types[$type->value] ?? 0,
					'color' => $type->color(),
					'icon' => $type->icon(),
					'url' => $this->filterUrl($path, ['type' => $type->value]),
				], SectionType::cases()),
			],
			$this->entriesCard($entries, Craft::t('hub', '{relations, number} references into {referenced, number} sections', [
				'relations' => $relations,
				'referenced' => $referenced,
			])),
			$this->activityCard($stats, $path),
		];
	}

	/**
	 * Summary cards for the entry types list.
	 *
	 * @param EntryTypeInfo[] $entryTypes
	 * @param string|null $path list page path for filter links, or null on the list page itself
	 * @return array<int,array<string,mixed>>
	 */
	protected function entryTypesSummary(array $entryTypes, ?string $path = null): array
	{
		$stats = array_map(fn (EntryTypeInfo $info) => $info->stats, $entryTypes);

		$entries = new EntryStatistics();
		foreach ($stats as $item) {
			$entries->add($item);
		}

		$kinds = array_count_values(array_merge(...array_map(fn (EntryTypeInfo $info) => $info->getKindValues(), $entryTypes)));

		return [
			$this->entryTypesCard($entryTypes),
			[
				'label' => Craft::t('hub', 'Usage'),
				'icon' => 'diagram-nested',
				'url' => null,
				'total' => count($entryTypes),
				'segments' => array_map(fn (UsageKind $kind) => [
					'label' => $kind->label(),
					'value' => $kinds[$kind->value] ?? 0,
					'color' => $kind->color(),
					'icon' => $kind->icon(),
					'url' => $this->filterUrl($path, ['kind' => $kind->value]),
				], UsageKind::cases()),
			],
			$this->entriesCard($entries),
			$this->activityCard($stats, $path),
		];
	}

	/**
	 * Summary cards for the fields list.
	 *
	 * @param FieldInfo[] $fields
	 * @param string|null $path list page path for filter links, or null on the list page itself
	 * @return array<int,array<string,mixed>>
	 */
	protected function fieldsSummary(array $fields, ?string $path = null): array
	{
		$kinds = array_count_values(array_map(fn (FieldInfo $info) => $info->getDataKind()->value, $fields));
		$typeCounts = array_count_values(array_map(fn (FieldInfo $info) => $info->getTypeName(), $fields));
		$ownerCounts = [];
		$nestedEntries = 0;
		$relations = 0;

		foreach ($fields as $info) {
			foreach ($info->layouts as $usage) {
				$ownerCounts[$usage->ownerType] = ($ownerCounts[$usage->ownerType] ?? 0) + 1;
			}

			$nestedEntries += $info->isNested ? $info->stats->total : 0;
			$relations += $info->isRelational ? $info->relations->relations : 0;
		}

		return [
			$this->fieldsCard($fields),
			[
				'label' => Craft::t('hub', 'Data'),
				'icon' => 'database',
				'url' => null,
				'total' => count($fields),
				'segments' => array_map(fn (DataKind $kind) => [
					'label' => $kind->label(),
					'value' => $kinds[$kind->value] ?? 0,
					'color' => $kind->color(),
					'icon' => $kind->icon(),
					'url' => $this->filterUrl($path, ['kind' => $kind->value]),
				], DataKind::cases()),
				'note' => Craft::t('hub', '{entries, number} nested entries · {relations, number} relations', [
					'entries' => $nestedEntries,
					'relations' => $relations,
				]),
			],
			$this->topCard(Craft::t('hub', 'Field Types'), 'puzzle-piece', $typeCounts, 'type', path: $path),
			$this->topCard(Craft::t('hub', 'Relations'), 'link', $ownerCounts, 'owner', path: $path),
		];
	}

	/**
	 * Summary cards for the volumes list.
	 *
	 * @param VolumeInfo[] $volumes
	 * @param FileKindInfo[] $fileKinds
	 * @return array<int,array<string,mixed>>
	 */
	protected function volumesSummary(array $volumes, array $fileKinds): array
	{
		$assets = new AssetStatistics();
		$relations = 0;
		foreach ($volumes as $info) {
			$assets->add($info->stats);
			$relations += $info->relations->relations;
		}

		$kinds = [];
		$kindValues = [];
		foreach ($fileKinds as $info) {
			$kinds[$info->getLabel()] = $info->stats->total;
			$kindValues[$info->getLabel()] = $info->kind;
		}

		return [
			$this->volumesCard($volumes),
			$this->assetsCard($assets, Craft::t('hub', '{relations, number} relations from Assets and Link fields', [
				'relations' => $relations,
			])),
			$this->topCard(Craft::t('hub', 'Types'), 'file', $kinds, 'kind', filterValues: $kindValues),
			$this->altCard($assets),
		];
	}

	/**
	 * Summary cards for the files list, linking to its filters.
	 *
	 * @param VolumeInfo[] $volumes
	 * @param FileKindInfo[] $fileKinds
	 * @return array<int,array<string,mixed>>
	 */
	protected function filesSummary(array $volumes, array $fileKinds): array
	{
		$path = 'hub/assets/files';
		$assets = new AssetStatistics();
		$counts = [];
		$handles = [];

		foreach ($volumes as $info) {
			$assets->add($info->stats);
			$name = Craft::t('site', $info->volume->name);
			$counts[$name] = $info->stats->total;
			$handles[$name] = $info->volume->handle;
		}

		$kinds = [];
		$kindValues = [];
		foreach ($fileKinds as $info) {
			$kinds[$info->getLabel()] = $info->stats->total;
			$kindValues[$info->getLabel()] = $info->kind;
		}

		$assetsCard = $this->assetsCard($assets);
		$assetsCard['segments'][0]['url'] = $this->hubUrl($path, ['usage' => FileService::USAGE_USED]);
		$assetsCard['segments'][1]['url'] = $this->hubUrl($path, ['usage' => FileService::USAGE_UNUSED]);

		$altCard = $this->altCard($assets);
		$altCard['segments'][1]['url'] = $this->hubUrl($path, ['alt' => 1]);

		return [
			$assetsCard,
			$this->topCard(Craft::t('hub', 'Volumes'), 'folder-open', $counts, 'volume', path: $path, filterValues: $handles),
			$this->topCard(Craft::t('hub', 'Types'), 'file', $kinds, 'kind', path: $path, filterValues: $kindValues),
			$altCard,
		];
	}

	/**
	 * Summary card for volumes, split by whether they hold assets.
	 *
	 * @param VolumeInfo[] $volumes
	 * @return array<string,mixed>
	 */
	protected function volumesCard(array $volumes): array
	{
		$empty = count(array_filter($volumes, fn (VolumeInfo $info) => $info->isEmpty()));
		$withUrls = count(array_filter($volumes, fn (VolumeInfo $info) => $info->hasUrls));

		return [
			'label' => Craft::t('hub', 'Volumes'),
			'icon' => 'folder-open',
			'url' => $this->hubUrl('hub/assets/volumes'),
			'total' => count($volumes),
			'segments' => [
				['label' => Craft::t('hub', 'With assets'), 'value' => count($volumes) - $empty, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/assets/volumes', ['empty' => 1])],
			],
			'note' => Craft::t('hub', '{count, plural, =0{None} =1{One} other{#}} with URLs', ['count' => $withUrls]),
		];
	}

	/**
	 * Summary card for assets, split by whether they are related to.
	 *
	 * @return array<string,mixed>
	 */
	protected function assetsCard(AssetStatistics $stats, ?string $note = null): array
	{
		$size = Craft::$app->getFormatter()->asShortSize($stats->size, 1);

		return [
			'label' => Craft::t('hub', 'Assets'),
			'icon' => 'image',
			'url' => null,
			'total' => $stats->total,
			'segments' => [
				['label' => Craft::t('hub', 'In use'), 'value' => $stats->used, 'color' => 'green'],
				['label' => Craft::t('hub', 'Unused'), 'value' => $stats->getUnused(), 'color' => 'orange'],
			],
			'note' => $note !== null ? $size . ' · ' . $note : $size,
		];
	}

	/**
	 * Summary card for images, split by whether they have alternative text.
	 *
	 * @return array<string,mixed>
	 */
	protected function altCard(AssetStatistics $stats): array
	{
		return [
			'label' => Craft::t('hub', 'Alternative Text'),
			'icon' => 'universal-access',
			'url' => null,
			'total' => $stats->images,
			'segments' => [
				['label' => Craft::t('hub', 'With alternative text'), 'value' => $stats->images - $stats->missingAlt, 'color' => 'green'],
				['label' => Craft::t('hub', 'Missing'), 'value' => $stats->missingAlt, 'color' => 'orange'],
			],
			'note' => Craft::t('hub', 'Images only'),
		];
	}

	/**
	 * Summary card for sections, split by whether they hold entries.
	 *
	 * @param SectionInfo[] $sections
	 * @return array<string,mixed>
	 */
	protected function sectionsCard(array $sections): array
	{
		$empty = count(array_filter($sections, fn (SectionInfo $info) => $info->isEmpty()));

		return [
			'label' => Craft::t('hub', 'Sections'),
			'icon' => 'layer-group',
			'url' => $this->hubUrl('hub/entries/sections'),
			'total' => count($sections),
			'segments' => [
				['label' => Craft::t('hub', 'With entries'), 'value' => count($sections) - $empty, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/entries/sections', ['empty' => 1])],
			],
		];
	}

	/**
	 * Summary card for entry types, split by whether they are used and hold entries.
	 *
	 * @param EntryTypeInfo[] $entryTypes
	 * @return array<string,mixed>
	 */
	protected function entryTypesCard(array $entryTypes): array
	{
		$unused = count(array_filter($entryTypes, fn (EntryTypeInfo $info) => $info->hasKind(UsageKind::Unused)));
		$empty = count(array_filter($entryTypes, fn (EntryTypeInfo $info) => $info->hasKind(UsageKind::Empty)));

		return [
			'label' => Craft::t('hub', 'Entry Types'),
			'icon' => 'shapes',
			'url' => $this->hubUrl('hub/entries/types'),
			'total' => count($entryTypes),
			'segments' => [
				['label' => Craft::t('hub', 'In use'), 'value' => count($entryTypes) - $empty - $unused, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/entries/types', ['kind' => UsageKind::Empty->value])],
				['label' => Craft::t('hub', 'Unused'), 'value' => $unused, 'color' => 'red', 'url' => $this->hubUrl('hub/entries/types', ['kind' => UsageKind::Unused->value])],
			],
		];
	}

	/**
	 * Summary card for fields, split by whether they are placed and empty.
	 *
	 * @param FieldInfo[] $fields
	 * @return array<string,mixed>
	 */
	protected function fieldsCard(array $fields): array
	{
		$unused = count(array_filter($fields, fn (FieldInfo $info) => $info->isUnused()));
		$empty = count(array_filter($fields, fn (FieldInfo $info) => $info->isEmpty()));

		return [
			'label' => Craft::t('hub', 'Fields'),
			'icon' => 'pen-ruler',
			'url' => $this->hubUrl('hub/entries/fields'),
			'total' => count($fields),
			'segments' => [
				['label' => Craft::t('hub', 'In use'), 'value' => count($fields) - $empty - $unused, 'color' => 'green'],
				['label' => Craft::t('hub', 'Empty'), 'value' => $empty, 'color' => 'orange', 'url' => $this->hubUrl('hub/entries/fields', ['empty' => 1])],
				['label' => Craft::t('hub', 'Unused'), 'value' => $unused, 'color' => 'red', 'url' => $this->hubUrl('hub/entries/fields', ['unused' => 1])],
			],
		];
	}

	/**
	 * Summary card for entries, split by status.
	 *
	 * @return array<string,mixed>
	 */
	protected function entriesCard(EntryStatistics $stats, ?string $note = null): array
	{
		return [
			'label' => Craft::t('hub', 'Entries'),
			'icon' => 'file-lines',
			'url' => null,
			'total' => $stats->total,
			'segments' => [
				['label' => Craft::t('hub', 'Enabled'), 'value' => $stats->enabled, 'color' => 'green'],
				// Drafts are not part of the total, so they are listed but kept out of the bar.
				['label' => Craft::t('hub', 'Drafts'), 'value' => $stats->drafts, 'color' => 'amber', 'bar' => false],
				['label' => Craft::t('hub', 'Disabled'), 'value' => $stats->getDisabled(), 'color' => 'gray'],
			],
			'note' => $note,
		];
	}

	/**
	 * Summary card showing the largest groups of a list, with the rest combined.
	 *
	 * @param array<string,int> $counts group label => count
	 * @param string|null $filter query parameter that filters the list by a group's lowercased label
	 * @param string|null $path list page path for filter links, or null on the list page itself
	 * @param array<string,string> $filterValues group label => filter value, for groups not filtered by their lowercased label
	 * @return array<string,mixed>
	 */
	protected function topCard(string $label, string $icon, array $counts, ?string $filter = null, int $limit = 4, ?string $path = null, array $filterValues = []): array
	{
		arsort($counts);
		$top = array_slice($counts, 0, $limit, true);
		$colors = ['blue', 'violet', 'teal', 'amber'];
		$segments = [];

		foreach (array_keys($top) as $i => $group) {
			$segments[] = [
				'label' => (string)$group,
				'value' => $top[$group],
				'color' => $colors[$i % count($colors)],
				'url' => $filter !== null ? $this->filterUrl($path, [$filter => $filterValues[$group] ?? mb_strtolower((string)$group)]) : null,
			];
		}

		if (count($counts) > $limit) {
			$segments[] = [
				'label' => Craft::t('hub', '{count, number} more', ['count' => count($counts) - $limit]),
				'value' => array_sum($counts) - array_sum($top),
				'color' => 'gray',
			];
		}

		return [
			'label' => $label,
			'icon' => $icon,
			'url' => null,
			'total' => array_sum($counts),
			'segments' => $segments,
		];
	}

	/**
	 * Summary card splitting items by how recently their entries were updated.
	 *
	 * @param array<int,EntryStatistics> $stats keyed like the list it summarizes
	 * @param string|null $path list page path for filter links, or null on the list page itself
	 * @return array<string,mixed>
	 */
	protected function activityCard(array $stats, ?string $path = null): array
	{
		$months = Hub::getInstance()->settings->staleThresholdMonths;
		$counts = [self::ACTIVITY_RECENT => 0, self::ACTIVITY_STALE => 0, self::ACTIVITY_NONE => 0];

		foreach ($stats as $item) {
			$counts[$this->activity($item)]++;
		}

		return [
			'label' => Craft::t('hub', 'Activity'),
			'icon' => 'clock',
			'url' => null,
			'total' => count($stats),
			'segments' => [
				['label' => Craft::t('hub', 'Recently updated'), 'value' => $counts[self::ACTIVITY_RECENT], 'color' => 'green', 'url' => $this->filterUrl($path, ['activity' => self::ACTIVITY_RECENT])],
				['label' => Craft::t('hub', 'Stale'), 'value' => $counts[self::ACTIVITY_STALE], 'color' => 'orange', 'url' => $this->filterUrl($path, ['activity' => self::ACTIVITY_STALE])],
				['label' => Craft::t('hub', 'No entries'), 'value' => $counts[self::ACTIVITY_NONE], 'color' => 'gray', 'url' => $this->filterUrl($path, ['activity' => self::ACTIVITY_NONE])],
			],
			'note' => Craft::t('hub', 'Stale: not updated for {months, plural, =1{one month} other{# months}}', ['months' => $months]),
		];
	}

	/**
	 * Classifies entry stats as recently updated, stale or without entries.
	 */
	protected function activity(EntryStatistics $stats): string
	{
		if ($stats->lastUpdated === null) {
			return self::ACTIVITY_NONE;
		}

		$threshold = new DateTime(sprintf('-%d months', Hub::getInstance()->settings->staleThresholdMonths));

		return $stats->lastUpdated < $threshold ? self::ACTIVITY_STALE : self::ACTIVITY_RECENT;
	}

	/**
	 * Variables for the unreferenced/stale entry list shown on section and entry type pages.
	 *
	 * @return array<string,mixed>
	 */
	protected function reviewListVariables(?int $sectionId = null, ?int $typeId = null): array
	{
		$request = Craft::$app->getRequest();
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;

		$list = $request->getQueryParam('list') === self::LIST_STALE ? self::LIST_STALE : self::LIST_UNREFERENCED;
		$months = max(1, (int)$request->getQueryParam('months', Hub::getInstance()->settings->staleThresholdMonths));

		$query = $list === self::LIST_STALE
			? $review->getStaleQuery($months, $sectionId, $typeId)
			: $review->getUnreferencedQuery($sectionId, $typeId);

		return [
			'reviewList' => $list,
			'reviewMonths' => $months,
			'reviewQuery' => $query,
		];
	}

	/**
	 * Variables for the unused/missing alt text/large asset list shown on volume pages.
	 *
	 * @return array<string,mixed>
	 */
	protected function assetReviewListVariables(?int $volumeId = null): array
	{
		/** @var ReviewService $review */
		$review = Hub::getInstance()->review;

		$list = Craft::$app->getRequest()->getQueryParam('list');

		return match ($list) {
			self::LIST_MISSING_ALT => ['reviewList' => $list, 'reviewQuery' => $review->getMissingAltQuery($volumeId)],
			self::LIST_LARGE => ['reviewList' => $list, 'reviewQuery' => $review->getLargeFilesQuery($volumeId)],
			default => ['reviewList' => self::LIST_UNUSED, 'reviewQuery' => $review->getUnusedAssetsQuery($volumeId)],
		};
	}
}
