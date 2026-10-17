<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\FieldInterface;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;

/**
 * Who maintains a section, entry type, field or volume an element is stored in, so editors know who to ask about the element.
 */
class MaintainerSource
{
	/**
	 * @param string $list list of the item, see ColumnService::LIST_*
	 * @param User[] $users
	 * @param UserGroup[] $groups
	 */
	public function __construct(
		public string $list,
		public Section|EntryType|FieldInterface|Volume $item,
		public array $users,
		public array $groups,
	) {
	}

	/**
	 * What kind of item the maintainers are those of.
	 */
	public function getKind(): ?string
	{
		return ActivitySubject::fromList($this->list)?->label();
	}
}
