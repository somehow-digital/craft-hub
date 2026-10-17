<?php

namespace SomehowDigital\Craft\Hub\handlers;

use craft\events\RegisterUrlRulesEvent;

class RegisterCpRoutesHandler
{
	public function __invoke(RegisterUrlRulesEvent $event): void
	{
		$event->rules['hub'] = 'hub/overview/index';
		$event->rules['hub/entries'] = 'hub/entries/index';
		$event->rules['hub/entries/entries'] = 'hub/entries/index';
		$event->rules['hub/entries/entries/<id:\d+>'] = 'hub/entries/view';
		$event->rules['hub/entries/sections'] = 'hub/sections/index';
		$event->rules['hub/entries/sections/<id:\d+>'] = 'hub/sections/view';
		$event->rules['hub/entries/types'] = 'hub/types/index';
		$event->rules['hub/entries/types/<id:\d+>'] = 'hub/types/view';
		$event->rules['hub/entries/fields'] = 'hub/fields/index';
		$event->rules['hub/entries/fields/<id:\d+>'] = 'hub/fields/view';
		$event->rules['hub/assets'] = 'hub/files/index';
		$event->rules['hub/assets/files'] = 'hub/files/index';
		$event->rules['hub/assets/files/<id:\d+>'] = 'hub/files/view';
		$event->rules['hub/assets/volumes'] = 'hub/volumes/index';
		$event->rules['hub/assets/volumes/<id:\d+>'] = 'hub/volumes/view';
	}
}
