<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\elements\Entry;
use DateTime;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class EntriesController extends BaseController
{
	public function actionIndex(): Response
	{
		if (($redirect = $this->keepFilters('hub/data/entries')) !== null) {
			return $redirect;
		}

		return $this->renderTemplate('hub/pages/entries/index', [
			...$this->entryListVariables('hub/data/entries'),
			'summary' => $this->entriesSummary(Hub::getInstance()->structure->getSections()),
		]);
	}

	public function actionDiagnostics(): Response
	{
		$hub = Hub::getInstance();
		$settings = $hub->settings;
		$review = $hub->review;

		$entries = $hub->statistics->sumEntries($hub->getSiteIds());

		return $this->renderDiagnostics('data', 'entries', Craft::t('hub', 'Entries'), null, [
			[
				'label' => Craft::t('hub', 'Unreferenced entries'),
				'description' => Craft::t('hub', 'Without URLs and not referenced by any element.'),
				'icon' => 'link-slash',
				'severity' => Severity::Warning,
				'value' => $review->countUnreferenced(),
				'url' => $this->hubUrl('hub/structure/sections', ['urls' => 'no']),
			],
			[
				'label' => Craft::t('hub', 'Stale entries'),
				'description' => Craft::t('hub', 'Not updated for {months, plural, =1{one month} other{# months}}.', ['months' => $settings->staleThresholdMonths]),
				'icon' => 'clock',
				'severity' => Severity::Notice,
				'value' => $review->countStale($settings->staleThresholdMonths),
				'url' => $this->hubUrl('hub/data/entries', ['stale' => 1]),
			],
			[
				'label' => Craft::t('hub', 'Drafts'),
				'description' => Craft::t('hub', 'Publish or discard them, so changes don’t get lost.'),
				'icon' => 'pen-to-square',
				'severity' => Severity::Notice,
				'value' => $entries->drafts,
				'url' => $this->hubUrl('hub/data/entries', ['drafts' => 1]),
			],
			[
				'label' => Craft::t('hub', 'Disabled entries'),
				'description' => Craft::t('hub', 'Delete those that are no longer needed.'),
				'icon' => 'eye-slash',
				'severity' => Severity::Notice,
				'value' => $entries->getDisabled(),
				'url' => $this->hubUrl('hub/data/entries', ['status' => Entry::STATUS_DISABLED]),
			],
		]);
	}

	public function actionView(int $id, string $tab = self::TAB_OVERVIEW): Response
	{
		$tab = $this->detailTab($tab);
		$hub = Hub::getInstance();
		/** @var EntryService $entries */
		$entries = $hub->entries;
		/** @var ReferenceService $references */
		$references = $hub->references;

		$entry = $entries->getEntry($id) ?? throw new NotFoundHttpException('Entry not found.');
		$months = $hub->settings->staleThresholdMonths;
		$staleDate = (new DateTime())->modify(sprintf('-%d months', $months));
		$referencing = $references->getReferences($entry);
		$stale = $entry->dateUpdated !== null && $entry->dateUpdated < $staleDate;

		return $this->renderTemplate('hub/pages/entries/view', [
			'entry' => $entry,
			'selectedTab' => $tab,
			'references' => $referencing,
			'sites' => $entries->getSiteVariants($entry),
			'stale' => $stale,
			'checks' => $hub->checks->forEntry($entry, count($referencing), $stale),
		]);
	}
}
