<?php

namespace SomehowDigital\Craft\Hub\handlers;

use craft\events\RegisterUrlRulesEvent;

class RegisterCpRoutesHandler
{
	/** index pages by their path, each with a diagnostics tab and detail pages with tabs of their own, mapped to their controller */
	private const PAGES = [
		'hub/data/entries' => 'entries',
		'hub/data/assets' => 'files',
		'hub/structure/entry-types' => 'types',
		'hub/structure/sections' => 'sections',
		'hub/structure/fields' => 'fields',
		'hub/structure/volumes' => 'volumes',
	];

	/** tabs listing the entries, assets or transforms of a detail page, by the path of its index page */
	private const LIST_TABS = [
		'hub/data/assets' => 'transforms',
		'hub/structure/entry-types' => 'entries',
		'hub/structure/fields' => 'entries',
		'hub/structure/sections' => 'entries',
		'hub/structure/volumes' => 'assets',
	];

	public function __invoke(RegisterUrlRulesEvent $event): void
	{
		$event->rules['hub/data'] = 'hub/entries/index';
		$event->rules['hub/structure'] = 'hub/types/index';

		foreach (self::PAGES as $path => $controller) {
			$event->rules[$path] = "hub/$controller/index";
			$event->rules["$path/diagnostics"] = "hub/$controller/diagnostics";
			$event->rules["$path/<id:\d+>"] = "hub/$controller/view";

			// The tabs of the detail pages are on URLs of their own too, below that of their overview.
			$tabs = implode('|', array_filter([self::LIST_TABS[$path] ?? null, 'diagnostics']));
			$event->rules["$path/<id:\d+>/<tab:$tabs>"] = "hub/$controller/view";
		}
	}
}
