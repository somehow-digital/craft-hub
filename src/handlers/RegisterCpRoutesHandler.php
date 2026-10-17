<?php

namespace SomehowDigital\Craft\Hub\handlers;

use craft\events\RegisterUrlRulesEvent;

class RegisterCpRoutesHandler
{
	/** index pages by their path, each with a diagnostics tab, summary cards loaded on their own and detail pages with tabs of their own, mapped to their controller */
	private const PAGES = [
		'hub/data/entries' => 'entries',
		'hub/data/assets' => 'files',
		'hub/data/users' => 'users',
		'hub/structure/entry-types' => 'types',
		'hub/structure/sections' => 'sections',
		'hub/structure/fields' => 'fields',
		'hub/structure/volumes' => 'volumes',
	];

	/** tabs listing the entries, assets, transforms or history of each detail page, by the path of its index page */
	private const LIST_TABS = [
		'hub/data/assets' => ['transforms'],
		'hub/data/entries' => ['history'],
		'hub/data/users' => ['entries', 'assets'],
		'hub/structure/entry-types' => ['entries'],
		'hub/structure/fields' => ['entries'],
		'hub/structure/sections' => ['entries'],
		'hub/structure/volumes' => ['assets'],
	];

	/** tabs of an index page between its list and its diagnostics, by its path, each routed to the action of the same name */
	private const INDEX_TABS = [
		'hub/data/users' => ['groups'],
	];

	public function __invoke(RegisterUrlRulesEvent $event): void
	{
		$event->rules['hub/data'] = 'hub/nav/data';
		$event->rules['hub/structure'] = 'hub/nav/structure';

		$event->rules['hub/manage'] = 'hub/nav/manage';

		// The labels are the same for all lists, so they are managed on pages of their own, below the "Hub" navigation item.
		$event->rules['hub/manage/labels'] = 'hub/labels/index';
		$event->rules['hub/manage/labels/new'] = 'hub/labels/edit';
		$event->rules['hub/manage/labels/<labelId:\d+>'] = 'hub/labels/edit';

		foreach (self::PAGES as $path => $controller) {
			$event->rules[$path] = "hub/$controller/index";
			$event->rules["$path/diagnostics"] = "hub/$controller/diagnostics";

			foreach (self::INDEX_TABS[$path] ?? [] as $tab) {
				$event->rules["$path/$tab"] = "hub/$controller/$tab";
			}

			$event->rules["$path/summary"] = "hub/$controller/summary";
			$event->rules["$path/<id:\d+>"] = "hub/$controller/view";

			// The tabs of the detail pages are on URLs of their own too, below that of their overview.
			$tabs = implode('|', [...self::LIST_TABS[$path], 'diagnostics']);
			$event->rules["$path/<id:\d+>/<tab:$tabs>"] = "hub/$controller/view";
		}
	}
}
