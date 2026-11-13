<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Request as WebRequest;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\models\FilterParams;
use yii\base\Component;

/**
 * Hub's navigation items in the control panel: "Hub", "Data" and "Structure", with the pages the user may view.
 *
 * @see RegisterCpNavItemsHandler
 *
 * @internal
 */
final class NavigationService extends Component
{
	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly AccessService $access,
		private readonly WorkService $work,
		private readonly ActivityService $activity,
		private readonly SiteScopeService $siteScope,
		private readonly ?WebRequest $request,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * The "Data" navigation item, listing the content, with the pages the user may view. The "Hub" one is added right
	 * before it, the "Structure" one right after it.
	 *
	 * @see getStructureNavItem()
	 * @see getManageNavItem()
	 *
	 * @return array<string,mixed>|null
	 */
	public function getDataNavItem(): ?array
	{
		return $this->areaNavItem('hub/data', Craft::t('hub', 'Data'), 'database', [Page::Entries, Page::Assets, Page::Users]);
	}

	/**
	 * The "Structure" navigation item, listing what holds the content, with the pages the user may view.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getStructureNavItem(): ?array
	{
		return $this->areaNavItem('hub/structure', Craft::t('app', 'Structure'), 'sitemap', [Page::EntryTypes, Page::Sections, Page::Fields, Page::Volumes]);
	}

	/**
	 * Navigation item of an area with the pages the user may view, unless they may view none.
	 *
	 * @param Page[] $pages
	 *
	 * @return array<string,mixed>|null
	 */
	private function areaNavItem(string $path, string $label, string $icon, array $pages): ?array
	{
		$labels = [];
		foreach ($pages as $page) {
			if ($this->access->canView($page)) {
				$labels[$page->segment()] = $page->label();
			}
		}

		if ($labels === []) {
			return null;
		}

		return [
			'label' => $label,
			'url' => $path,
			'icon' => $icon,
			'subnav' => $this->subnav($path, $labels),
		];
	}

	/**
	 * The "Hub" navigation item, with the work of the user, the number of items needing their attention as its badge, and
	 * the activity log, for users who may view them, and the settings page managing what
	 * Hub's pages share, like the labels of the lists, the checks of the diagnostics tabs and the saved filters of the
	 * lists, for users who may manage any of it; added before the "Data" one, unless the user may open neither.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getManageNavItem(): ?array
	{
		$labels = array_filter([
			'work' => $this->work->canView() ? Craft::t('hub', 'My Work') : null,
			'activity' => $this->activity->canView() ? Craft::t('hub', 'Activity') : null,
			'settings' => $this->access->canManageSettings() ? Craft::t('app', 'Settings') : null,
		], is_string(...));

		if ($labels === []) {
			return null;
		}

		return [
			'label' => Craft::t('hub', 'Hub'),
			'url' => 'hub/manage',
			'icon' => Craft::getAlias('@hub') . '/icon-mask.svg',
			'badgeCount' => isset($labels['work']) ? $this->work->getCounts()['diagnostics'] : 0,
			'subnav' => $this->subnav('hub/manage', $labels),
		];
	}

	/**
	 * @param array<string,string> $labels labels of the pages below the path, by their path segment
	 * @return array<string,array{label:string,url:string,linkAttributes:array{href:string}}>
	 */
	private function subnav(string $path, array $labels): array
	{
		// Keep the selected sites when switching between Hub pages; from elsewhere, Hub's pages restore them from the session.
		$params = $this->request?->getSegment(1) === 'hub' ? [FilterParams::SITE_PARAM => $this->siteScope->getSiteParam()] : [];

		$items = [];
		foreach ($labels as $segment => $label) {
			// Craft only selects a subnav item whose URL is part of the request path, so it is the bare path, while the
			// link itself carries the selected sites.
			$items[$segment] = [
				'label' => $label,
				'url' => "{$path}/{$segment}",
				'linkAttributes' => ['href' => UrlHelper::cpUrl("{$path}/{$segment}", $params)],
			];
		}

		return $items;
	}
}
