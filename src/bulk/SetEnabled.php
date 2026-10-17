<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Enables or disables entries like Craft's element actions: enabling enables them for the site they're loaded in and
 * validates them as live, disabling disables them on all sites.
 */
class SetEnabled extends BulkAction
{
	public function __construct(string $list, private readonly bool $enabled)
	{
		parent::__construct($list);
	}

	public function definition(): array
	{
		return $this->enabled
			? ['label' => Craft::t('app', 'Enable'), 'icon' => 'toggle-on', 'unless' => 'enabled']
			: ['label' => Craft::t('app', 'Disable'), 'icon' => 'toggle-off', 'unless' => 'disabled'];
	}

	public function isAvailable(): bool
	{
		return $this->hub()->access->canAct(Page::Entries, Hub::PERMISSION_ENABLE_ENTRIES);
	}

	public function apply(array $ids, array $params): array
	{
		$service = Craft::$app->getElements();

		return $this->succeeded($this->hub()->bulk->getElementsOf($this->list, $ids, Entry::class), function (Entry $entry) use ($service) {
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
