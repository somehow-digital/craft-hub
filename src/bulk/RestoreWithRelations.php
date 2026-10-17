<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Restores trashed elements along with the trashed elements related to them (see TrashService::getRelatedTrashed()):
 * those the user may restore from the trash of their own page and save.
 */
class RestoreWithRelations extends Restore
{
	public function definition(): array
	{
		return [
			'label' => Craft::t('hub', 'Restore with relations'),
			'icon' => 'diagram-project',
			'unless' => 'unrestorable',
			'preview' => true,
			'titles' => [
				'one' => Craft::t('hub', 'Restore “{name}” with its relations?'),
				'some' => Craft::t('hub', 'Restore {count} selected items with their relations?'),
			],
			'loading' => Craft::t('hub', 'Checking what is restored along with them…'),
		];
	}

	public function apply(array $ids, array $params): array
	{
		$service = Craft::$app->getElements();
		$elements = $this->hub()->bulk->getElements($this->list, $ids);
		$relations = $this->relations($elements);
		$restored = [];

		return $this->succeeded($elements, function (ElementInterface $element) use ($service, $relations, &$restored) {
			if (!$service->canSave($element) || !$service->restoreElement($element)) {
				return false;
			}

			// An element related to several items is restored once.
			foreach ($relations[$element->id] ?? [] as $id => $related) {
				if (!isset($restored[$id])) {
					$restored[$id] = $service->restoreElement($related);
				}
			}

			return true;
		});
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		$service = Craft::$app->getElements();
		$elements = array_values(array_filter(
			$this->hub()->bulk->getElements($this->list, $ids),
			fn (ElementInterface $element) => (!$element instanceof Asset || $element->keptFile) && $service->canSave($element),
		));
		$relations = $this->relations($elements);
		$related = array_replace([], ...array_values($relations));
		[$pageInfo, $shown] = (new Paginator($page))->items($elements);

		return new BulkPreview('relations-preview', count($elements), [
			'items' => array_map(fn (ElementInterface $element) => ['element' => $element, 'children' => array_values($relations[$element->id] ?? [])], $shown),
			'pageInfo' => $pageInfo,
			'entries' => count(array_filter($related, fn (ElementInterface $element) => $element instanceof Entry)),
			'files' => count(array_filter($related, fn (ElementInterface $element) => $element instanceof Asset)),
		]);
	}

	/**
	 * Trashed elements restored along with each of the elements, by its ID.
	 *
	 * @param ElementInterface[] $elements
	 * @return array<int,array<int,Entry|Asset>>
	 */
	private function relations(array $elements): array
	{
		$hub = $this->hub();
		$service = Craft::$app->getElements();
		$canEntries = $hub->access->canAct(Page::Entries, Hub::PERMISSION_RESTORE_ENTRIES);
		$canFiles = $hub->access->canAct(Page::Assets, Hub::PERMISSION_RESTORE_ASSETS);

		return array_filter(array_map(
			fn (array $related) => array_filter($related, fn (ElementInterface $element) => ($element instanceof Asset ? $canFiles : $canEntries) && $service->canSave($element)),
			$hub->trash->getRelatedTrashed(array_map(fn (ElementInterface $element) => (int)$element->id, $elements)),
		));
	}
}
