<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Entry;
use craft\models\Section;
use craft\services\Entries;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\EntryService;
use Throwable;

/**
 * Moves entries to the section picked (`sectionId`), like Craft's own moving, see EntryService::canMoveTo().
 *
 * @internal
 */
final class MoveToSection extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly EntryService $entries,
		private readonly Entries $craftEntries,
	) {
		parent::__construct($list, $access);
	}

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
		return $this->access->canAct(Page::Entries, Permission::MoveEntries->value);
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$service = $this->craftEntries;
		$section = $service->getSectionById($this->intParam($params, 'sectionId'));
		$hubEntries = $this->entries;

		return $section instanceof Section ? $this->succeeded($this->bulk->getElementsOf($this->list, $ids, Entry::class), function (Entry $entry) use ($hubEntries, $service, $section): bool {
			if (!$hubEntries->canMoveTo($entry, $section)) {
				return false;
			}

			try {
				return $service->moveEntryToSection($entry, $section);
			} catch (Throwable $throwable) {
				Craft::warning('Couldn’t move entry ' . (int) $entry->id . ' to section ' . (int) $section->id . ': ' . $throwable->getMessage(), __METHOD__);

				return false;
			}
		}) : [];
	}

	public function preview(array $ids, int $page): BulkPreview
	{
		$hubEntries = $this->entries;
		$entries = $this->bulk->getElementsOf($this->list, $ids, Entry::class);
		$targets = $hubEntries->getSectionTargets($entries);
		$movable = array_filter($entries, function (Entry $entry) use ($hubEntries, $targets): bool {
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
