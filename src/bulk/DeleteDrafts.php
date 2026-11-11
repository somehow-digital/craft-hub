<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Entry;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\models\Paginator;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\EntryService;

/**
 * Deletes the drafts of entries the user may delete, listing them by entry, skipping entries without any.
 *
 * @internal
 */
final class DeleteDrafts extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly EntryService $entries,
		private readonly Elements $craftElements,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return [
			'label' => Craft::t('hub', 'Delete drafts'),
			'icon' => 'eraser',
			'confirm' => Craft::t('hub', 'Are you sure you want to delete the drafts of the selected entries? This can’t be undone.'),
			'preview' => true,
			'titles' => [
				'one' => Craft::t('hub', 'Delete the drafts of “{name}”?'),
				'some' => Craft::t('hub', 'Delete the drafts of {count} selected entries?'),
			],
			'loading' => Craft::t('hub', 'Checking which drafts are deleted…'),
		];
	}

	public function isAvailable(): bool
	{
		return $this->access->canAct(Page::Entries, Permission::DeleteDrafts->value);
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$entries = $this->bulk->getElementsOf($this->list, $ids, Entry::class);
		[$withDrafts, $drafts] = $this->entries->getDeletableDrafts(array_map(fn(Entry $entry): int => (int) $entry->id, $entries));
		$service = $this->craftElements;

		return $this->succeeded($withDrafts, fn(Entry $entry): bool => array_filter(array_map(fn(Entry $draft): bool => $service->deleteElement($draft, true), $drafts[(int) $entry->id])) !== []);
	}

	public function preview(array $ids, int $page): BulkPreview
	{
		[$entries, $drafts] = $this->entries->getDeletableDrafts($ids);
		[$pageInfo, $shown] = (new Paginator($page))->items($entries);

		return new BulkPreview('drafts-preview', count($entries), [
			'items' => array_map(fn(Entry $entry): array => ['element' => $entry, 'count' => count($drafts[(int) $entry->id]), 'children' => $drafts[(int) $entry->id]], $shown),
			'pageInfo' => $pageInfo,
			'drafts' => array_sum(array_map(count(...), $drafts)),
		]);
	}
}
