<?php

namespace SomehowDigital\Craft\Hub\handlers;

use craft\events\RegisterUrlRulesEvent;

class RegisterCpRoutesHandler
{
	/** index pages by their path, each with a statistics and a diagnostics tab and detail pages with tabs of their own, mapped to their controller */
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

	/** detail pages with a statistics tab, by the path of their index page */
	private const STATISTICS_TABS = [
		'hub/structure/entry-types',
		'hub/structure/fields',
		'hub/structure/sections',
		'hub/structure/volumes',
	];

	/** tabs of an index page between its list and its statistics, by its path, each routed to the action of the same name */
	private const INDEX_TABS = [
		'hub/data/users' => ['groups'],
	];

	public function __invoke(RegisterUrlRulesEvent $event): void
	{
		$event->rules['hub/data'] = 'hub/nav/data';
		$event->rules['hub/structure'] = 'hub/nav/structure';

		$event->rules['hub/manage'] = 'hub/nav/manage';

		// The activity log, below the "Hub" navigation item, listing what users did.
		$event->rules['hub/manage/activity'] = 'hub/activity/index';

		// The settings page, below the "Hub" navigation item, leads to its first tab: one managing the saved filters of the
		// lists, one managing the labels, which are the same for all lists, one managing the checks of the diagnostics tabs,
		// and ones with the settings of the statistics and of the activity log.
		$event->rules['hub/manage/settings'] = 'hub/settings/index';
		$event->rules['hub/manage/settings/labels'] = 'hub/labels/index';
		$event->rules['hub/manage/settings/labels/new'] = 'hub/labels/edit';
		$event->rules['hub/manage/settings/labels/<labelId:\d+>'] = 'hub/labels/edit';
		$event->rules['hub/manage/settings/diagnostics'] = 'hub/checks/index';
		$event->rules['hub/manage/settings/diagnostics/new/<kind:[a-zA-Z]+>'] = 'hub/checks/edit';
		$event->rules['hub/manage/settings/diagnostics/<checkId:\d+>'] = 'hub/checks/edit';
		$event->rules['hub/manage/settings/filters'] = 'hub/filters/index';
		$event->rules['hub/manage/settings/filters/new/<kind:[a-zA-Z]+>'] = 'hub/filters/edit';
		$event->rules['hub/manage/settings/filters/<filterId:\d+>'] = 'hub/filters/edit';
		$event->rules['hub/manage/settings/statistics'] = 'hub/settings/statistics';
		$event->rules['hub/manage/settings/activity'] = 'hub/settings/activity';

		foreach (self::PAGES as $path => $controller) {
			$event->rules[$path] = "hub/$controller/index";
			$event->rules["$path/statistics"] = "hub/$controller/statistics";
			$event->rules["$path/diagnostics"] = "hub/$controller/diagnostics";

			foreach (self::INDEX_TABS[$path] ?? [] as $tab) {
				$event->rules["$path/$tab"] = "hub/$controller/$tab";
			}

			$event->rules["$path/<id:\d+>"] = "hub/$controller/view";

			// The tabs of the detail pages are on URLs of their own too, below that of their overview.
			$tabs = implode('|', [...self::LIST_TABS[$path], ...(in_array($path, self::STATISTICS_TABS, true) ? ['statistics'] : []), 'diagnostics']);
			$event->rules["$path/<id:\d+>/<tab:$tabs>"] = "hub/$controller/view";
		}
	}
}
