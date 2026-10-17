<?php

namespace SomehowDigital\Craft\Hub\handlers;

use craft\events\RegisterCpNavItemsEvent;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Puts the "Hub", "Data" and "Structure" navigation items, those the user may open, in place of the plugin's own item,
 * which is the first of them the user may open (see Hub::getCpNavItem()).
 */
class RegisterCpNavItemsHandler
{
	private const URLS = ['hub/data', 'hub/structure', 'hub/manage'];

	public function __invoke(RegisterCpNavItemsEvent $event): void
	{
		foreach ($event->navItems as $index => $item) {
			if (in_array($item['url'] ?? null, self::URLS, true)) {
				$hub = Hub::getInstance();
				array_splice($event->navItems, $index, 1, array_filter([$hub->getManageNavItem(), $hub->getDataNavItem(), $hub->getStructureNavItem()]));

				return;
			}
		}
	}
}
