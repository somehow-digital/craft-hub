<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Deletes the drafts of entries the user may delete, listing them by entry, skipping entries without any.
 */
class DeleteDrafts extends BulkAction
{
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
		return $this->hub()->access->canAct(Page::Entries, Hub::PERMISSION_DELETE_DRAFTS);
	}

	public function apply(array $ids, array $params): array
	{
		$entries = $this->hub()->bulk->getElementsOf($this->list, $ids, Entry::class);
		[$withDrafts, $drafts] = $this->hub()->entries->getDeletableDrafts(array_map(fn (Entry $entry) => (int)$entry->id, $entries));
		$service = Craft::$app->getElements();

		return $this->succeeded($withDrafts, fn (Entry $entry) => array_filter(array_map(fn (Entry $draft) => $service->deleteElement($draft, true), $drafts[$entry->id])) !== []);
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		[$entries, $drafts] = $this->hub()->entries->getDeletableDrafts($ids);
		[$pageInfo, $shown] = (new Paginator($page))->items($entries);

		return new BulkPreview('drafts-preview', count($entries), [
			'items' => array_map(fn (Entry $entry) => ['element' => $entry, 'count' => count($drafts[$entry->id]), 'children' => $drafts[$entry->id]], $shown),
			'pageInfo' => $pageInfo,
			'drafts' => array_sum(array_map('count', $drafts)),
		]);
	}
}
