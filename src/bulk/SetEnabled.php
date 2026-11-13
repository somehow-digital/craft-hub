<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;

/**
 * Enables or disables entries like Craft's element actions: enabling enables them for the site they're loaded in and
 * validates them as live, disabling disables them on all sites.
 *
 * @internal
 */
final class SetEnabled extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly Elements $craftElements,
		private readonly bool $enabled,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return $this->enabled
			? ['label' => Craft::t('app', 'Enable'), 'icon' => 'toggle-on', 'unless' => 'enabled']
			: ['label' => Craft::t('app', 'Disable'), 'icon' => 'toggle-off', 'unless' => 'disabled'];
	}

	public function isAvailable(): bool
	{
		return $this->access->canAct(Page::Entries, Permission::EnableEntries->value);
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$service = $this->craftElements;

		return $this->succeeded($this->bulk->getElementsOf($this->list, $ids, Entry::class), function (Entry $entry) use ($service): bool {
			if (!$service->canSave($entry)) {
				return false;
			}

			$entry->enabled = $this->enabled;

			if ($this->enabled) {
				$entry->setEnabledForSite(true);
				$entry->setScenario(Element::SCENARIO_LIVE);
			}

			return $service->saveElement($entry);
		});
	}
}
