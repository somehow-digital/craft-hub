<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\Snapshot;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SectionsController extends BaseController
{
	protected ?Page $page = Page::Sections;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/structure/sections')) !== null) {
			return $redirect;
		}

		$hub = Hub::getInstance();
		/** @var ListService $lists */
		$lists = $hub->lists;
		$months = $hub->filters->getThreshold(CheckKind::Sections, 'stale');
		$all = $hub->structure->getSections();

		// While reviewing the selection, the filters don't apply.
		$selection = $this->selection(ColumnService::LIST_SECTIONS, 'hub/structure/sections');
		/** @var SectionFilter $filter */
		$filter = ($selection['review'] ?? false) ? new SectionFilter() : $this->listFilterFromRequest(CheckKind::Sections);
		$sortColumns = $lists->getSectionSortColumns();
		[$sort, $direction] = $this->sortParams($sortColumns, ListService::SORT_NAME);
		[$pageInfo, $sections] = $this->paginateItems($this->reviewItems($lists->getSections($filter, $sort, $direction), $selection));
		$columns = $hub->columns->getColumns(ColumnService::LIST_SECTIONS);
		$shown = $hub->columns->getShownKeys($columns);

		return $this->renderTemplate('hub/pages/sections/index', [
			'sections' => $sections,
			'pageInfo' => $pageInfo,
			'total' => count($all),
			'activities' => in_array('updated', $shown, true) ? array_map(fn (SectionInfo $info) => $info->stats->getActivity($months), $sections) : [],
			'filter' => $filter,
			'columns' => $columns,
			...$this->savedFilterVariables(CheckKind::Sections, 'hub/structure/sections', $filter->savedFilter),
			...$this->labelVariables(ColumnService::LIST_SECTIONS, array_keys($sections)),
			...$this->bulkVariables(ColumnService::LIST_SECTIONS, $selection, $filter),
			...$this->sortVariables('hub/structure/sections', $sortColumns, ListService::SORT_NAME, $sort, $direction),
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Sections, $this->sectionsSummary());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::Sections);
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$tab = $this->detailTab($tab, [self::TAB_ENTRIES, self::TAB_STATISTICS]);
		$info = Hub::getInstance()->structure->getSection($id) ?? throw new NotFoundHttpException('Section not found.');

		if ($tab === self::TAB_STATISTICS && ($card = $this->renderCard($this->summaryCards($info))) !== null) {
			return $card;
		}

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/sections/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forSection($info),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/structure/sections/$id/entries", section: $info->section),
				self::TAB_STATISTICS => ['summary' => $this->summaryCards($info)],
				self::TAB_DIAGNOSTICS => [],
				default => [
					'entryTypes' => Hub::getInstance()->structure->getEntryTypes(),
					'statistics' => Hub::getInstance()->statistics,
				],
			},
		]);
	}

	/**
	 * @return array<int,array<string,mixed>> see lazyCard()
	 */
	private function summaryCards(SectionInfo $info): array
	{
		$id = $info->section->id;
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->getSiteIds();
		$path = "hub/structure/sections/$id/entries";

		return [
			$this->lazyCard(Craft::t('hub', 'Entries'), 'file-lines', fn () => $this->entriesCard($info->stats, path: $path), $this->entriesHistory(sectionId: $id)),
			$this->lazyCard(Craft::t('hub', 'Entry Types'), 'shapes', function () use ($info, $id, $statistics, $siteIds) {
				$counts = [];
				$handles = [];
				foreach ($info->entryTypes as $entryType) {
					$name = Craft::t('site', $entryType->name);
					$counts[$name] = $statistics->sumEntries($siteIds, $id, $entryType->id)->total;
					$handles[$name] = $entryType->handle;
				}

				$card = $this->topCard(Craft::t('hub', 'Entry Types'), 'shapes', $counts);
				foreach ($card['segments'] as &$segment) {
					if (isset($handles[$segment['label']]) && Hub::getInstance()->canView(Page::Entries)) {
						$segment['url'] = $this->hubUrl('hub/data/entries', ['section' => $info->section->handle, 'type' => $handles[$segment['label']]]);
					}
				}
				unset($segment);

				$withoutEntries = count(array_filter($counts, fn (int $count) => $count === 0));
				if ($withoutEntries > 0) {
					$card['note'] = Craft::t('hub', '{count, plural, =1{One entry type has} other{# entry types have}} no entries here', ['count' => $withoutEntries]);
				}

				return $card;
			}, fn (Snapshot $snapshot) => $this->labeledTopHistory($this->entryTypeLabels(), $this->byId(
				$snapshot->getSections()[$id]['entryTypes'] ?? [],
				fn (int $typeId) => $snapshot->statistics->sumEntries($siteIds, $id, $typeId)->total,
			))),
			$this->lazyCard(Craft::t('hub', 'References'), 'link', function () use ($info, $id, $statistics, $siteIds) {
				$counts = [];
				foreach ([...$info->referenceFields, ...$info->linkFields] as $field) {
					$counts[$field->name] = $statistics->sumReferences($siteIds, $field->id, $id)->references;
				}

				$card = $this->topCard(Craft::t('hub', 'References'), 'link', $counts, sum: true);
				$card['note'] = Craft::t('hub', '{entries, plural, =0{No Entries field} =1{One Entries field} other{# Entries fields}} and {links, plural, =0{no Link field} =1{one Link field} other{# Link fields}} can relate to entries from this section', [
					'entries' => count($info->referenceFields),
					'links' => count($info->linkFields),
				]);

				return $card;
			}, fn (Snapshot $snapshot) => $this->labeledTopHistory($this->fieldLabels(), $this->byId(
				$snapshot->getSections()[$id]['fields'] ?? [],
				fn (int $fieldId) => $snapshot->statistics->sumReferences($siteIds, $fieldId, $id)->references,
			), true)),
			$this->lazyCard(Craft::t('hub', 'Authors'), 'user', fn () => $this->peopleCard(Craft::t('hub', 'Authors'), 'user', Hub::getInstance()->entries->getAuthors($id), 'author', $path, Craft::t('hub', 'Entries'))),
		];
	}
}
