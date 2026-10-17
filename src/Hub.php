<?php

namespace SomehowDigital\Craft\Hub;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use SomehowDigital\Craft\Hub\handlers\DefineEntrySidebarHtmlHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterCpRoutesHandler;
use SomehowDigital\Craft\Hub\handlers\RegisterPermissionsHandler;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\services\ListService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use SomehowDigital\Craft\Hub\services\ReviewService;
use SomehowDigital\Craft\Hub\services\StatisticsService;
use SomehowDigital\Craft\Hub\services\StructureService;
use SomehowDigital\Craft\Hub\services\VolumeService;
use yii\base\Event;

/**
 * @property CheckService $checks
 * @property EntryService $entries
 * @property FileService $files
 * @property ListService $lists
 * @property ReferenceService $references
 * @property ReviewService $review
 * @property StatisticsService $statistics
 * @property StructureService $structure
 * @property VolumeService $volumes
 * @property Settings $settings
 */
class Hub extends Plugin
{
	public const PERMISSION_ACCESS = 'hub-access';
	public const SITE_PARAM = 'hubSite';

	public bool $hasCpSection = true;
	public bool $hasCpSettings = true;

	/** @var Site[]|null memoized, as the site scope is read for every count and link */
	private ?array $sites = null;

	/** @var int[]|null */
	private ?array $siteIds = null;

	public function init(): void
	{
		parent::init();

		Craft::setAlias('@hub', $this->getBasePath());

		$this->registerPermissions();

		if (Craft::$app->getRequest()->getIsCpRequest()) {
			$this->registerCpRoutes();
			$this->registerEntrySidebar();
		}
	}

	public static function config(): array
	{
		return [
			'components' => [
				'checks' => ['class' => CheckService::class],
				'entries' => ['class' => EntryService::class],
				'files' => ['class' => FileService::class],
				'lists' => ['class' => ListService::class],
				'references' => ['class' => ReferenceService::class],
				'review' => ['class' => ReviewService::class],
				'statistics' => ['class' => StatisticsService::class],
				'structure' => ['class' => StructureService::class],
				'volumes' => ['class' => VolumeService::class],
			],
		];
	}

	/**
	 * The sites selected via the comma-separated `hubSite` query param, like `?hubSite=en,de`; empty when all sites are in scope.
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
		$value = $request->getIsConsoleRequest() ? null : $request->getQueryParam(self::SITE_PARAM);
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

	public function getCpNavItem(): ?array
	{
		if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_ACCESS)) {
			return null;
		}

		$item = parent::getCpNavItem();
		$item['label'] = Craft::t('hub', 'Hub');
		$item['url'] = 'hub';

		// Keep the selected sites when switching between Hub pages.
		$params = Craft::$app->getRequest()->getSegment(1) === 'hub' ? [self::SITE_PARAM => $this->getSiteParam()] : [];

		$item['subnav'] = [
			'overview' => [
				'label' => Craft::t('hub', 'Overview'),
				'url' => UrlHelper::cpUrl('hub', $params),
			],
			'entries' => [
				'label' => Craft::t('hub', 'Entries'),
				'url' => UrlHelper::cpUrl('hub/entries', $params),
			],
			'assets' => [
				'label' => Craft::t('hub', 'Assets'),
				'url' => UrlHelper::cpUrl('hub/assets', $params),
			],
		];

		return $item;
	}

	protected function createSettingsModel(): Settings
	{
		return new Settings();
	}

	protected function settingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate('hub/settings/plugin', [
			'settings' => $this->getSettings(),
			'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile($this->handle)),
		]);
	}

	protected function registerCpRoutes(): void
	{
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_CP_URL_RULES,
			new RegisterCpRoutesHandler()
		);
	}

	protected function registerEntrySidebar(): void
	{
		Event::on(
			Entry::class,
			Element::EVENT_DEFINE_SIDEBAR_HTML,
			new DefineEntrySidebarHtmlHandler()
		);
	}

	protected function registerPermissions(): void
	{
		Event::on(
			UserPermissions::class,
			UserPermissions::EVENT_REGISTER_PERMISSIONS,
			new RegisterPermissionsHandler()
		);
	}
}
