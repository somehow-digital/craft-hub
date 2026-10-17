<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;
use craft\web\twig\variables\Paginate;
use SomehowDigital\Craft\Hub\helpers\Paginator;

/**
 * What deleting the items of a list takes along, shown before they're deleted: the totals of what is deleted, changed or
 * kept along with all items, and the items of a page, each with what deleting it takes along (see DeletionItem).
 */
class DeletionPreview
{
	/** effects on what is deleted along with the items, changed by deleting them, or kept */
	public const TONE_DELETED = 'deleted';
	public const TONE_CHANGED = 'changed';
	public const TONE_KEPT = 'kept';

	/** number of items deleted */
	public int $total = 0;

	/** number of items selected that the user may not delete, or that no longer exist */
	public int $skipped = 0;

	/** page of the items shown, kept within the pages there are */
	public int $page = 1;

	/** @var DeletionItem[] the items deleted of the page shown */
	public array $items = [];

	/** the page shown within the pages of the items, see Paginator */
	public ?Paginate $pageInfo = null;

	/** message naming the number of items deleted, by `count`, like “# entries” */
	public string $label = '';

	/** icon of the kind of items deleted */
	public string $icon = 'trash';

	/** @var array<int,array{tone:string,icon:string,value:string,label:string}> totals of all items */
	public array $effects = [];

	/**
	 * Sets the items deleted, detailing those of the page shown, as many as on the lists of the Hub (see Paginator).
	 *
	 * @param array<int,ElementInterface|Section|EntryType|FieldInterface|Volume|UserGroup> $models
	 * @param string $label see `$label`
	 */
	public function setItems(array $models, string $label, string $icon): void
	{
		[$this->pageInfo, $shown] = (new Paginator($this->page))->items($models);
		$this->total = count($models);
		$this->page = $this->pageInfo->currentPage;
		$this->items = array_map(fn ($model) => new DeletionItem($model), array_values($shown));
		$this->label = $label;
		$this->icon = $icon;
	}

	/**
	 * IDs of the items shown with their details.
	 *
	 * @return int[]
	 */
	public function getItemIds(): array
	{
		return array_map(fn (DeletionItem $item) => $item->getId(), $this->items);
	}

	/**
	 * Adds a total of all items, unless there is nothing to it.
	 *
	 * @param int $count of what it is about, which is left out if it is none
	 * @param string $value the count, or a size, formatted
	 */
	public function addEffect(string $tone, string $icon, int $count, string $value, string $label): void
	{
		if ($count <= 0) {
			return;
		}

		$this->effects[] = ['tone' => $tone, 'icon' => $icon, 'value' => $value, 'label' => $label];
	}
}
