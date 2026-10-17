<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use Throwable;

/**
 * Moves entries to the section picked (`sectionId`), like Craft's own moving, see EntryService::canMoveTo().
 */
class MoveToSection extends BulkAction
{
	public function definition(): array
	{
		return $this->picked(
			Craft::t('hub', 'Move to section'),
			'arrow-right-arrow-left',
			Craft::t('hub', 'Move “{name}” to another section?'),
			Craft::t('hub', 'Move {count} selected entries to another section?'),
			Craft::t('hub', 'Checking which sections they can be moved to…'),
		);
	}

	public function isAvailable(): bool
	{
		return $this->hub()->access->canAct(Page::Entries, Hub::PERMISSION_MOVE_ENTRIES);
	}

	public function apply(array $ids, array $params): array
	{
		$service = Craft::$app->getEntries();
		$section = $service->getSectionById($this->intParam($params, 'sectionId'));
		$hubEntries = $this->hub()->entries;

		return $section === null ? [] : $this->succeeded($this->hub()->bulk->getElementsOf($this->list, $ids, Entry::class), function (Entry $entry) use ($hubEntries, $service, $section) {
			if (!$hubEntries->canMoveTo($entry, $section)) {
				return false;
			}

			try {
				return $service->moveEntryToSection($entry, $section);
			} catch (Throwable $e) {
				Craft::warning("Couldn’t move entry $entry->id to section $section->id: {$e->getMessage()}", __METHOD__);

				return false;
			}
		});
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		$hubEntries = $this->hub()->entries;
		$entries = $this->hub()->bulk->getElementsOf($this->list, $ids, Entry::class);
		$targets = $hubEntries->getSectionTargets($entries);
		$movable = array_filter($entries, function (Entry $entry) use ($hubEntries, $targets) {
			foreach ($targets as $target) {
				if ($hubEntries->canMoveTo($entry, $target['section'])) {
					return true;
				}
			}

			return false;
		});

		return new BulkPreview('section-preview', count($movable), ['targets' => $targets]);
	}
}
