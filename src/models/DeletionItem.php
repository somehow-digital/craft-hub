<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;

/**
 * An item of a deletion preview, with what deleting it takes along, in groups: like its nested entries, drafts and
 * references, each listing up to ELEMENT_LIMIT of what it is about.
 */
class DeletionItem
{
	/** most elements, models or names listed in a group */
	public const ELEMENT_LIMIT = 10;

	/**
	 * @var array<int,array{tone:string,icon:string,label:string,count:int,items:array<int,ElementInterface|EntryReference|Section|EntryType|FieldInterface|string>,more:int}>
	 */
	public array $groups = [];

	public function __construct(
		public ElementInterface|Section|EntryType|FieldInterface|Volume|UserGroup $model,
	) {
	}

	public function getId(): int
	{
		return (int)$this->model->id;
	}

	/**
	 * Groups with anything in them, which deleting the item affects.
	 *
	 * @return array<int,array{tone:string,icon:string,label:string,count:int,items:array<int,ElementInterface|EntryReference|Section|EntryType|FieldInterface|string>,more:int}>
	 */
	public function getAffected(): array
	{
		return array_values(array_filter($this->groups, fn (array $group) => $group['count'] > 0));
	}

	/**
	 * Adds a group of what deleting the item deletes too, changes or keeps (see DeletionPreview::TONE_*), even if there is
	 * nothing in it, so each item counts the same groups.
	 *
	 * @param string $label naming the count, like “Drafts”
	 * @param int $count how many there are, of which the items are the first
	 * @param array<int,ElementInterface|EntryReference|Section|EntryType|FieldInterface|string> $items
	 */
	public function addGroup(string $tone, string $icon, string $label, int $count, array $items = []): void
	{
		$count = max(0, $count);
		$items = array_slice(array_values($items), 0, self::ELEMENT_LIMIT);

		$this->groups[] = [
			'tone' => $tone,
			'icon' => $icon,
			'label' => $label,
			'count' => $count,
			'items' => $items,
			'more' => $items !== [] ? max(0, $count - count($items)) : 0,
		];
	}
}
