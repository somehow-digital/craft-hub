<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\controllers;

use Closure;
use craft\fields\ContentBlock;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The fields page, below the "Structure" navigation item: its list, statistics and diagnostics, and the detail pages of
 * fields.
 *
 * @internal
 */
final class FieldsController extends BaseController
{
	/** The path of the list. */
	private const PATH = 'hub/structure/fields';

	protected ?Page $page = Page::Fields;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters(self::PATH)) instanceof Response) {
			return $redirect;
		}

		$hub = Hub::getInstance();
		$lists = $hub->lists;
		$all = $hub->structure->getFields();

		// Field types and layout owners are filtered by their lowercased names.
		$types = [];
		$owners = [];
		foreach ($all as $info) {
			$types[mb_strtolower($info->getTypeName())] = ['label' => $info->getTypeName(), 'icon' => (Fill::text($info->field->getIcon()) ?? 'pen-to-square')];

			foreach ($info->getOwnerTypes() as $owner) {
				$owners[mb_strtolower($owner)] = $owner;
			}
		}

		uasort($types, fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

		// While reviewing the selection, the filters don't apply.
		$selection = Hub::getInstance()->bulkActions->selectionFor(ColumnService::LIST_FIELDS, self::PATH);
		/** @var FieldFilter $filter */
		$filter = $selection->review ? new FieldFilter() : $this->listFilterFromRequest(CheckKind::Fields);
		$sort = $this->listSort($lists->getFieldSortColumns(), ListService::SORT_NAME);
		[$pageInfo, $fields] = $this->paginator()->items($selection->limitItems($lists->getFields($filter, $sort->column, $sort->direction)));
		$columns = $hub->columns->getColumns(ColumnService::LIST_FIELDS);
		$shown = $hub->columns->getShownKeys($columns);

		return $this->renderTemplate('hub/pages/fields/index', [
			'list' => [
				'fields' => $fields,
				'pageInfo' => $pageInfo,
				'total' => count($all),
				'types' => $types,
				'owners' => $owners,
				'data' => in_array('data', $shown, true) ? array_map($lists->getFieldData(...), $fields) : [],
				'filter' => $filter,
				'columns' => $columns,
				...$this->savedFilterVariables(CheckKind::Fields, self::PATH, $filter->savedFilter),
				...$this->labelVariables(ColumnService::LIST_FIELDS, array_keys($fields)),
				...Hub::getInstance()->selection->variables($selection, $filter),
				...$sort->variables(self::PATH),
			],
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Fields, Hub::getInstance()->fieldsSummary->index());
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

		if ($tab === self::TAB_ENTRIES && ($redirect = $this->keepFilters("hub/structure/fields/{$id}/entries")) instanceof Response) {
			return $redirect;
		}

		// The cards load their data once their panel is opened, so building them all is cheap (see renderCard()).
		$summary = $tab === self::TAB_STATISTICS ? Hub::getInstance()->fieldsSummary->detail($info) : [];

		// A card or panel requested on its own is rendered without the page around it.
		$part = ($tab === self::TAB_STATISTICS ? $this->renderCard($summary) : null)
			?? $this->renderPanel($tab === self::TAB_DIAGNOSTICS ? $this->checksPanel(fn() => Hub::getInstance()->checks->forField($info)) : $this->overviewPanels($info));

		if ($part instanceof Response) {
			return $part;
		}

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/fields/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forField($info),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/structure/fields/{$id}/entries", field: $info->field),
				self::TAB_STATISTICS => ['summary' => $summary],
				self::TAB_DIAGNOSTICS => [],
				default => ['volumes' => Hub::getInstance()->volumes->getVolumesOf($info->field)],
			},
		]);
	}

	/**
	 * Panels of the overview tab of a field page, with what each lists.
	 *
	 * @return array<string,Closure():array{0:string,1:array<string,mixed>}>
	 */
	private function overviewPanels(FieldInfo $info): array
	{
		$hub = Hub::getInstance();
		$field = $info->field;

		return [
			'maintainers' => fn(): array => ['hub/_panels/maintainers', ['list' => ColumnService::LIST_FIELDS, 'item' => $field]],
			'entryTypes' => fn(): array => ['hub/_panels/fields/entry-types', ['info' => $info, 'field' => $field, 'entryTypes' => $hub->structure->getEntryTypes(), 'statistics' => $hub->statistics]],
			'sections' => fn(): array => ['hub/_panels/fields/sections', ['info' => $info, 'field' => $field, 'statistics' => $hub->statistics]],
			'volumes' => fn(): array => ['hub/_panels/fields/volumes', ['field' => $field, 'volumes' => $hub->volumes->getVolumesOf($field), 'statistics' => $hub->statistics]],
			'relations' => fn(): array => ['hub/_panels/fields/relations', ['info' => $info]],
			...($field instanceof ContentBlock ? ['fieldLayout' => fn(): array => ['hub/_panels/field-layout', ['layout' => $field->getFieldLayout(), 'fields' => $hub->structure->getFields()]]] : []),
			'activity' => fn(): array => ['hub/_panels/activity', ['subject' => ActivitySubject::Field->value, 'id' => $field->id]],
		];
	}
}
