<?php

namespace SomehowDigital\Craft\Hub\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\Queue;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\jobs\IndexReferences;
use yii\console\ExitCode;

/**
 * Indexes the reference tags in the content of all elements again.
 */
class ReferencesController extends Controller
{
	public $defaultAction = 'index';

	/** whether to index them in the queue instead */
	public bool $queue = false;

	public function options($actionID): array
	{
		return [...parent::options($actionID), 'queue'];
	}

	/**
	 * Indexes the reference tags in the content of all elements, like links and images in rich text.
	 */
	public function actionIndex(): int
	{
		if ($this->queue) {
			Queue::push(new IndexReferences());
			$this->stdout('Indexing the references in the queue.' . PHP_EOL, Console::FG_GREEN);

			return ExitCode::OK;
		}

		$references = Hub::getInstance()->references;
		$query = $references->indexableQuery();
		$total = (int)$query->count();
		$done = 0;

		Console::startProgress(0, $total);

		foreach (Db::batch($query, 500) as $rows) {
			$references->index(array_map(fn (array $row) => (int)$row['id'], $rows));
			$done += count($rows);
			Console::updateProgress($done, $total);
		}

		Console::endProgress();
		$this->stdout(sprintf('Indexed the references of %d elements.', $total) . PHP_EOL, Console::FG_GREEN);

		return ExitCode::OK;
	}
}
