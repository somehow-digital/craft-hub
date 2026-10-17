<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Closure;
use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\web\Controller;
use craft\web\View;
use SomehowDigital\Craft\Hub\bulk\BulkPreview;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\FilterParams;
use SomehowDigital\Craft\Hub\helpers\ListSelection;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\services\SnapshotService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Base of Hub's controllers, requiring access to the plugin and, for those of a page, to the page, and rendering what
 * the pages have in common: their tabs, lists, statistics and diagnostics.
 */
abstract class BaseController extends Controller
{
	use ListVariables;

	/** tabs of the detail pages, each on its own URL, the overview on that of the page itself */
	public const TAB_OVERVIEW = 'overview';
	public const TAB_STATISTICS = 'statistics';
	public const TAB_DIAGNOSTICS = 'diagnostics';
	public const TAB_ENTRIES = 'entries';
	public const TAB_ASSETS = 'assets';
	public const TAB_USERS = 'users';
	public const TAB_TRANSFORMS = 'transforms';
	public const TAB_HISTORY = 'history';

	/** header of the requests loading a list in place, as its filters change or the site scope does */
	public const FILTERS_HEADER = 'X-Hub-Filters';

	/** sent by requests only reading the results of a list, swapped in below its filters, so their options aren't needed */
	public const RESULTS_HEADER = 'X-Hub-Results';

	/** query param of a statistics tab loading the card of that key alone, see Summary::lazyCard() */
	public const CARD_PARAM = 'card';

	/** query param of a card of a statistics tab with the days its chart spans, see SnapshotService::chart() */
	public const RANGE_PARAM = 'range';

	protected array|int|bool $allowAnonymous = false;

	/** page of the controller (see Page), which the user must be able to view; controllers without one check their actions */
	protected ?Page $page = null;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) {
			return false;
		}

		$this->requireCpRequest();

		// Craft only requires it of requests by the plugin's URLs, not of its actions.
		$this->requirePermission('accessPlugin-' . Hub::getInstance()->id);

		if ($this->page !== null && !Hub::getInstance()->access->canView($this->page)) {
			throw new ForbiddenHttpException('User is not authorized to perform this action.');
		}

		// The diagnostics tabs of the index pages are only there while diagnostics are enabled.
		if ($action->id === 'diagnostics' && $this->page !== null && !Hub::getInstance()->settings->enableDiagnostics) {
			throw new NotFoundHttpException('Diagnostics are disabled.');
		}

		// The statistics tabs of the index pages are only there while statistics are enabled; the tab of the settings page
		// of the same name isn't one of them, as it enables them again.
		if ($action->id === 'statistics' && $this->page !== null && !Hub::getInstance()->settings->enableStatistics) {
			throw new NotFoundHttpException('Statistics are disabled.');
		}

		// The trash tabs of the index pages are only there while the trash is enabled, for users who may view it.
		if ($action->id === 'trash' && $this->page !== null) {
			if (!Hub::getInstance()->settings->enableTrash) {
				throw new NotFoundHttpException('The trash is disabled.');
			}

			if (!Hub::getInstance()->access->canViewTrash($this->page)) {
				throw new ForbiddenHttpException('User is not authorized to perform this action.');
			}
		}

		return true;
	}

	/**
	 * Requires the current user to be able to view the element, as Craft lets them.
	 *
	 * @throws ForbiddenHttpException
	 */
	protected function requireViewable(ElementInterface $element): void
	{
		if (!Craft::$app->getElements()->canView($element)) {
			throw new ForbiddenHttpException('User is not authorized to view this element.');
		}
	}

	/**
	 * The path of the page a list is on, as posted along with its bulk actions, or null unless it's one of Hub's.
	 */
	protected function postedListPath(): ?string
	{
		$path = Craft::$app->getRequest()->getBodyParam('path');

		return is_string($path) && preg_match('#^hub/[a-z0-9/-]+$#', $path) === 1 ? $path : null;
	}

	/**
	 * The path of the page a list is on, which requests changing its selection must post.
	 *
	 * @throws BadRequestHttpException
	 */
	protected function requirePostedListPath(): string
	{
		return $this->postedListPath() ?? throw new BadRequestHttpException('Invalid list path.');
	}

	/**
	 * Adds the site scope and whether diagnostics and statistics are enabled to every Hub template.
	 *
	 * @param array<string,mixed> $variables
	 */
	public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
	{
		$hub = Hub::getInstance();

		return parent::renderTemplate($template, [
			'selectedSites' => $hub->siteScope->getSites(),
			'hubSite' => $hub->siteScope->getSiteParam(),
			'siteIds' => $hub->siteScope->getSiteIds(),
			'diagnosticsEnabled' => $hub->settings->enableDiagnostics,
			'statisticsEnabled' => $hub->settings->enableStatistics,
			...$variables,
		], $templateMode);
	}

	/**
	 * CP URL to a Hub page that keeps the selected sites.
	 *
	 * @param array<string,mixed> $params
	 */
	protected function hubUrl(string $path, array $params = []): string
	{
		return Hub::getInstance()->siteScope->pageUrl($path, $params);
	}

	/**
	 * Tabs of the entries and assets index pages between their list and their statistics: the trash, while it's enabled,
	 * for users who may view it.
	 *
	 * @return array<string,string>
	 */
	protected function trashTabs(): array
	{
		return $this->page !== null && Hub::getInstance()->access->canViewTrash($this->page) ? ['trash' => Craft::t('hub', 'Trash')] : [];
	}

	/**
	 * Variables of the tabs of the settings page that edit the plugin's settings, for admins: the settings, those that failed
	 * to save passed back, the names of the ones overridden by `config/hub.php`, and whether they're read-only, as they're
	 * kept in the project config; none for other users, who may not change them.
	 *
	 * @return array<string,mixed>
	 */
	protected function settingsVariables(?Settings $settings = null): array
	{
		if (!Craft::$app->getUser()->getIsAdmin()) {
			return [];
		}

		$hub = Hub::getInstance();

		return [
			'settings' => $settings ?? $hub->settings,
			'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile($hub->handle)),
			'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
		];
	}

	/**
	 * Tab of a detail page selected by its URL, between the overview and the diagnostics, which are only there while enabled,
	 * as are the statistics.
	 *
	 * @param string[] $tabs tabs the page has besides those, like one listing its entries or the statistics
	 * @throws NotFoundHttpException for tabs the page doesn't have
	 */
	protected function detailTab(string $tab, array $tabs = []): string
	{
		if ($tab === self::TAB_DIAGNOSTICS && !Hub::getInstance()->settings->enableDiagnostics) {
			throw new NotFoundHttpException('Diagnostics are disabled.');
		}

		if ($tab === self::TAB_STATISTICS && !Hub::getInstance()->settings->enableStatistics) {
			throw new NotFoundHttpException('Statistics are disabled.');
		}

		if (!in_array($tab, [self::TAB_OVERVIEW, ...$tabs, self::TAB_DIAGNOSTICS], true)) {
			throw new NotFoundHttpException('Tab not found.');
		}

		// The tabs listing entries or assets are those of their pages, which the user may not view.
		if (in_array($tab, [self::TAB_ENTRIES, self::TAB_ASSETS], true) && !Hub::getInstance()->access->canView($tab)) {
			throw new ForbiddenHttpException('User is not authorized to perform this action.');
		}

		return $tab;
	}

	/**
	 * Keeps the filters and sorting of a list page in the session, so coming back to it shows them again.
	 * Requests with query params keep theirs, as do the requests loading the list in place, which leave out empty filters;
	 * other requests without any, like those of the nav, are redirected to the kept ones.
	 * Reviewing the selection ignores the filters, so they are kept as they are.
	 *
	 * @return Response|null the redirect to the kept filters, if any
	 */
	protected function keepFilters(string $path): ?Response
	{
		$request = Craft::$app->getRequest();

		if (ListSelection::isRequested()) {
			return null;
		}

		$session = Craft::$app->getSession();
		$key = "hub:filters:$path";
		$params = FilterParams::listParams($request->getQueryParamsWithoutPath());

		if ($params !== [] || $request->getHeaders()->has(self::FILTERS_HEADER)) {
			$params !== [] ? $session->set($key, $params) : $session->remove($key);

			return null;
		}

		// Kept params are filtered again, as redirecting without any would redirect again.
		$kept = FilterParams::listParams($session->get($key));

		return $kept !== [] && $request->getPageNum() === 1 ? $this->redirect($this->hubUrl($path, $kept)) : null;
	}

	/**
	 * Config of a condition posted by its builder, as a condition of the kind with the rules posted, whatever else was
	 * posted along; null without any rule.
	 *
	 * @return array<string,mixed>|null
	 */
	protected function conditionConfig(CheckKind $kind, mixed $posted): ?array
	{
		$rules = is_array($posted) && is_array($posted['conditionRules'] ?? null) ? $posted['conditionRules'] : [];
		$config = $kind->createCondition(['conditionRules' => $rules])->getConfig();

		return ($config['conditionRules'] ?? []) !== [] ? $config : null;
	}

	/**
	 * Responds with a preview of a bulk action, rendered by its component, with the number of the items it applies to, and
	 * the number of those selected that it leaves out.
	 */
	protected function renderPreview(BulkPreview $preview, int $selected): Response
	{
		return $this->asJson([
			'total' => $preview->total,
			'html' => $this->getView()->renderTemplate("hub/_components/$preview->component", [
				'total' => $preview->total,
				'skipped' => $selected - $preview->total,
				...$preview->variables,
			], View::TEMPLATE_MODE_CP),
		]);
	}

	/**
	 * Panel of the card of a statistics tab requested by its key (see Summary::lazyCard()), with its data loaded.
	 *
	 * @param array<int,array{key:string,label:string,icon:string,load:Closure,history?:Closure}> $cards
	 * @return Response|null null unless a card is requested
	 * @throws NotFoundHttpException for cards the tab doesn't have
	 */
	protected function renderCard(array $cards): ?Response
	{
		$key = Craft::$app->getRequest()->getQueryParam(self::CARD_PARAM);

		if (!is_string($key)) {
			return null;
		}

		foreach ($cards as $card) {
			if ($card['key'] === $key) {
				$data = ($card['load'])();

				if (isset($card['history'], $data['total'])) {
					$range = (int)Craft::$app->getRequest()->getQueryParam(self::RANGE_PARAM, SnapshotService::RANGES[0]);
					$data['chart'] = Hub::getInstance()->snapshots->chart($card['history'], [...$card, ...$data], $range);
				}

				return $this->renderTemplate('hub/_components/statistics-card', ['card' => [...$card, ...$data]]);
			}
		}

		throw new NotFoundHttpException('Card not found.');
	}

	/**
	 * Statistics tab of an index page: the summary cards of its items, or the one requested (see renderCard()).
	 *
	 * @param array<int,array{key:string,label:string,icon:string,load:Closure,history?:Closure}> $cards
	 * @param bool $siteScoped whether the elements are scoped to the selected sites, which users aren't
	 * @param array<string,string> $extraTabs labels of the tabs between the list and the statistics, by their path segment
	 */
	protected function renderStatistics(CheckKind $kind, array $cards, bool $siteScoped = true, array $extraTabs = []): Response
	{
		if (($card = $this->renderCard($cards)) !== null) {
			return $card;
		}

		[, $area, $page] = explode('/', $kind->path());

		return $this->renderTemplate('hub/pages/statistics', [
			'area' => $area,
			'page' => $page,
			'title' => $kind->label(),
			'cards' => $cards,
			'siteScoped' => $siteScoped,
			'extraTabs' => $extraTabs,
		]);
	}

	/**
	 * Diagnostics tab of an index page: each enabled check of the kind with the number of items it flags, linking to the
	 * list showing them, as its filter does; failed checks first, the most urgent first, see DiagnosticsService::sort().
	 *
	 * @param bool $siteScoped whether the elements are scoped to the selected sites, which users aren't
	 * @param array<string,string> $extraTabs labels of the tabs between the list and the statistics, by their path segment
	 */
	protected function renderDiagnostics(CheckKind $kind, bool $siteScoped = true, array $extraTabs = []): Response
	{
		$diagnostics = Hub::getInstance()->diagnostics;
		[, $area, $page] = explode('/', $kind->path());
		$tasks = [];

		foreach ($diagnostics->getEnabledChecks($kind) as $definition) {
			$tasks[] = [
				'key' => $definition->getKey(),
				'label' => $definition->getListName(),
				'description' => $definition->getListDescription(),
				'icon' => $definition->getIcon(),
				'severity' => $definition->getSeverity(),
				'value' => $diagnostics->countFailing($definition),
				'url' => $this->hubUrl($kind->path(), $definition->getFilter()?->getListParams() ?? []),
			];
		}

		return $this->renderTemplate('hub/pages/diagnostics', [
			'area' => $area,
			'page' => $page,
			'title' => $kind->label(),
			'tasks' => $diagnostics->sort($tasks, $kind, fn (array $task) => [$task['key'], $task['value'] === 0, $task['severity']]),
			'siteScoped' => $siteScoped,
			'extraTabs' => $extraTabs,
		]);
	}
}
