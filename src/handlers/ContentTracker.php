<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\handlers;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\events\MultiElementActionEvent;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\Hub;
use yii\base\Event;

/**
 * Keeps what Hub knows of the content as elements are saved: the reference tags in their content, when people last
 * edited entries, and the counts of the work of the user changing entries and assets, see WorkService::getCounts().
 *
 * Elements are indexed once they're saved on all their sites, so the copies saved while propagating are left out, but
 * elements propagated to other sites on their own, like when a site is added, are indexed again.
 */
class ContentTracker
{
	public function register(): void
	{
		Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, fn(ElementEvent $event) => $this->elementSaved($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_PROPAGATE_ELEMENT, fn(MultiElementActionEvent $event) => $this->elementPropagated($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, fn(ElementEvent $event) => $this->forgetWork($event->element));
	}

	private function elementSaved(ElementEvent $event): void
	{
		$element = $event->element;
		$this->forgetWork($element);

		if ($element->propagating || $element->getIsDraft() || $element->getIsRevision()) {
			return;
		}

		Hub::getInstance()->references->index([$element->id]);

		if ($element instanceof Entry) {
			Hub::getInstance()->edits->record($element, $event->isNew || $element->firstSave);
		}
	}

	/**
	 * Drafts are counted too, so their saves count as well, other than the unsaved changes Craft saves as people type.
	 */
	private function forgetWork(ElementInterface $element): void
	{
		if (($element instanceof Entry || $element instanceof Asset) && !$element->propagating && !$element->getIsRevision() && !$element->isProvisionalDraft) {
			Hub::getInstance()->work->forgetCounts();
		}
	}

	private function elementPropagated(MultiElementActionEvent $event): void
	{
		if (!$event->element->getIsDraft() && !$event->element->getIsRevision()) {
			Hub::getInstance()->references->index([$event->element->id]);
		}
	}
}
