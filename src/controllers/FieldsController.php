<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\models\EntryType;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FieldsController extends BaseController
{
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
		$kinds = array_map(fn (DataKind $kind) => $kind->value, DataKind::cases());

		$filter = $this->filterFromRequest(array_keys($types), $kinds, array_keys($owners));
		$sortColumns = $lists->getFieldSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		[$pageInfo, $fields] = $this->paginateItems($lists->getFields($filter, $sort, $direction));
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
			'bulkActions' => $hub->bulk->getActions(ColumnService::LIST_FIELDS),
			...$this->sortVariables('hub/structure/fields', $sortColumns, ListService::SORT_NAME, $sort, $direction),
		]);
	}

	public function actionSummary(): Response
	{
		return $this->renderSummary($this->fieldsSummary(Hub::getInstance()->structure->getFields()));
	}

	public function actionDiagnostics(): Response
	{
		$hub = Hub::getInstance();
		$fields = $hub->structure->getFields();
		$path = 'hub/structure/fields';

		return $this->renderDiagnostics('structure', 'fields', Craft::t('hub', 'Fields'), $this->checkTasks(
			array_map(fn (FieldInfo $info) => $hub->checks->forField($info), $fields),
			$path,
			[
				Craft::t('hub', 'Usage') => ['unused' => 1],
				Craft::t('hub', 'Content') => ['empty' => 1],
			],
		));
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$info = Hub::getInstance()->structure->getField($id) ?? throw new NotFoundHttpException('Field not found.');
		// Only nested entry fields have entries to list.
		$tab = $this->detailTab($tab, $info->isNested ? [self::TAB_ENTRIES] : []);

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/fields/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forField($info),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/structure/fields/$id/entries", field: $info->field),
				// Nested entries are never unreferenced, see ReviewService::getUnreferencedQuery().
				self::TAB_DIAGNOSTICS => $info->isNested ? $this->reviewListVariables(fieldId: $id, unreferenced: false) : [],
				default => $this->overviewVariables($info),
			},
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function overviewVariables(FieldInfo $info): array
	{
		$structure = Hub::getInstance()->structure;
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->getSiteIds();

		$owners = [];
		foreach ($info->layouts as $usage) {
			$owners[$usage->ownerType] = ($owners[$usage->ownerType] ?? 0) + 1;
		}

		$layouts = $this->topCard(Craft::t('hub', 'Relations'), 'link', $owners, sum: true);
		$layouts['note'] = Craft::t('hub', '{count, plural, =0{No instances} =1{One instance} other{# instances}}', ['count' => $info->getInstanceCount()]);
		$summary = [$layouts];

		if ($info->isNested) {
			$typesWithEntries = count(array_filter(
				$info->entryTypes,
				fn (EntryType $entryType) => $statistics->sumEntries($siteIds, null, $entryType->id, $info->field->id)->total > 0,
			));

			$summary[] = $this->entriesCard($info->stats);
			$summary[] = [
				'label' => Craft::t('hub', 'Entry Types'),
				'icon' => 'shapes',
				'url' => null,
				'total' => count($info->entryTypes),
				'segments' => [
					['label' => Craft::t('hub', 'With entries'), 'value' => $typesWithEntries, 'color' => 'green'],
					['label' => Craft::t('hub', 'No entries here'), 'value' => count($info->entryTypes) - $typesWithEntries, 'color' => 'orange'],
				],
			];
		}

		if ($info->isRelational) {
			$summary[] = [
				'label' => Craft::t('hub', 'References'),
				'icon' => DataKind::Relational->icon(),
				'url' => null,
				'total' => $info->references->references,
				'segments' => [],
				'note' => Craft::t('hub', '{sources, number} source elements · {targets, number} referenced elements', [
					'sources' => $info->references->sources,
					'targets' => $info->references->targets,
				]),
			];
		}

		return [
			'summary' => $summary,
			'entryTypes' => $structure->getEntryTypes(),
			'fields' => $structure->getFields(),
			'statistics' => $statistics,
		];
	}

	/**
	 * Filters arrive as query params, like those of the other lists.
	 *
	 * @param string[] $types lowercased field type names that can be filtered by
	 * @param string[] $kinds kinds of data that can be filtered by
	 * @param string[] $owners lowercased layout owner types that can be filtered by
	 */
	private function filterFromRequest(array $types, array $kinds, array $owners): FieldFilter
	{
		$request = Craft::$app->getRequest();

		$filter = new FieldFilter();
		$filter->search = trim((string)$request->getQueryParam('q', ''));
		$filter->types = $this->listParam('type', $types);
		$filter->kinds = $this->listParam('kind', $kinds);
		$filter->owners = $this->listParam('owner', $owners);
		$filter->layoutsMin = $this->intParam('layoutsMin');
		$filter->layoutsMax = $this->intParam('layoutsMax');
		$filter->unused = $request->getQueryParam('unused') === '1';
		$filter->empty = $request->getQueryParam('empty') === '1';

		return $filter;
	}
}
