<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use SomehowDigital\Craft\Hub\enums\ListType;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;

/**
 * What the actions restoring trashed elements share: who may use them.
 *
 * @internal
 */
abstract class BaseRestore extends BaseBulkAction
{
	public function isAvailable(): bool
	{
		return $this->list === ListType::TrashedEntries->value
			? $this->access->canAct(Page::Entries, Permission::RestoreEntries->value)
			: $this->access->canAct(Page::Assets, Permission::RestoreAssets->value);
	}
}
