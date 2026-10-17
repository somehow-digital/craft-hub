<?php

namespace SomehowDigital\Craft\Hub\handlers\activity;

use Craft;
use craft\controllers\AssetIndexesController;
use craft\controllers\UtilitiesController;
use craft\db\Connection;
use craft\elements\Asset;
use craft\events\BackupEvent;
use craft\utilities\AssetIndexes;
use craft\utilities\ClearCaches;
use craft\utilities\FindAndReplace;
use craft\utilities\Migrations;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event;

/**
 * Utilities run from the control panel, and the database being backed up or restored.
 */
class SystemActivity extends ActivitySubscriber
{
	public function register(): void
	{
		Event::on(UtilitiesController::class, Controller::EVENT_AFTER_ACTION, fn (ActionEvent $event) => $this->utility($event));
		Event::on(AssetIndexesController::class, Controller::EVENT_AFTER_ACTION, fn (ActionEvent $event) => $this->assetIndexing($event));
		Event::on(Connection::class, Connection::EVENT_AFTER_CREATE_BACKUP, fn (BackupEvent $event) => $this->activity()->record(ActivityAction::BackedUp, ActivitySubject::Database, null, basename($event->file)));
		Event::on(Connection::class, Connection::EVENT_AFTER_RESTORE_BACKUP, fn (BackupEvent $event) => $this->activity()->record(ActivityAction::Restored, ActivitySubject::Database, null, basename($event->file)));
	}

	/**
	 * Utilities run from the control panel, unless they failed; backups are recorded as they're created.
	 */
	private function utility(ActionEvent $event): void
	{
		if (!Craft::$app->getResponse()->getIsSuccessful()) {
			return;
		}

		$request = Craft::$app->getRequest();
		$params = $request->getBodyParam('params');

		[$action, $title, $data] = match ($event->action->id) {
			'clear-caches-perform-action' => [ActivityAction::Cleared, ClearCaches::displayName(), ['caches' => (array)$request->getBodyParam('caches')]],
			'invalidate-tags' => [ActivityAction::Cleared, ClearCaches::displayName(), ['tags' => (array)$request->getBodyParam('tags')]],
			'find-and-replace-perform-action' => is_array($params) && !empty($params['find']) && !empty($params['replace'])
				? [ActivityAction::Ran, FindAndReplace::displayName(), ['find' => (string)$params['find'], 'replace' => (string)$params['replace']]]
				: [null, null, []],
			'apply-new-migrations' => [ActivityAction::Ran, Migrations::displayName(), []],
			default => [null, null, []],
		};

		if ($action !== null) {
			$this->activity()->record($action, ActivitySubject::Utility, null, $title, $data);
		}
	}

	/**
	 * Asset indexing started, by the names of the volumes indexed.
	 */
	private function assetIndexing(ActionEvent $event): void
	{
		if ($event->action->id !== 'start-indexing' || !Craft::$app->getResponse()->getIsSuccessful()) {
			return;
		}

		$volumes = Craft::$app->getVolumes();
		$names = array_values(array_filter(array_map(fn (mixed $id) => $volumes->getVolumeById((int)$id)?->name, (array)Craft::$app->getRequest()->getBodyParam('volumes'))));

		$this->activity()->record(ActivityAction::Ran, ActivitySubject::Utility, null, AssetIndexes::displayName(), array_filter(['volumes' => $names]));
	}
}
