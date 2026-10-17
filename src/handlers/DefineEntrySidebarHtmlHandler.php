<?php

namespace SomehowDigital\Craft\Hub\handlers;

use Craft;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use SomehowDigital\Craft\Hub\assets\SidebarAsset;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ReferenceService;

/**
 * Adds a panel to the entry edit sidebar that lists the elements referencing the entry.
 */
class DefineEntrySidebarHtmlHandler
{
	public function __invoke(DefineHtmlEvent $event): void
	{
		$entry = $event->sender;

		if (!$entry instanceof Entry || !$entry->id || $entry->getIsUnpublishedDraft()) {
			return;
		}

		if (!Hub::getInstance()->canView(Page::Entries)) {
			return;
		}

		/** @var ReferenceService $references */
		$references = Hub::getInstance()->references;
		$view = Craft::$app->getView();

		$view->registerAssetBundle(SidebarAsset::class);

		$event->html .= $view->renderTemplate('hub/_sidebar/references', [
			'references' => $references->getReferences($entry),
		]);
	}
}
