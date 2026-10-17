<?php

namespace SomehowDigital\Craft\Hub\controllers;

use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\ListSelection;
use SomehowDigital\Craft\Hub\helpers\ListSort;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\statistics\FieldsSummary;
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
		$selection = ListSelection::fromRequest(ColumnService::LIST_FIELDS, 'hub/structure/fields');
		/** @var FieldFilter $filter */
		$filter = $selection->review ? new FieldFilter() : $this->listFilterFromRequest(CheckKind::Fields);
		$sort = ListSort::fromRequest($lists->getFieldSortColumns(), ListService::SORT_NAME);
		[$pageInfo, $fields] = Paginator::fromRequest()->items($selection->limitItems($lists->getFields($filter, $sort->column, $sort->direction)));
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
			...$selection->variables($filter),
			...$sort->variables('hub/structure/fields'),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Fields, (new FieldsSummary())->index());
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

		// The cards load their data once their panel is opened, so building them all is cheap (see renderCard()).
		$summary = $tab === self::TAB_STATISTICS ? (new FieldsSummary())->detail($info) : [];

		if ($tab === self::TAB_STATISTICS && ($card = $this->renderCard($summary)) !== null) {
			return $card;
		}

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/fields/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forField($info),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/structure/fields/$id/entries", field: $info->field),
				self::TAB_STATISTICS => ['summary' => $summary],
				self::TAB_DIAGNOSTICS => [],
				default => [
					'entryTypes' => Hub::getInstance()->structure->getEntryTypes(),
					'fields' => Hub::getInstance()->structure->getFields(),
					'statistics' => Hub::getInstance()->statistics,
				],
			},
		]);
	}
}
