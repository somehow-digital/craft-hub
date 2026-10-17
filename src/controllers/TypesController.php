<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class TypesController extends BaseController
{
	public function actionIndex(): Response
	{
		$hub = Hub::getInstance();
		/** @var ListService $lists */
		$lists = $hub->lists;
		$months = $hub->settings->staleThresholdMonths;
		$all = $hub->structure->getEntryTypes();
		$kinds = array_map(fn (UsageKind $kind) => $kind->value, UsageKind::listed());

		$filter = $this->filterFromRequest($kinds);
		$sortColumns = $lists->getEntryTypeSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		$entryTypes = $lists->getEntryTypes($filter, $sort, $direction);

		return $this->renderTemplate('hub/pages/types/index', [
			'entryTypes' => $entryTypes,
			'total' => count($all),
			'kinds' => UsageKind::listed(),
			'activities' => array_map(fn (EntryTypeInfo $info) => $info->stats->getActivity($months), $entryTypes),
			'diagnostics' => array_map(fn (EntryTypeInfo $info) => $hub->checks->forEntryType($info), $entryTypes),
			'filter' => $filter,
			'counts' => [
				'kinds' => $lists->getEntryTypeCounts($kinds, fn (EntryTypeFilter $filter, string $kind) => $filter->kinds = [$kind]),
				'activities' => $lists->getEntryTypeCounts(EntryStatistics::getActivities(), fn (EntryTypeFilter $filter, string $activity) => $filter->activities = [$activity]),
				'diagnoses' => $lists->getEntryTypeCounts(CheckService::getDiagnoses(), fn (EntryTypeFilter $filter, string $diagnosis) => $filter->diagnoses = [$diagnosis]),
			],
			...$this->sortVariables('hub/entries/types', $sortColumns, ListService::SORT_NAME, $sort, $direction),
			'summary' => $this->entryTypesSummary($all),
		]);
	}

	public function actionView(int $id): Response
	{
		$structure = Hub::getInstance()->structure;
		$info = $structure->getEntryType($id) ?? throw new NotFoundHttpException('Entry type not found.');
		$fields = $structure->getFields();

		$kinds = [];
		foreach ($info->entryType->getFieldLayout()->getCustomFields() as $field) {
			$kind = isset($fields[$field->id]) ? $fields[$field->id]->getDataKind() : DataKind::Plain;
			$kinds[$kind->value] = ($kinds[$kind->value] ?? 0) + 1;
		}

		return $this->renderTemplate('hub/pages/types/view', [
			// Nested entries aren't listed, so entry types used by no section have no list.
			...($info->sections !== [] ? $this->entryListVariables('hub/entries/types/' . $id, entryType: $info->entryType) : []),
			'info' => $info,
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
			'checks' => Hub::getInstance()->checks->forEntryType($info),
			...$this->reviewListVariables(typeId: $id),
		]);
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
