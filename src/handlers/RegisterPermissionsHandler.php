<?php

namespace SomehowDigital\Craft\Hub\handlers;

use Craft;
use craft\events\RegisterUserPermissionsEvent;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Registers the permissions accessing the data and the structure, each with those of its pages nested under it: viewing
 * the page, and labeling, bulk-editing and applying actions to its items nested under that (see Page).
 */
class RegisterPermissionsHandler
{
	public function __invoke(RegisterUserPermissionsEvent $event): void
	{
		$event->permissions[] = [
			'heading' => Craft::t('hub', 'Hub'),
			'permissions' => [
				Hub::PERMISSION_ACCESS_DATA => [
					'label' => Craft::t('hub', 'Access data'),
					'nested' => $this->pages([Page::Entries, Page::Assets, Page::Users]),
				],
				Hub::PERMISSION_ACCESS_STRUCTURE => [
					'label' => Craft::t('hub', 'Access structure'),
					'nested' => $this->pages([Page::EntryTypes, Page::Sections, Page::Fields, Page::Volumes]),
				],
				Hub::PERMISSION_MANAGE_LABELS => [
					'label' => Craft::t('hub', 'Manage labels'),
				],
				Hub::PERMISSION_MANAGE_DIAGNOSTICS => [
					'label' => Craft::t('hub', 'Manage diagnostic checks'),
				],
				Hub::PERMISSION_MANAGE_FILTERS => [
					'label' => Craft::t('hub', 'Manage saved filters'),
					'info' => Craft::t('hub', 'The predefined and custom filters offered to everyone. Users save their own filters in the lists they may view.'),
				],
				Hub::PERMISSION_VIEW_ACTIVITY => [
					'label' => Craft::t('hub', 'View the activity log'),
				],
			],
		];
	}

	/**
	 * @param Page[] $pages
	 * @return array<string,array{label:string,info?:string,nested:array<string,array{label:string,info?:string}>}>
	 */
	private function pages(array $pages): array
	{
		$permissions = [];
		foreach ($pages as $page) {
			$actions = [];
			foreach ($page->actionPermissions() as $permission => $label) {
				$actions[$permission] = ['label' => $label, 'info' => Craft::t('hub', 'Craft’s own permissions of the items still apply.')];
			}

			[$view, $label, $bulkEdit] = $page->permissionLabels();
			$permissions[$page->viewPermission()] = [
				'label' => $view,
				...($page === Page::Users ? ['info' => Craft::t('hub', 'Includes the user groups and the users’ personal data.')] : []),
				'nested' => [
					$page->labelPermission() => ['label' => $label],
					$page->bulkEditPermission() => [
						'label' => $bulkEdit,
						'info' => Craft::t('hub', 'Select several items at once, to label them or apply actions to all of them.'),
					],
					...$actions,
				],
			];
		}

		return $permissions;
	}
}
