<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class TypesController extends BaseController
{
	protected ?Page $page = Page::EntryTypes;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/structure/entry-types')) !== null) {
			return $redirect;
		}

		$hub = Hub::getInstance();
		/** @var ListService $lists */
		$lists = $hub->lists;
		$months = $hub->filters->getThreshold(CheckKind::EntryTypes, 'stale');
		$all = $hub->structure->getEntryTypes();

		// While reviewing the selection, the filters don't apply.
		$selection = $this->selection(ColumnService::LIST_ENTRY_TYPES, 'hub/structure/entry-types');
		/** @var EntryTypeFilter $filter */
		$filter = ($selection['review'] ?? false) ? new EntryTypeFilter() : $this->listFilterFromRequest(CheckKind::EntryTypes);
		$sortColumns = $lists->getEntryTypeSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		[$pageInfo, $entryTypes] = $this->paginateItems($this->reviewItems($lists->getEntryTypes($filter, $sort, $direction), $selection));
		$columns = $hub->columns->getColumns(ColumnService::LIST_ENTRY_TYPES);
		$shown = $hub->columns->getShownKeys($columns);

		return $this->renderTemplate('hub/pages/types/index', [
			'entryTypes' => $entryTypes,
			'pageInfo' => $pageInfo,
			'total' => count($all),
			'kinds' => UsageKind::listed(),
			'activities' => in_array('updated', $shown, true) ? array_map(fn (EntryTypeInfo $info) => $info->stats->getActivity($months), $entryTypes) : [],
			'filter' => $filter,
			'columns' => $columns,
			...$this->savedFilterVariables(CheckKind::EntryTypes, 'hub/structure/entry-types', $filter->savedFilter),
			...$this->labelVariables(ColumnService::LIST_ENTRY_TYPES, array_keys($entryTypes)),
			...$this->bulkVariables(ColumnService::LIST_ENTRY_TYPES, $selection, $filter),
			...$this->sortVariables('hub/structure/entry-types', $sortColumns, ListService::SORT_NAME, $sort, $direction),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::EntryTypes, $this->entryTypesSummary());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::EntryTypes);
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$info = Hub::getInstance()->structure->getEntryType($id) ?? throw new NotFoundHttpException('Entry type not found.');
		// Entry types used by no section or field have no entries to list.
		$tab = $this->detailTab($tab, [...($info->sections !== [] || $info->nestedFields !== [] ? [self::TAB_ENTRIES] : []), self::TAB_STATISTICS]);

		if ($tab === self::TAB_STATISTICS && ($card = $this->renderCard($this->summaryCards($info))) !== null) {
			return $card;
		}

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/types/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forEntryType($info),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/structure/entry-types/$id/entries", entryType: $info->entryType),
				self::TAB_STATISTICS => ['summary' => $this->summaryCards($info)],
				self::TAB_DIAGNOSTICS => [],
				default => [
					'fields' => Hub::getInstance()->structure->getFields(),
					'statistics' => Hub::getInstance()->statistics,
				],
			},
		]);
	}

	/**
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	private function summaryCards(EntryTypeInfo $info): array
	{
		$id = $info->entryType->id;
		$path = "hub/structure/entry-types/$id/entries";

		return [
			$this->lazyCard(Craft::t('hub', 'Entries'), 'file-lines', fn () => $this->entriesCard($info->stats, path: $path)),
			$this->lazyCard(Craft::t('hub', 'Relations'), 'link', fn () => [
				'label' => Craft::t('hub', 'Relations'),
				'icon' => 'link',
				'url' => null,
				'total' => count($info->sections) + $info->getRelationCount(),
				'segments' => [
					['label' => Craft::t('hub', 'Sections'), 'value' => count($info->sections), 'color' => UsageKind::Section->color()],
					['label' => Craft::t('hub', 'Nested entry fields'), 'value' => count($info->nestedFields), 'color' => UsageKind::Nested->color()],
					['label' => Craft::t('hub', 'Reference fields'), 'value' => count($info->referenceFields), 'color' => UsageKind::Reference->color()],
					['label' => Craft::t('hub', 'Link fields'), 'value' => count($info->linkFields), 'color' => UsageKind::Link->color()],
				],
			]),
			$this->lazyCard(Craft::t('hub', 'Fields'), 'pen-ruler', function () use ($info) {
				$fields = Hub::getInstance()->structure->getFields();
				$kinds = [];
				foreach ($info->entryType->getFieldLayout()->getCustomFields() as $field) {
					$kind = isset($fields[$field->id]) ? $fields[$field->id]->getDataKind() : DataKind::Plain;
					$kinds[$kind->value] = ($kinds[$kind->value] ?? 0) + 1;
				}

				return [
					'label' => Craft::t('hub', 'Fields'),
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
			}),
			$this->lazyCard(Craft::t('hub', 'Authors'), 'user', fn () => $this->peopleCard(Craft::t('hub', 'Authors'), 'user', Hub::getInstance()->entries->getAuthors(typeId: $id), 'author', $path, Craft::t('hub', 'Entries'))),
		];
	}
}
