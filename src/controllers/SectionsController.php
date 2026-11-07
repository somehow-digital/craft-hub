<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\controllers;

use Closure;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\ListSelection;
use SomehowDigital\Craft\Hub\helpers\ListSort;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\statistics\SectionsSummary;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The sections page, below the "Structure" navigation item: its list, statistics and diagnostics, and the detail pages
 * of sections.
 */
class SectionsController extends BaseController
{
	/** The path of the list. */
	private const PATH = 'hub/structure/sections';

	protected ?Page $page = Page::Sections;

	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters(self::PATH)) instanceof Response) {
			return $redirect;
		}

		$hub = Hub::getInstance();
		$lists = $hub->lists;
		$months = $hub->filters->getThreshold(CheckKind::Sections, 'stale');
		$all = $hub->structure->getSections();

		// While reviewing the selection, the filters don't apply.
		$selection = ListSelection::fromRequest(ColumnService::LIST_SECTIONS, self::PATH);
		/** @var SectionFilter $filter */
		$filter = $selection->review ? new SectionFilter() : $this->listFilterFromRequest(CheckKind::Sections);
		$sort = ListSort::fromRequest($lists->getSectionSortColumns(), ListService::SORT_NAME);
		[$pageInfo, $sections] = Paginator::fromRequest()->items($selection->limitItems($lists->getSections($filter, $sort->column, $sort->direction)));
		$columns = $hub->columns->getColumns(ColumnService::LIST_SECTIONS);
		$shown = $hub->columns->getShownKeys($columns);

		return $this->renderTemplate('hub/pages/sections/index', [
			'list' => [
				'sections' => $sections,
				'pageInfo' => $pageInfo,
				'total' => count($all),
				'activities' => in_array('updated', $shown, true) ? array_map(fn(SectionInfo $info): string => $info->stats->getActivity($months), $sections) : [],
				'filter' => $filter,
				'columns' => $columns,
				...$this->savedFilterVariables(CheckKind::Sections, self::PATH, $filter->savedFilter),
				...$this->labelVariables(ColumnService::LIST_SECTIONS, array_keys($sections)),
				...$selection->variables($filter),
				...$sort->variables(self::PATH),
			],
		]);
	}

	public function actionStatistics(): Response
	{
		return $this->renderStatistics(CheckKind::Sections, (new SectionsSummary())->index());
	}

	public function actionDiagnostics(): Response
	{
		return $this->renderDiagnostics(CheckKind::Sections);
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$tab = $this->detailTab($tab, [self::TAB_ENTRIES, self::TAB_STATISTICS]);
		$info = Hub::getInstance()->structure->getSection($id) ?? throw new NotFoundHttpException('Section not found.');

		if ($tab === self::TAB_ENTRIES && ($redirect = $this->keepFilters("hub/structure/sections/{$id}/entries")) instanceof Response) {
			return $redirect;
		}

		// The cards load their data once their panel is opened, so building them all is cheap (see renderCard()).
		$summary = $tab === self::TAB_STATISTICS ? (new SectionsSummary())->detail($info) : [];

		// A card or panel requested on its own is rendered without the page around it.
		$part = ($tab === self::TAB_STATISTICS ? $this->renderCard($summary) : null)
			?? $this->renderPanel($tab === self::TAB_DIAGNOSTICS ? $this->checksPanel(fn() => Hub::getInstance()->checks->forSection($info)) : $this->overviewPanels($info));

		if ($part instanceof Response) {
			return $part;
		}

		// Only the selected tab is rendered, so only its variables are computed; the checks are counted on every tab.
		return $this->renderTemplate('hub/pages/sections/view', [
			'info' => $info,
			'selectedTab' => $tab,
			'checks' => Hub::getInstance()->checks->forSection($info),
			...match ($tab) {
				self::TAB_ENTRIES => $this->entryListVariables("hub/structure/sections/{$id}/entries", section: $info->section),
				self::TAB_STATISTICS => ['summary' => $summary],
				self::TAB_DIAGNOSTICS => [],
				default => [],
			},
		]);
	}

	/**
	 * Panels of the overview tab of a section page, with what each lists.
	 *
	 * @return array<string,Closure():array{0:string,1:array<string,mixed>}>
	 */
	private function overviewPanels(SectionInfo $info): array
	{
		$hub = Hub::getInstance();
		$section = $info->section;

		return [
			'maintainers' => fn(): array => ['hub/_panels/maintainers', ['list' => ColumnService::LIST_SECTIONS, 'item' => $section]],
			'sites' => fn(): array => ['hub/_panels/sections/sites', ['section' => $section]],
			'previewTargets' => fn(): array => ['hub/_panels/sections/preview-targets', ['section' => $section]],
			'entryTypes' => fn(): array => ['hub/_panels/sections/entry-types', ['info' => $info, 'section' => $section, 'entryTypes' => $hub->structure->getEntryTypes(), 'statistics' => $hub->statistics]],
			'relations' => fn(): array => ['hub/_panels/sections/relations', ['info' => $info, 'section' => $section, 'statistics' => $hub->statistics]],
			'activity' => fn(): array => ['hub/_panels/activity', ['subject' => ActivitySubject::Section->value, 'id' => $section->id]],
		];
	}
}
