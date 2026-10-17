<?php

namespace SomehowDigital\Craft\Hub\handlers;

use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\events\MultiElementActionEvent;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\Hub;
use yii\base\Event;

/**
 * Keeps what Hub knows of the content as elements are saved: the reference tags in their content, and when people last
 * edited entries.
 *
 * Elements are indexed once they're saved on all their sites, so the copies saved while propagating are left out, but
 * elements propagated to other sites on their own, like when a site is added, are indexed again.
 */
class ContentTracker
{
	public function register(): void
	{
		Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, fn (ElementEvent $event) => $this->elementSaved($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_PROPAGATE_ELEMENT, fn (MultiElementActionEvent $event) => $this->elementPropagated($event));
	}

	private function elementSaved(ElementEvent $event): void
	{
		$element = $event->element;

		if ($element->propagating || $element->getIsDraft() || $element->getIsRevision()) {
			return;
		}

		Hub::getInstance()->references->index([$element->id]);

		if ($element instanceof Entry) {
			Hub::getInstance()->edits->record($element, $event->isNew || $element->firstSave);
		}
	}

	private function elementPropagated(MultiElementActionEvent $event): void
	{
		if (!$event->element->getIsDraft() && !$event->element->getIsRevision()) {
			Hub::getInstance()->references->index([$event->element->id]);
		}
	}
}
