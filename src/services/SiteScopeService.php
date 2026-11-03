<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\elements\db\ElementQuery;
use craft\helpers\UrlHelper;
use craft\models\Site;
use SomehowDigital\Craft\Hub\Hub;
use yii\base\Component;

/**
 * The sites Hub's pages are scoped to, picked by the `hubSite` query param and kept in the session, so coming back to Hub from
 * elsewhere in the control panel shows them again.
 */
class SiteScopeService extends Component
{
	private const SESSION_KEY = 'hub:sites';

	/** @var Site[]|null memoized, as the site scope is read for every count and link */
	private ?array $sites = null;

	/** @var int[]|null */
	private ?array $siteIds = null;

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
	 * @return Site[]
	 */
	private function resolveSites(): array
	{
		$request = Craft::$app->getRequest();
		$value = $request->getIsConsoleRequest() ? null : $request->getQueryParam(Hub::SITE_PARAM);

		// Other pages, like the dashboard with its widgets, and actions only follow the param.
		$kept = !$request->getIsConsoleRequest() && $request->getIsCpRequest() && $request->getSegment(1) === 'hub';

		if ($kept && $value === null) {
			$value = Craft::$app->getSession()->get(self::SESSION_KEY);
		}

		$sites = $this->sitesOf($value);

		if ($kept) {
			$session = Craft::$app->getSession();
			$sites !== [] ? $session->set(self::SESSION_KEY, implode(',', array_map(fn (Site $site) => $site->handle, $sites))) : $session->remove(self::SESSION_KEY);
		}

		return $sites;
	}

	/**
	 * @return Site[] the editable sites of the comma-separated handles
	 */
	private function sitesOf(mixed $value): array
	{
		$handles = array_unique(array_filter(array_map('trim', explode(',', is_string($value) ? $value : '')), fn (string $handle) => $handle !== ''));
		$editableIds = Craft::$app->getSites()->getEditableSiteIds();

		// Kept in the order of the sites, so the same selection always reads the same.
		return array_values(array_filter(
			Craft::$app->getSites()->getAllSites(),
			fn (Site $site) => in_array($site->handle, $handles, true) && in_array($site->id, $editableIds, false),
		));
	}

	/**
	 * The `hubSite` query param of the selected sites, or null when all sites are in scope.
	 */
	public function getSiteParam(): ?string
	{
		$sites = $this->getSites();

		return $sites !== [] ? implode(',', array_map(fn (Site $site) => $site->handle, $sites)) : null;
	}

	/**
	 * CP URL to a Hub page that keeps the selected sites.
	 *
	 * @param array<string,mixed> $params
	 */
	public function pageUrl(string $path, array $params = []): string
	{
		return UrlHelper::cpUrl($path, [Hub::SITE_PARAM => $this->getSiteParam(), ...$params]);
	}

	/**
	 * IDs of the sites counts are scoped to: the selected sites, or all editable sites.
	 *
	 * @return int[]
	 */
	public function getSiteIds(): array
	{
		if ($this->siteIds !== null) {
			return $this->siteIds;
		}

		$sites = $this->getSites();
		$siteIds = $sites !== [] ? array_map(fn (Site $site) => $site->id, $sites) : Craft::$app->getSites()->getEditableSiteIds();
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
		return array_diff(Craft::$app->getSites()->getAllSiteIds(true), $siteIds) === [];
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
		$selected = array_map(fn (Site $site) => $site->id, $this->getSites());

		return $query
			->siteId($this->getSiteIds())
			->preferSites([...$selected, Craft::$app->getSites()->getCurrentSite()->id])
			->unique();
	}
}
