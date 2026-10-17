<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\SectionType;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SectionsController extends BaseController
{
	public function actionIndex(): Response
	{
		$hub = Hub::getInstance();
		/** @var ListService $lists */
		$lists = $hub->lists;
		$months = $hub->settings->staleThresholdMonths;
		$all = $hub->structure->getSections();

		$filter = $this->filterFromRequest();
		$sortColumns = $lists->getSectionSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		$sections = $lists->getSections($filter, $sort, $direction);

		return $this->renderTemplate('hub/pages/sections/index', [
			'sections' => $sections,
			'total' => count($all),
			'activities' => array_map(fn (SectionInfo $info) => $info->stats->getActivity($months), $sections),
			'diagnostics' => array_map(fn (SectionInfo $info) => $hub->checks->forSection($info), $sections),
			'filter' => $filter,
			'counts' => [
				'types' => $lists->getSectionCounts(array_map(fn (SectionType $type) => $type->value, SectionType::cases()), fn (SectionFilter $filter, string $type) => $filter->types = [$type]),
				'urls' => $lists->getSectionCounts([ListService::URLS_YES, ListService::URLS_NO], fn (SectionFilter $filter, string $urls) => $filter->urls = $urls),
				'activities' => $lists->getSectionCounts(EntryStatistics::getActivities(), fn (SectionFilter $filter, string $activity) => $filter->activities = [$activity]),
				'diagnoses' => $lists->getSectionCounts(CheckService::getDiagnoses(), fn (SectionFilter $filter, string $diagnosis) => $filter->diagnoses = [$diagnosis]),
			],
			...$this->sortVariables('hub/entries/sections', $sortColumns, ListService::SORT_NAME, $sort, $direction),
			'summary' => $this->sectionsSummary($all),
		]);
	}

	public function actionView(int $id): Response
	{
		$structure = Hub::getInstance()->structure;
		$info = $structure->getSection($id) ?? throw new NotFoundHttpException('Section not found.');
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->getSiteIds();

		$typeCounts = [];
		$typeHandles = [];
		foreach ($info->entryTypes as $entryType) {
			$name = Craft::t('site', $entryType->name);
			$typeCounts[$name] = $statistics->sumEntries($siteIds, $id, $entryType->id)->total;
			$typeHandles[$name] = $entryType->handle;
		}

		$entryTypes = $this->topCard(Craft::t('hub', 'Entry Types'), 'shapes', $typeCounts);
		foreach ($entryTypes['segments'] as &$segment) {
			if (isset($typeHandles[$segment['label']])) {
				$segment['url'] = $this->hubUrl('hub/entries/entries', ['section' => $info->section->handle, 'type' => $typeHandles[$segment['label']]]);
			}
		}
		unset($segment);

		$typesWithoutEntries = count(array_filter($typeCounts, fn (int $count) => $count === 0));
		if ($typesWithoutEntries > 0) {
			$entryTypes['note'] = Craft::t('hub', '{count, plural, =1{One entry type has} other{# entry types have}} no entries here', ['count' => $typesWithoutEntries]);
		}

		$relations = [];
		foreach ([...$info->referenceFields, ...$info->linkFields] as $field) {
			$relations[$field->name] = $statistics->sumRelations($siteIds, $field->id, $id)->relations;
		}

		$references = $this->topCard(Craft::t('hub', 'Relations'), 'link', $relations, sum: true);
		$references['note'] = Craft::t('hub', '{entries, plural, =0{No Entries field} =1{One Entries field} other{# Entries fields}} and {links, plural, =0{no Link field} =1{one Link field} other{# Link fields}} can relate to entries from this section', [
			'entries' => count($info->referenceFields),
			'links' => count($info->linkFields),
		]);

		return $this->renderTemplate('hub/pages/sections/view', [
			...$this->entryListVariables('hub/entries/sections/' . $id, section: $info->section),
			'info' => $info,
			'summary' => [
				$this->entriesCard($info->stats),
				$entryTypes,
				$references,
			],
			'entryTypes' => $structure->getEntryTypes(),
			'statistics' => Hub::getInstance()->statistics,
			'checks' => Hub::getInstance()->checks->forSection($info),
			...$this->reviewListVariables(sectionId: $id),
		]);
	}

	/**
	 * Filters arrive as query params, like those of the other lists.
	 */
	private function filterFromRequest(): SectionFilter
	{
		$request = Craft::$app->getRequest();
		$urls = $request->getQueryParam('urls');

		$filter = new SectionFilter();
		$filter->search = trim((string)$request->getQueryParam('q', ''));
		$filter->types = $this->listParam('type', array_map(fn (SectionType $type) => $type->value, SectionType::cases()));
		$filter->urls = in_array($urls, [ListService::URLS_YES, ListService::URLS_NO], true) ? $urls : null;
		$filter->entriesMin = $this->intParam('entriesMin');
		$filter->entriesMax = $this->intParam('entriesMax');
		$filter->relationsMin = $this->intParam('relationsMin');
		$filter->relationsMax = $this->intParam('relationsMax');
		$filter->activities = $this->listParam('activity', EntryStatistics::getActivities());
		$filter->empty = $request->getQueryParam('empty') === '1';
		$filter->diagnoses = $this->listParam('diagnosis', CheckService::getDiagnoses());

		return $filter;
	}
}
