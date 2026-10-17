<?php

namespace SomehowDigital\Craft\Hub\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Queue;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\jobs\TakeSnapshot;
use yii\console\ExitCode;

/**
 * Takes snapshots of the statistics, like a cron job can, rather than waiting for a control panel request to push one.
 */
class SnapshotsController extends Controller
{
	public $defaultAction = 'index';

	/** whether to replace the snapshot of today, if there is one */
	public bool $force = false;

	/** whether to take it in the queue instead */
	public bool $queue = false;

	public function options($actionID): array
	{
		return [...parent::options($actionID), 'force', 'queue'];
	}

	/**
	 * Takes the snapshot of the statistics of today, unless there is one already.
	 */
	public function actionIndex(): int
	{
		if ($this->queue) {
			Queue::push(new TakeSnapshot());
			$this->stdout('Taking the snapshot in the queue.' . PHP_EOL, Console::FG_GREEN);

			return ExitCode::OK;
		}

		if (!Hub::getInstance()->snapshots->take($this->force)) {
			$this->stdout('There is a snapshot of today already; use --force to replace it.' . PHP_EOL, Console::FG_YELLOW);

			return ExitCode::OK;
		}

		$this->stdout('Took the snapshot of today.' . PHP_EOL, Console::FG_GREEN);

		return ExitCode::OK;
	}
}
