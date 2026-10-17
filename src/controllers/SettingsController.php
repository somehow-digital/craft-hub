<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Settings;
use yii\web\Response;

/**
 * The maintainers, statistics, activity and trash tabs of the settings page, editing the plugin's settings, for admins,
 * read-only while admin changes aren't allowed. The settings page itself leads to its first tab the user may open: the
 * filters, the labels or the diagnostics tab, or the statistics tab. Each tab saves only its own settings, the labels
 * and diagnostics tabs switching theirs too.
 */
class SettingsController extends BaseController
{
	/**
	 * Leads to the first tab of the settings page the user may open.
	 */
	public function actionIndex(): Response
	{
		$user = Craft::$app->getUser();
		$tabs = [
			'filters' => Hub::PERMISSION_MANAGE_FILTERS,
			'labels' => Hub::PERMISSION_MANAGE_LABELS,
			'diagnostics' => Hub::PERMISSION_MANAGE_DIAGNOSTICS,
		];

		foreach ($tabs as $tab => $permission) {
			if ($user->checkPermission($permission)) {
				return $this->redirect($this->hubUrl("hub/manage/settings/$tab"));
			}
		}

		$this->requireAdmin(false);

		return $this->redirect($this->hubUrl('hub/manage/settings/statistics'));
	}

	/**
	 * Form of the maintainers setting; settings that failed to save are passed back to it.
	 */
	public function actionMaintainers(?Settings $settings = null): Response
	{
		$this->requireAdmin(false);

		return $this->renderTemplate('hub/pages/settings/maintainers', $this->settingsVariables($settings));
	}

	/**
	 * Form of the statistics settings; settings that failed to save are passed back to it.
	 */
	public function actionStatistics(?Settings $settings = null): Response
	{
		$this->requireAdmin(false);

		return $this->renderTemplate('hub/pages/settings/statistics', $this->settingsVariables($settings));
	}

	/**
	 * Form of the activity settings; settings that failed to save are passed back to it.
	 */
	public function actionActivity(?Settings $settings = null): Response
	{
		$this->requireAdmin(false);

		return $this->renderTemplate('hub/pages/settings/activity', $this->settingsVariables($settings));
	}

	/**
	 * Form of the trash settings; settings that failed to save are passed back to it.
	 */
	public function actionTrash(?Settings $settings = null): Response
	{
		$this->requireAdmin(false);

		return $this->renderTemplate('hub/pages/settings/trash', $this->settingsVariables($settings));
	}

	/**
	 * Saves the settings posted by a tab, keeping the others, and goes back to it.
	 */
	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAdmin();

		$hub = Hub::getInstance();
		$values = Craft::$app->getRequest()->getBodyParam('settings');

		if (!Craft::$app->getPlugins()->savePluginSettings($hub, is_array($values) ? $values : [])) {
			return $this->asModelFailure($hub->getSettings(), Craft::t('hub', 'Couldn’t save the settings.'), 'settings');
		}

		$redirect = $this->getPostedRedirectUrl() ?? $this->hubUrl('hub/manage/settings/statistics');

		return $this->asModelSuccess($hub->getSettings(), Craft::t('hub', 'Settings saved.'), 'settings', redirect: $redirect);
	}
}
