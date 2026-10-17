<?php

namespace SomehowDigital\Craft\Hub\handlers;

use craft\events\RegisterCpNavItemsEvent;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Adds the "Structure" navigation item right after the "Data" one, which is the plugin's own item, and the "Hub" one
 * after that, for users who may manage what it holds.
 */
class RegisterCpNavItemsHandler
{
	public function __invoke(RegisterCpNavItemsEvent $event): void
	{
		foreach ($event->navItems as $index => $item) {
			if (($item['url'] ?? null) === 'hub/data') {
				$hub = Hub::getInstance();
				array_splice($event->navItems, $index + 1, 0, array_filter([$hub->getStructureNavItem(), $hub->getManageNavItem()]));

				return;
			}
		}
	}
}
