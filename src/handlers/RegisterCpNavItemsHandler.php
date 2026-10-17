<?php

namespace SomehowDigital\Craft\Hub\handlers;

use craft\events\RegisterCpNavItemsEvent;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Adds the "Structure" navigation item right after the "Data" one, which is the plugin's own item.
 */
class RegisterCpNavItemsHandler
{
	public function __invoke(RegisterCpNavItemsEvent $event): void
	{
		foreach ($event->navItems as $index => $item) {
			if (($item['url'] ?? null) === 'hub/data') {
				array_splice($event->navItems, $index + 1, 0, [Hub::getInstance()->getStructureNavItem()]);

				return;
			}
		}
	}
}
