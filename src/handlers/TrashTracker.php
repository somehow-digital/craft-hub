<?php

namespace SomehowDigital\Craft\Hub\handlers;

use craft\elements\Asset;
use craft\events\DeleteElementEvent;
use craft\events\ElementEvent;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\TrashService;
use yii\base\Event;

/**
 * Keeps the files of assets moved to the trash, by whatever deletes them, so they can be restored, while
 * Settings::$keepTrashedFiles is on. Craft deletes the file of an asset as it's moved to the trash, unless told to keep it,
 * after which the asset can't be restored. The files kept are recorded, so they're deleted once the assets are deleted
 * permanently, see TrashService::deletePurgedFiles().
 *
 * Assets deleted permanently, like those of a folder being deleted, are left as they are, and so are those deleted along
 * with their volume, whose files Craft keeps itself.
 */
class TrashTracker
{
	public function register(): void
	{
		Event::on(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT, fn (DeleteElementEvent $event) => $this->deleting($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, fn (ElementEvent $event) => $this->deleted($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, fn (ElementEvent $event) => $this->restored($event));
	}

	private function deleting(DeleteElementEvent $event): void
	{
		$asset = $event->element;

		if ($asset instanceof Asset && !$event->hardDelete && !$asset->deletedWithVolume) {
			$asset->keepFileOnDelete = true;
		}
	}

	private function deleted(ElementEvent $event): void
	{
		$asset = $event->element;

		if (!$asset instanceof Asset) {
			return;
		}

		$trash = Hub::getInstance()->trash;

		if ($asset->hardDelete) {
			// Deleted permanently, along with its file, unless it's kept for another reason.
			$trash->forgetKeptFile((int)$asset->id);
		} elseif ($asset->keepFileOnDelete && !$asset->deletedWithVolume) {
			$trash->recordKeptFile($asset);
		}
	}

	private function restored(ElementEvent $event): void
	{
		if ($event->element instanceof Asset) {
			Hub::getInstance()->trash->forgetKeptFile((int)$event->element->id);
		}
	}
}
