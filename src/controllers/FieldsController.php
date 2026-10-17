<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\models\EntryType;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\services\StatisticsService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FieldsController extends BaseController
{
	protected ?Page $page = Page::Fields;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/structure/fields')) !== null) {
			return $redirect;
		}

		$hub = Hub::getInstance();
		/** @var ListService $lists */
		$lists = $hub->lists;
		$all = $hub->structure->getFields();

		// Field types and layout owners are filtered by their lowercased names.
		$types = [];
		$owners = [];
		foreach ($all as $info) {
			$types[mb_strtolower($info->getTypeName())] = ['label' => $info->getTypeName(), 'icon' => $info->field->getIcon() ?: 'pen-to-square'];

			foreach ($info->getOwnerTypes() as $owner) {
				$owners[mb_strtolower($owner)] = $owner;
			}
		}
		uasort($types, fn (array $a, array $b) => strnatcasecmp($a['label'], $b['label']));

		// While reviewing the selection, the filters don't apply.
		$selection = $this->selection(ColumnService::LIST_FIELDS, 'hub/structure/fields');
		/** @var FieldFilter $filter */
		$filter = ($selection['review'] ?? false) ? new FieldFilter() : $this->listFilterFromRequest(CheckKind::Fields);
		$sortColumns = $lists->getFieldSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		[$pageInfo, $fields] = $this->paginateItems($this->reviewItems($lists->getFields($filter, $sort, $direction), $selection));
		$columns = $hub->columns->getColumns(ColumnService::LIST_FIELDS);
		$shown = $hub->columns->getShownKeys($columns);

		return $this->renderTemplate('hub/pages/fields/index', [
			'fields' => $fields,
			'pageInfo' => $pageInfo,
			'total' => count($all),
			'types' => $types,
			'owners' => $owners,
			'data' => in_array('data', $shown, true) ? array_map(fn (FieldInfo $info) => $lists->getFieldData($info), $fields) : [],
			'filter' => $filter,
			'columns' => $columns,
			...$this->savedFilterVariables(CheckKind::Fields, 'hub/structure/fields', $filter->savedFilter),
			...$this->labelVariables(ColumnService::LIST_FIELDS, array_keys($fields)),
			...$this->bulkVariables(ColumnService::LIST_FIELDS, $selection, $filter),
			...$this->sortVariables('hub/structure/fields', $sortColumns, ListService::SORT_NAME, $sort, $direction),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Fields, $this->fieldsSummary());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::Fields);
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$info = Hub::getInstance()->structure->getField($id) ?? throw new NotFoundHttpException('Field not found.');
		// Only nested entry fields have entries to list.
		$tab = $this->detailTab($tab, [...($info->isNested ? [self::TAB_ENTRIES] : []), self::TAB_STATISTICS]);

		if ($tab === self::TAB_STATISTICS && ($card = $this->renderCard($this->summaryCards($info))) !== null) {
			return $card;
		}

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/fields/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forField($info),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/structure/fields/$id/entries", field: $info->field),
				self::TAB_STATISTICS => ['summary' => $this->summaryCards($info)],
				self::TAB_DIAGNOSTICS => [],
				default => [
					'entryTypes' => Hub::getInstance()->structure->getEntryTypes(),
					'fields' => Hub::getInstance()->structure->getFields(),
					'statistics' => Hub::getInstance()->statistics,
				],
			},
		]);
	}

	/**
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	private function summaryCards(FieldInfo $info): array
	{
		$siteIds = Hub::getInstance()->getSiteIds();
		$summary = [
			$this->lazyCard(Craft::t('hub', 'Relations'), 'link', function () use ($info) {
				$owners = [];
				foreach ($info->layouts as $usage) {
					$owners[$usage->ownerType] = ($owners[$usage->ownerType] ?? 0) + 1;
				}

				$card = $this->topCard(Craft::t('hub', 'Relations'), 'link', $owners, sum: true);
				$card['note'] = Craft::t('hub', '{count, plural, =0{No instances} =1{One instance} other{# instances}}', ['count' => $info->getInstanceCount()]);

				return $card;
			}, fn (Snapshot $snapshot) => $this->topHistory(
				array_map('intval', (array)($snapshot->getFields()[$info->field->id]['owners'] ?? [])),
				true,
			)),
		];

		if ($info->isNested) {
			$summary[] = $this->lazyCard(Craft::t('hub', 'Entries'), 'file-lines', fn () => $this->entriesCard($info->stats, path: "hub/structure/fields/{$info->field->id}/entries"), $this->entriesHistory(fieldId: $info->field->id));
			$summary[] = $this->lazyCard(Craft::t('hub', 'Entry Types'), 'shapes', fn () => $this->nestedEntryTypesCard(
				Hub::getInstance()->statistics,
				array_map(fn (EntryType $entryType) => $entryType->id, $info->entryTypes),
				$info->field->id,
				$siteIds,
			), fn (Snapshot $snapshot) => $this->nestedEntryTypesCard(
				$snapshot->statistics,
				array_map('intval', $snapshot->getFields()[$info->field->id]['entryTypes'] ?? []),
				$info->field->id,
				$siteIds,
			));
		}

		if ($info->isRelational) {
			$summary[] = $this->lazyCard(Craft::t('hub', 'References'), DataKind::Relational->icon(), fn () => [
				'label' => Craft::t('hub', 'References'),
				'icon' => DataKind::Relational->icon(),
				'url' => null,
				'total' => $info->references->references,
				'segments' => [],
				'note' => Craft::t('hub', '{sources, number} source elements · {targets, number} referenced elements', [
					'sources' => $info->references->sources,
					'targets' => $info->references->targets,
				]),
			], fn (Snapshot $snapshot) => ['total' => $snapshot->statistics->sumReferences($siteIds, $info->field->id)->references]);
		}

		return $summary;
	}

	/**
	 * Summary card of the entry types of a nested entry field, split by whether the field holds entries of them, counted by
	 * the live statistics or those of a snapshot.
	 *
	 * @param int[] $entryTypeIds
	 * @param int[] $siteIds
	 * @return array<string,mixed>
	 */
	private function nestedEntryTypesCard(StatisticsService $statistics, array $entryTypeIds, int $fieldId, array $siteIds): array
	{
		$withEntries = count(array_filter(
			$entryTypeIds,
			fn (int $typeId) => $statistics->sumEntries($siteIds, null, $typeId, $fieldId)->total > 0,
		));

		return [
			'label' => Craft::t('hub', 'Entry Types'),
			'icon' => 'shapes',
			'url' => null,
			'total' => count($entryTypeIds),
			'segments' => [
				['label' => Craft::t('hub', 'With entries'), 'value' => $withEntries, 'color' => 'green'],
				['label' => Craft::t('hub', 'No entries here'), 'value' => count($entryTypeIds) - $withEntries, 'color' => 'orange'],
			],
		];
	}
}
