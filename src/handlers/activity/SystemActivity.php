<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\handlers\activity;

use craft\controllers\AssetIndexesController;
use craft\controllers\UtilitiesController;
use craft\db\Connection;
use craft\events\BackupEvent;
use craft\services\Volumes;
use craft\utilities\AssetIndexes;
use craft\utilities\ClearCaches;
use craft\utilities\FindAndReplace;
use craft\utilities\Migrations;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\services\ActivityService;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event;

/**
 * Utilities run from the control panel, and the database being backed up or restored.
 *
 * @internal
 */
final class SystemActivity extends BaseActivitySubscriber
{
	public function __construct(
		ActivityService $activity,
		private readonly Volumes $craftVolumes,
		private readonly ?WebRequest $request,
		private readonly ?WebResponse $response,
	) {
		parent::__construct($activity);
	}

	public function register(): void
	{
		Event::on(UtilitiesController::class, Controller::EVENT_AFTER_ACTION, fn(ActionEvent $event) => $this->utility($event));
		Event::on(AssetIndexesController::class, Controller::EVENT_AFTER_ACTION, fn(ActionEvent $event) => $this->assetIndexing($event));
		Event::on(Connection::class, Connection::EVENT_AFTER_CREATE_BACKUP, fn(BackupEvent $event) => $this->activity->record(ActivityAction::BackedUp, ActivitySubject::Database, null, basename($event->file)));
		Event::on(Connection::class, Connection::EVENT_AFTER_RESTORE_BACKUP, fn(BackupEvent $event) => $this->activity->record(ActivityAction::Restored, ActivitySubject::Database, null, basename($event->file)));
	}

	/**
	 * Utilities run from the control panel, unless they failed; backups are recorded as they're created.
	 */
	private function utility(ActionEvent $event): void
	{
		if ($this->response?->getIsSuccessful() !== true) {
			return;
		}

		$params = $this->request?->getBodyParam('params');

		[$action, $title, $data] = match ($event->action->id) {
			'clear-caches-perform-action' => [ActivityAction::Cleared, ClearCaches::displayName(), ['caches' => Fill::list($this->request?->getBodyParam('caches'))]],
			'invalidate-tags' => [ActivityAction::Cleared, ClearCaches::displayName(), ['tags' => Fill::list($this->request?->getBodyParam('tags'))]],
			'find-and-replace-perform-action' => is_array($params) && Fill::text($params['find'] ?? null) !== null && Fill::text($params['replace'] ?? null) !== null
				? [ActivityAction::Ran, FindAndReplace::displayName(), ['find' => Row::string($params, 'find'), 'replace' => Row::string($params, 'replace')]]
				: [null, null, []],
			'apply-new-migrations' => [ActivityAction::Ran, Migrations::displayName(), []],
			default => [null, null, []],
		};

		if ($action !== null) {
			$this->activity->record($action, ActivitySubject::Utility, null, $title, $data);
		}
	}

	/**
	 * Asset indexing started, by the names of the volumes indexed.
	 */
	private function assetIndexing(ActionEvent $event): void
	{
		if ($event->action->id !== 'start-indexing' || $this->response?->getIsSuccessful() !== true) {
			return;
		}

		$volumes = $this->craftVolumes;
		$names = array_values(Fill::truthy(array_map(fn(mixed $id): ?string => $volumes->getVolumeById(Fill::int($id))?->name, Fill::list($this->request?->getBodyParam('volumes')))));

		$this->activity->record(ActivityAction::Ran, ActivitySubject::Utility, null, AssetIndexes::displayName(), array_filter(['volumes' => $names]));
	}
}
