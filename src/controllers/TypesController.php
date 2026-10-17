<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class TypesController extends BaseController
{
	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/structure/entry-types')) !== null) {
			return $redirect;
		}

		$hub = Hub::getInstance();
		/** @var ListService $lists */
		$lists = $hub->lists;
		$months = $hub->settings->staleThresholdMonths;
		$all = $hub->structure->getEntryTypes();
		$kinds = array_map(fn (UsageKind $kind) => $kind->value, UsageKind::listed());

		$filter = $this->filterFromRequest($kinds);
		$sortColumns = $lists->getEntryTypeSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		[$pageInfo, $entryTypes] = $this->paginateItems($lists->getEntryTypes($filter, $sort, $direction));
		$columns = $hub->columns->getColumns(ColumnService::LIST_ENTRY_TYPES);
		$shown = $hub->columns->getShownKeys($columns);

		return $this->renderTemplate('hub/pages/types/index', [
			'entryTypes' => $entryTypes,
			'pageInfo' => $pageInfo,
			'total' => count($all),
			'kinds' => UsageKind::listed(),
			'activities' => in_array('updated', $shown, true) ? array_map(fn (EntryTypeInfo $info) => $info->stats->getActivity($months), $entryTypes) : [],
			'diagnostics' => in_array('diagnostics', $shown, true) ? array_map(fn (EntryTypeInfo $info) => $hub->checks->forEntryType($info), $entryTypes) : [],
			'filter' => $filter,
			'columns' => $columns,
			...$this->sortVariables('hub/structure/entry-types', $sortColumns, ListService::SORT_NAME, $sort, $direction),
			'summary' => $this->entryTypesSummary($all),
		]);
	}

	public function actionDiagnostics(): Response
	{
		$hub = Hub::getInstance();
		$entryTypes = $hub->structure->getEntryTypes();
		$path = 'hub/structure/entry-types';

		return $this->renderDiagnostics('structure', 'entry-types', Craft::t('hub', 'Entry Types'), $this->diagnosticsCard(
			Craft::t('hub', 'Checks'),
			'shapes',
			$path,
			array_map(fn (EntryTypeInfo $info) => $hub->checks->forEntryType($info), $entryTypes),
		), [
			[
				'label' => Craft::t('hub', 'Unused entry types'),
				'description' => Craft::t('hub', 'Not assigned to any section or nested entry field.'),
				'icon' => 'ghost',
				'severity' => Severity::Error,
				'value' => count(array_filter($entryTypes, fn (EntryTypeInfo $info) => $info->hasKind(UsageKind::Unused))),
				'url' => $this->hubUrl($path, ['kind' => UsageKind::Unused->value]),
			],
			[
				'label' => Craft::t('hub', 'Empty entry types'),
				'description' => Craft::t('hub', 'Assigned somewhere, but no entries of this type exist.'),
				'icon' => 'box-open',
				'severity' => Severity::Warning,
				'value' => count(array_filter($entryTypes, fn (EntryTypeInfo $info) => $info->hasKind(UsageKind::Empty))),
				'url' => $this->hubUrl($path, ['kind' => UsageKind::Empty->value]),
			],
		]);
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$info = Hub::getInstance()->structure->getEntryType($id) ?? throw new NotFoundHttpException('Entry type not found.');
		// Entry types used by no section or field have no entries to list.
		$tab = $this->detailTab($tab, $info->sections !== [] || $info->nestedFields !== [] ? [self::TAB_ENTRIES] : []);

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/types/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forEntryType($info),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/structure/entry-types/$id/entries", entryType: $info->entryType),
				// Nested entries are never referenced on their own, so entry types used by no section only list the stale entries.
				self::TAB_DIAGNOSTICS => $this->reviewListVariables(typeId: $id, unreferenced: $info->sections !== []),
				default => $this->overviewVariables($info),
			},
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function overviewVariables(EntryTypeInfo $info): array
	{
		$fields = Hub::getInstance()->structure->getFields();

		$kinds = [];
		foreach ($info->entryType->getFieldLayout()->getCustomFields() as $field) {
			$kind = isset($fields[$field->id]) ? $fields[$field->id]->getDataKind() : DataKind::Plain;
			$kinds[$kind->value] = ($kinds[$kind->value] ?? 0) + 1;
		}

		return [
			'summary' => [
				$this->entriesCard($info->stats),
				[
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
				],
				[
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
				],
			],
			'fields' => $fields,
			'statistics' => Hub::getInstance()->statistics,
		];
	}

	/**
	 * Filters arrive as query params, like those of the other lists.
	 *
	 * @param string[] $kinds usage kinds that can be filtered by
	 */
	private function filterFromRequest(array $kinds): EntryTypeFilter
	{
		$request = Craft::$app->getRequest();

		$filter = new EntryTypeFilter();
		$filter->search = trim((string)$request->getQueryParam('q', ''));
		$filter->kinds = $this->listParam('kind', $kinds);
		$filter->entriesMin = $this->intParam('entriesMin');
		$filter->entriesMax = $this->intParam('entriesMax');
		$filter->relationsMin = $this->intParam('relationsMin');
		$filter->relationsMax = $this->intParam('relationsMax');
		$filter->activities = $this->listParam('activity', EntryStatistics::getActivities());
		$filter->diagnoses = $this->listParam('diagnosis', CheckService::getDiagnoses());

		return $filter;
	}
}
