<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Closure;
use Craft;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\services\WorkService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The tabs of the "My Work" page, below the "Hub" navigation item, snoozing what they list.
 */
class WorkController extends BaseController
{
	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) {
			return false;
		}

		if (!Hub::getInstance()->work->canView()) {
			throw new ForbiddenHttpException('User is not authorized to perform this action.');
		}

		return true;
	}

	/**
	 * A tab of the page: the overview, the diagnostics, and the lists of the user's entries and assets, which are Hub's own
	 * lists fixed to them.
	 *
	 * @throws ForbiddenHttpException for the lists of pages the user may not view
	 * @throws NotFoundHttpException for tabs with nothing on them
	 */
	public function actionIndex(string $tab = self::TAB_OVERVIEW): Response
	{
		$hub = Hub::getInstance();
		$work = $hub->work;
		$user = Craft::$app->getUser()->getIdentity() ?? throw new ForbiddenHttpException('User is not authorized to perform this action.');
		$path = WorkService::PATH;

		if (in_array($tab, [self::TAB_ENTRIES, self::TAB_ASSETS], true) && !$hub->access->canView($tab)) {
			throw new ForbiddenHttpException('User is not authorized to perform this action.');
		}

		$sections = $work->getSections();
		$tabs = $this->tabs($sections);

		if (!isset($tabs[$tab])) {
			throw new NotFoundHttpException('Tab not found.');
		}

		if (in_array($tab, [self::TAB_ENTRIES, self::TAB_ASSETS], true) && ($redirect = $this->keepFilters("$path/$tab")) !== null) {
			return $redirect;
		}

		if ($tab === WorkService::SECTION_DIAGNOSTICS && ($panel = $this->renderPanel($this->diagnosticsPanels())) !== null) {
			return $panel;
		}

		$variables = match ($tab) {
			self::TAB_ENTRIES => $this->entryListVariables("$path/entries", author: $user),
			self::TAB_ASSETS => $this->fileListVariables("$path/assets", uploader: $user),
			WorkService::SECTION_DIAGNOSTICS => $this->diagnosticsVariables(),
			default => [
				'diagnostics' => $work->getDiagnostics(),
				'drafts' => $work->getDrafts(),
				'comingUp' => $work->getComingUp(),
				'maintained' => $work->getMaintained(),
			],
		};

		return $this->renderTemplate('hub/pages/work/' . $tab, [
			...$variables,
			'tab' => $tab,
			'workTabs' => $tabs,
			'workSections' => $sections,
			'itemLimit' => WorkService::ITEM_LIMIT,
			'workPath' => $path,
		]);
	}

	/**
	 * Tabs of the page, by their ID: the overview, the diagnostics and the user's items as a whole. Those the user may not
	 * view or that can't hold anything are left out.
	 *
	 * @param array<string,array{label:string,url:string,count:int|null}> $sections see WorkService::getSections()
	 * @return array<string,array{label:string,url:string}>
	 */
	private function tabs(array $sections): array
	{
		$hub = Hub::getInstance();
		$tabs = [self::TAB_OVERVIEW => ['label' => Craft::t('hub', 'Overview'), 'url' => $this->hubUrl(WorkService::PATH)]];

		if (isset($sections[WorkService::SECTION_DIAGNOSTICS])) {
			['label' => $label, 'count' => $count] = $sections[WorkService::SECTION_DIAGNOSTICS];
			$tabs[WorkService::SECTION_DIAGNOSTICS] = [
				'label' => $count ? Craft::t('hub', '{label} ({count, number})', ['label' => $label, 'count' => $count]) : $label,
				'url' => $this->hubUrl(WorkService::PATH . '/' . WorkService::SECTION_DIAGNOSTICS),
			];
		}

		$lists = [
			self::TAB_ENTRIES => Craft::t('hub', 'My entries'),
			self::TAB_ASSETS => Craft::t('hub', 'My assets'),
		];

		foreach ($lists as $list => $label) {
			if ($hub->access->canView($list)) {
				$tabs[$list] = ['label' => $label, 'url' => $this->hubUrl(WorkService::PATH . "/$list")];
			}
		}

		return $tabs;
	}

	/**
	 * Variables of the diagnostics tab: the checks flagging the user's items, narrowed by its filters, and the options of those.
	 *
	 * @return array<string,mixed>
	 */
	private function diagnosticsVariables(): array
	{
		$params = $this->filterParamsOfRequest();
		$all = Hub::getInstance()->work->getDiagnostics();
		$filter = [
			'kinds' => $params->list('kind', [CheckKind::Entries->value, CheckKind::Assets->value]),
			'severities' => $params->list('severity', array_map(fn (Severity $severity) => $severity->value, Severity::cases())),
			'checks' => $params->list('check', array_map(fn (Check $check) => (string)$check->key, $all)),
		];

		$checks = array_filter($all, function (Check $check) use ($filter) {
			return ($filter['kinds'] === [] || in_array(explode('.', (string)$check->key)[0], $filter['kinds'], true))
				&& ($filter['severities'] === [] || in_array($check->severity->value, $filter['severities'], true))
				&& ($filter['checks'] === [] || in_array($check->key, $filter['checks'], true));
		});

		return ['checks' => array_values($checks), 'allChecks' => $all, 'filter' => $filter, 'snoozeDays' => WorkService::SNOOZE_DAYS, 'snoozedCount' => Hub::getInstance()->work->countSnoozed()];
	}

	/**
	 * Panels of the diagnostics tab, with what each lists: the check requested, keyed by its key, with the first of its
	 * items, and what the user snoozed.
	 *
	 * @return array<string,Closure():array{0:string,1:array<string,mixed>}>
	 */
	private function diagnosticsPanels(): array
	{
		$work = Hub::getInstance()->work;
		$key = Craft::$app->getRequest()->getQueryParam(self::PANEL_PARAM);
		$check = fn () => $work->getDiagnostics(WorkService::CHECK_ITEM_LIMIT, (string)$key)[0] ?? throw new NotFoundHttpException('Check not found.');

		return [
			'hub-snoozed' => fn () => ['hub/_panels/work/snoozed', ['snoozed' => $work->getSnoozed()]],
			...(is_string($key) && $key !== 'hub-snoozed' ? [$key => fn () => ['hub/_panels/work/check', ['check' => $check(), 'snoozeDays' => WorkService::SNOOZE_DAYS]]] : []),
		];
	}

	/**
	 * Snoozes an element for a check that applies to it, for the days posted; 0 dismisses it for good.
	 *
	 * @throws BadRequestHttpException for days that aren't offered
	 * @throws NotFoundHttpException for checks and elements that don't exist
	 * @throws ForbiddenHttpException for elements the user may not view
	 */
	public function actionSnooze(): Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		$days = (int)$request->getRequiredBodyParam('days');

		if (!in_array($days, WorkService::SNOOZE_DAYS, true)) {
			throw new BadRequestHttpException('Invalid number of days.');
		}

		$hub = Hub::getInstance();
		$check = $hub->diagnostics->getDefinition((string)$request->getRequiredBodyParam('check'));
		$elementType = $check?->kind->elementType();
		$element = $elementType !== null ? Craft::$app->getElements()->getElementById((int)$request->getRequiredBodyParam('element'), $elementType, '*') : null;

		if ($check === null || $element === null) {
			throw new NotFoundHttpException('Item not found.');
		}

		$this->requireViewable($element);
		$hub->work->snooze($check, (int)$element->id, $days ?: null);

		return $this->asSuccess($days ? Craft::t('hub', 'Snoozed for {days, plural, =1{one day} other{# days}}.', ['days' => $days]) : Craft::t('hub', 'Dismissed.'));
	}

	/**
	 * Lets an element be flagged by a check for the user again.
	 */
	public function actionWake(): Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		Hub::getInstance()->work->wake((string)$request->getRequiredBodyParam('check'), (int)$request->getRequiredBodyParam('element'));

		return $this->asSuccess(Craft::t('hub', 'Back on your list.'));
	}
}
