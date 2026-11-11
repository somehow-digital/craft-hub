<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use craft\base\ElementExporterInterface;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\enums\ListType;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\TrashedEntryFilter;
use SomehowDigital\Craft\Hub\models\TrashedFileFilter;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\models\UserGroupFilter;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use yii\base\Component;

/**
 * The items of a list that bulk actions (see BulkActions) apply to or send: the elements by their IDs, those matching the
 * filters of a list, the files that may be downloaded, and what the exporters of Craft export. Items are the ones the
 * counted sites have (see SiteScopeService::inSiteScope()), in the trash for the trashed lists.
 *
 * @internal
 */
final class BulkService extends Component
{
	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly SiteScopeService $siteScope,
		private readonly EntryService $entries,
		private readonly FileService $files,
		private readonly UserService $users,
		private readonly TrashService $trash,
		private readonly ListService $lists,
		private readonly Elements $craftElements,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * Items of a list of elements with the IDs that are of the class, see getElements().
	 *
	 * @template T of ElementInterface
	 * @param int[] $ids
	 * @param class-string<T> $class
	 * @return list<T>
	 */
	public function getElementsOf(string $list, array $ids, string $class): array
	{
		return array_values(array_filter($this->getElements($list, $ids), fn(ElementInterface $element): bool => $element instanceof $class));
	}

	/**
	 * Items of a list of elements with the IDs, whatever their status, on the counted sites (see
	 * SiteScopeService::inSiteScope()), in the trash for the trashed ones.
	 *
	 * @param int[] $ids
	 * @return ElementInterface[]
	 */
	public function getElements(string $list, array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$scoped = fn(ElementQuery $query): ElementQuery => $this->siteScope->inSiteScope($query)->id($ids);

		return match ($list) {
			ColumnService::LIST_ENTRIES => $scoped(Entry::find())->status(null)->all(),
			ColumnService::LIST_FILES => $scoped(Asset::find())->all(),
			ColumnService::LIST_USERS => User::find()->id($ids)->status(null)->all(),
			ColumnService::LIST_TRASHED_ENTRIES => $scoped(Entry::find())->status(null)->trashed()->all(),
			ColumnService::LIST_TRASHED_FILES => $scoped(Asset::find())->trashed()->all(),
			default => [],
		};
	}

	/**
	 * IDs of the items of a list matching its filters, limited to the given IDs, or to a number of them.
	 * Filters of another list match nothing.
	 *
	 * @param int[]|null $ids
	 * @return int[]
	 */
	public function getMatchingIds(string $list, object $filter, ?array $ids = null, ?int $limit = null): array
	{

		if ($ids === []) {
			return [];
		}

		$query = match (true) {
			$list === ColumnService::LIST_ENTRIES && $filter instanceof EntryFilter => $this->entries->getQuery($filter),
			$list === ColumnService::LIST_FILES && $filter instanceof FileFilter => $this->files->getQuery($filter),
			$list === ColumnService::LIST_USERS && $filter instanceof UserFilter => $this->users->getQuery($filter),
			$list === ColumnService::LIST_TRASHED_ENTRIES && $filter instanceof TrashedEntryFilter => $this->trash->getEntryQuery($filter),
			$list === ColumnService::LIST_TRASHED_FILES && $filter instanceof TrashedFileFilter => $this->trash->getFileQuery($filter),
			default => null,
		};

		if ($query instanceof ElementQuery) {
			$query->orderBy(null)->limit($limit);

			if ($ids !== null) {
				$query->id($ids);
			}

			return array_map(Fill::int(...), $query->ids());
		}

		$items = match (true) {
			$list === ColumnService::LIST_SECTIONS && $filter instanceof SectionFilter => $this->lists->getSections($filter),
			$list === ColumnService::LIST_ENTRY_TYPES && $filter instanceof EntryTypeFilter => $this->lists->getEntryTypes($filter),
			$list === ColumnService::LIST_FIELDS && $filter instanceof FieldFilter => $this->lists->getFields($filter),
			$list === ColumnService::LIST_VOLUMES && $filter instanceof VolumeFilter => $this->lists->getVolumes($filter),
			$list === ColumnService::LIST_USER_GROUPS && $filter instanceof UserGroupFilter => $this->lists->getUserGroups($this->users->getGroups(), $filter),
			default => [],
		};

		// The lists are keyed by the IDs of their items.
		$found = array_keys($items);
		$found = $ids !== null ? array_values(array_intersect($found, $ids)) : $found;

		return $limit !== null ? array_slice($found, 0, $limit) : $found;
	}

	/**
	 * Files with the IDs that the user may view, and so download.
	 *
	 * @param int[] $ids
	 * @return list<Asset>
	 */
	public function getDownloadableFiles(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$service = $this->craftElements;

		return array_values(array_filter(
			$this->siteScope->inSiteScope(Asset::find())->id($ids)->all(),
			fn(Asset $asset): bool => $service->canView($asset),
		));
	}

	/**
	 * Craft's exporters of the element type of a list, by their class, as its element indexes offer them: its own, and those
	 * registered for it by plugins (see Element::EVENT_REGISTER_EXPORTERS), for all of its sources, as Hub's lists span
	 * them. Lists of other items have none.
	 *
	 * @return array<class-string<ElementExporterInterface>,ElementExporterInterface>
	 */
	public function getExporters(string $list): array
	{
		$elementType = ListType::tryFrom($list)?->elementType();

		if ($elementType === null) {
			return [];
		}

		$exporters = [];

		foreach ($elementType::exporters('*') as $exporter) {
			if ($exporter instanceof ElementExporterInterface) {
				$exporter->setElementType($elementType);
			} else {
				/** @var array{type:class-string<ElementExporterInterface>} $config */
				$config = is_string($exporter) ? ['type' => $exporter] : $exporter;
				$exporter = $this->craftElements->createExporter($config['type']);
				$exporter->setElementType($elementType);
			}

			$exporters[$exporter::class] = $exporter;
		}

		return $exporters;
	}

	/**
	 * Formats the data of formattable exporters is sent in, by Craft's response formatters, as its element indexes offer them.
	 *
	 * @return array<string,string> labels by format
	 */
	public function getExportFormats(): array
	{
		return [
			'csv' => 'CSV',
			'xlsx' => 'XLSX',
			'json' => 'JSON',
			'xml' => 'XML',
			'yaml' => 'YAML',
		];
	}

	/**
	 * Items of a list with the IDs that the user may view, and so export, in the order of the IDs.
	 *
	 * @param int[] $ids
	 * @return list<ElementInterface>
	 */
	public function getExportableElements(string $list, array $ids): array
	{
		$query = $ids !== [] ? $this->exportQuery($list) : null;

		if (!$query instanceof ElementQuery) {
			return [];
		}

		$service = $this->craftElements;

		return array_values(array_filter(
			$query->id($ids)->fixedOrder()->all(),
			fn(ElementInterface $element): bool => $service->canView($element),
		));
	}

	/**
	 * Query of exportable items of a list (see getExportableElements()), for an exporter to export, in their order.
	 *
	 * @param ElementInterface[] $elements
	 *
	 * @return ElementQuery<array-key,ElementInterface>|null
	 */
	public function getExportQuery(string $list, array $elements): ?ElementQuery
	{
		return $this->exportQuery($list)?->id(array_map(fn(ElementInterface $element): int => (int) $element->id, $elements))->fixedOrder();
	}

	/**
	 * Query of the items of a list, on the counted sites, whatever their status, in the trash for the trashed ones.
	 *
	 * @return ElementQuery<array-key,ElementInterface>|null
	 */
	private function exportQuery(string $list): ?ElementQuery
	{
		$elementType = ListType::tryFrom($list)?->elementType();

		if ($elementType === null) {
			return null;
		}

		/** @var ElementQuery<array-key,ElementInterface> $query */
		$query = $elementType::find()->status(null);

		if ($elementType !== User::class) {
			$query->siteId($this->siteScope->getSiteIds())->unique();
		}

		if (ListType::from($list)->isTrash()) {
			$query->trashed();
		}

		return $query;
	}
}
