<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\models\Paginator;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\TrashService;

/**
 * Restores trashed elements along with the trashed elements related to them (see TrashService::getRelatedTrashed()):
 * those the user may restore from the trash of their own page and save.
 *
 * @internal
 */
final class RestoreWithRelations extends BaseRestore
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly TrashService $trash,
		private readonly Elements $craftElements,
	) {
		parent::__construct($list, $access);
	}

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

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$service = $this->craftElements;
		$elements = $this->bulk->getElements($this->list, $ids);
		$relations = $this->relations($elements);
		$restored = [];

		return $this->succeeded($elements, function (ElementInterface $element) use ($service, $relations, &$restored): bool {
			if (!$service->canSave($element) || !$service->restoreElement($element)) {
				return false;
			}

			// An element related to several items is restored once.
			foreach ($relations[(int) $element->id] ?? [] as $id => $related) {
				$restored[$id] ??= $service->restoreElement($related);
			}

			return true;
		});
	}

	public function preview(array $ids, int $page): BulkPreview
	{
		$service = $this->craftElements;
		$elements = array_values(array_filter(
			$this->bulk->getElements($this->list, $ids),
			fn(ElementInterface $element): bool => (!$element instanceof Asset || $element->keptFile === true) && $service->canSave($element),
		));
		$relations = $this->relations($elements);
		$related = array_replace([], ...array_values($relations));
		[$pageInfo, $shown] = (new Paginator($page))->items($elements);

		return new BulkPreview('relations-preview', count($elements), [
			'items' => array_map(fn(ElementInterface $element): array => ['element' => $element, 'children' => array_values($relations[(int) $element->id] ?? [])], $shown),
			'pageInfo' => $pageInfo,
			'entries' => count(array_filter($related, fn(ElementInterface $element): bool => $element instanceof Entry)),
			'files' => count(array_filter($related, fn(ElementInterface $element): bool => $element instanceof Asset)),
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
		$service = $this->craftElements;
		$canEntries = $this->access->canAct(Page::Entries, Permission::RestoreEntries->value);
		$canFiles = $this->access->canAct(Page::Assets, Permission::RestoreAssets->value);

		return array_filter(array_map(
			fn(array $related): array => array_filter($related, fn(ElementInterface $element): bool => ($element instanceof Asset ? $canFiles : $canEntries) && $service->canSave($element)),
			$this->trash->getRelatedTrashed(array_map(fn(ElementInterface $element): int => (int) $element->id, $elements)),
		));
	}
}
