<?php

namespace SomehowDigital\Craft\Hub\controllers;

use DateTime;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class EntriesController extends BaseController
{
	public function actionIndex(): Response
	{
		return $this->renderTemplate('hub/pages/entries/index', [
			...$this->entryListVariables('hub/entries/entries'),
			'summary' => $this->entriesSummary(Hub::getInstance()->structure->getSections()),
		]);
	}

	public function actionView(int $id): Response
	{
		$hub = Hub::getInstance();
		/** @var EntryService $entries */
		$entries = $hub->entries;
		/** @var ReferenceService $references */
		$references = $hub->references;

		$entry = $entries->getEntry($id) ?? throw new NotFoundHttpException('Entry not found.');
		$months = $hub->settings->staleThresholdMonths;
		$staleDate = (new DateTime())->modify(sprintf('-%d months', $months));
		$relations = $references->getRelations($entry);
		$stale = $entry->dateUpdated !== null && $entry->dateUpdated < $staleDate;

		return $this->renderTemplate('hub/pages/entries/view', [
			'entry' => $entry,
			'relations' => $relations,
			'sites' => $entries->getSiteVariants($entry),
			'stale' => $stale,
			'staleMonths' => $months,
			'checks' => $hub->checks->forEntry($entry, count($relations), $stale),
		]);
	}
}
