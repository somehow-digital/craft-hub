<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use craft\elements\db\ElementQuery;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\services\Sites;
use craft\web\Request;
use SomehowDigital\Craft\Hub\models\FilterParams;
use yii\base\Component;
use yii\base\Request as BaseRequest;
use yii\web\Session;

/**
 * The sites Hub's pages are scoped to, picked by the `hubSite` query param and kept in the session, so coming back to Hub from
 * elsewhere in the control panel shows them again.
 *
 * @internal
 */
final class SiteScopeService extends Component
{
	private const SESSION_KEY = 'hub:sites';

	/** @var Site[]|null memoized, as the site scope is read for every count and link */
	private ?array $sites = null;

	/** @var list<int>|null */
	private ?array $siteIds = null;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly Sites $craftSites,
		private readonly BaseRequest $request,
		private readonly ?Session $session = null,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * The sites selected via the comma-separated `hubSite` query param, like `?hubSite=en,de`, an empty one selecting all
	 * sites; without it, Hub's pages use those kept in the session. Empty when all sites are in scope.
	 *
	 * @return Site[]
	 */
	public function getSites(): array
	{
		return $this->sites ??= $this->resolveSites();
	}

	/**
	 * @return list<Site>
	 */
	private function resolveSites(): array
	{
		$request = $this->request instanceof Request && !$this->request->getIsConsoleRequest() ? $this->request : null;
		$value = $request?->getQueryParam(FilterParams::SITE_PARAM);

		// Other pages, like the dashboard with its widgets, and actions only follow the param.
		$session = $this->session;
		$kept = $request instanceof Request && $session instanceof Session && $request->getIsCpRequest() && $request->getSegment(1) === 'hub';

		if ($kept && $value === null) {
			$value = $session->get(self::SESSION_KEY);
		}

		$sites = $this->sitesOf($value);

		if ($kept) {
			$sites !== [] ? $session->set(self::SESSION_KEY, implode(',', array_map(fn(Site $site): ?string => $site->handle, $sites))) : $session->remove(self::SESSION_KEY);
		}

		return $sites;
	}

	/**
	 * @return list<Site> the editable sites of the comma-separated handles
	 */
	private function sitesOf(mixed $value): array
	{
		$handles = array_unique(array_filter(array_map(trim(...), explode(',', is_string($value) ? $value : '')), fn(string $handle): bool => $handle !== ''));
		$editableIds = $this->craftSites->getEditableSiteIds();

		// Kept in the order of the sites, so the same selection always reads the same.
		return array_values(array_filter(
			$this->craftSites->getAllSites(),
			fn(Site $site): bool => in_array($site->handle, $handles, true) && in_array($site->id, $editableIds, true),
		));
	}

	/**
	 * The `hubSite` query param of the selected sites, or null when all sites are in scope.
	 */
	public function getSiteParam(): ?string
	{
		$sites = $this->getSites();

		return $sites !== [] ? implode(',', array_map(fn(Site $site): ?string => $site->handle, $sites)) : null;
	}

	/**
	 * CP URL to a Hub page that keeps the selected sites.
	 *
	 * @param array<string,mixed> $params
	 */
	public function pageUrl(string $path, array $params = []): string
	{
		return UrlHelper::cpUrl($path, [FilterParams::SITE_PARAM => $this->getSiteParam(), ...$params]);
	}

	/**
	 * IDs of the sites counts are scoped to: the selected sites, or all editable sites.
	 *
	 * @return list<int>
	 */
	public function getSiteIds(): array
	{
		if ($this->siteIds !== null) {
			return $this->siteIds;
		}

		$sites = $this->getSites();
		$siteIds = array_map(fn(Site $site): int => $site->id ?? 0, $sites !== [] ? $sites : $this->craftSites->getEditableSites());
		sort($siteIds);

		return $this->siteIds = $siteIds;
	}

	/**
	 * Whether the sites are all sites, including disabled ones. Every element is on one of them, so conditions matching
	 * elements on any of them can be left out, which saves looking up the sites of each element on large sites.
	 *
	 * @param int[] $siteIds
	 */
	public function coversAllSites(array $siteIds): bool
	{
		return array_diff($this->craftSites->getAllSiteIds(true), $siteIds) === [];
	}

	/**
	 * Narrows a query of elements to the counted sites, each element once, in the first selected site or the current one if
	 * it's there. Elements are changed in the site they're loaded in, and Craft checks the user may edit that one.
	 *
	 * @template T of ElementQuery
	 * @param T $query
	 * @return T
	 */
	public function inSiteScope(ElementQuery $query): ElementQuery
	{
		$selected = array_map(fn(Site $site): ?int => $site->id, $this->getSites());

		return $query
			->siteId($this->getSiteIds())
			->preferSites([...$selected, $this->craftSites->getCurrentSite()->id])
			->unique();
	}
}
