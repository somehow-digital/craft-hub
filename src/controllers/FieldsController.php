<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\models\EntryType;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FieldsController extends BaseController
{
	public function actionIndex(): Response
	{
		$hub = Hub::getInstance();
		/** @var ListService $lists */
		$lists = $hub->lists;
		$all = $hub->structure->getFields();

		// Field types and layout owners are filtered by their lowercased names.
		$types = [];
		$owners = [];
		foreach ($all as $info) {
			$types[mb_strtolower($info->getTypeName())] = $info->getTypeName();

			foreach ($info->getOwnerTypes() as $owner) {
				$owners[mb_strtolower($owner)] = $owner;
			}
		}
		natcasesort($types);
		$kinds = array_map(fn (DataKind $kind) => $kind->value, DataKind::cases());

		$filter = $this->filterFromRequest(array_keys($types), $kinds, array_keys($owners));
		$sortColumns = $lists->getFieldSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		$fields = $lists->getFields($filter, $sort, $direction);

		return $this->renderTemplate('hub/pages/fields/index', [
			'fields' => $fields,
			'total' => count($all),
			'types' => $types,
			'owners' => $owners,
			'data' => array_map(fn (FieldInfo $info) => $lists->getFieldData($info), $fields),
			'diagnostics' => array_map(fn (FieldInfo $info) => $hub->checks->forField($info), $fields),
			'filter' => $filter,
			'counts' => [
				'types' => $lists->getFieldCounts(array_keys($types), fn (FieldFilter $filter, string $type) => $filter->types = [$type]),
				'kinds' => $lists->getFieldCounts($kinds, fn (FieldFilter $filter, string $kind) => $filter->kinds = [$kind]),
				'owners' => $lists->getFieldCounts(array_keys($owners), fn (FieldFilter $filter, string $owner) => $filter->owners = [$owner]),
				'diagnoses' => $lists->getFieldCounts(CheckService::getDiagnoses(), fn (FieldFilter $filter, string $diagnosis) => $filter->diagnoses = [$diagnosis]),
			],
			...$this->sortVariables('hub/entries/fields', $sortColumns, ListService::SORT_NAME, $sort, $direction),
			'summary' => $this->fieldsSummary($all),
		]);
	}

	public function actionView(int $id): Response
	{
		$structure = Hub::getInstance()->structure;
		$info = $structure->getField($id) ?? throw new NotFoundHttpException('Field not found.');
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
				'label' => Craft::t('hub', 'Relations'),
				'icon' => DataKind::Relational->icon(),
				'url' => null,
				'total' => $info->relations->relations,
				'segments' => [],
				'note' => Craft::t('hub', '{sources, number} source elements · {targets, number} referenced elements', [
					'sources' => $info->relations->sources,
					'targets' => $info->relations->targets,
				]),
			];
		}

		return $this->renderTemplate('hub/pages/fields/view', [
			'info' => $info,
			'summary' => $summary,
			'entryTypes' => $structure->getEntryTypes(),
			'fields' => $structure->getFields(),
			'statistics' => Hub::getInstance()->statistics,
			'checks' => Hub::getInstance()->checks->forField($info),
		]);
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
		$filter->diagnoses = $this->listParam('diagnosis', CheckService::getDiagnoses());

		return $filter;
	}
}
