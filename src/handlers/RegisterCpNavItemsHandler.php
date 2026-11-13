<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\handlers;

use craft\events\RegisterCpNavItemsEvent;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\services\NavigationService;

/**
 * Puts the "Hub", "Data" and "Structure" navigation items, those the user may open, in place of the plugin's own item,
 * which is the first of them the user may open (see Hub::getCpNavItem()).
 *
 * @internal
 */
final readonly class RegisterCpNavItemsHandler
{
	private const URLS = ['hub/data', 'hub/structure', 'hub/manage'];

	public function __construct(
		private NavigationService $navigation,
	) {}

	public function __invoke(RegisterCpNavItemsEvent $event): void
	{
		foreach ($event->navItems as $index => $item) {
			if (in_array(Row::value($item, 'url'), self::URLS, true)) {
				array_splice($event->navItems, $index, 1, Fill::truthy([$this->navigation->getManageNavItem(), $this->navigation->getDataNavItem(), $this->navigation->getStructureNavItem()]));

				return;
			}
		}
	}
}
