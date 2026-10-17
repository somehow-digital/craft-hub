<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use SomehowDigital\Craft\Hub\enums\ListType;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use yii\base\Component;

/**
 * What the current user may do in Hub, by its own permissions; Craft's permissions of each item still apply.
 */
class AccessService extends Component
{
	/**
	 * Whether the current user may open the settings page, as they may manage the saved filters, the labels or the checks;
	 * admins may manage the settings of the statistics and the activity log too.
	 */
	public function canManageSettings(): bool
	{
		$user = Craft::$app->getUser();

		return $user->checkPermission(Hub::PERMISSION_MANAGE_LABELS)
			|| $user->checkPermission(Hub::PERMISSION_MANAGE_DIAGNOSTICS)
			|| $user->checkPermission(Hub::PERMISSION_MANAGE_FILTERS);
	}

	/**
	 * Whether the current user may view a page (see Page), by the page or its value: they may access the plugin and the
	 * page's area, and view it.
	 */
	public function canView(Page|string $page): bool
	{
		$page = is_string($page) ? Page::tryFrom($page) : $page;
		$user = Craft::$app->getUser();

		return $page !== null
			&& $user->checkPermission('accessPlugin-' . Hub::getInstance()->id)
			&& $user->checkPermission($page->areaPermission())
			&& $user->checkPermission($page->viewPermission());
	}

	/**
	 * Whether the current user may label the items of a page; whether labels are enabled is up to LabelService::canApply().
	 */
	public function canLabel(Page $page): bool
	{
		return $this->canView($page) && Craft::$app->getUser()->checkPermission($page->labelPermission());
	}

	/**
	 * Whether the current user may select several items of a page at once, to apply the actions to all of them; without it,
	 * the actions are applied to one item at a time, from its context menu.
	 */
	public function canBulkEdit(Page $page): bool
	{
		return $this->canView($page) && Craft::$app->getUser()->checkPermission($page->bulkEditPermission());
	}

	/**
	 * Whether the current user may apply an action to the items of a page, by its permission (see Page::actionPermissions()),
	 * or to those in its trash (see Page::trashActionPermissions()), which they must be able to view; Craft's own
	 * permissions of each item still apply.
	 */
	public function canAct(Page $page, string $permission): bool
	{
		if (isset($page->trashActionPermissions()[$permission])) {
			return $this->canViewTrash($page) && Craft::$app->getUser()->checkPermission($permission);
		}

		return $this->canView($page) && isset($page->actionPermissions()[$permission]) && Craft::$app->getUser()->checkPermission($permission);
	}

	/**
	 * Whether the current user may view the trash tab of a page, while the trash is enabled: they may view the page and
	 * its trash (see Page::trashPermission()).
	 */
	public function canViewTrash(Page $page): bool
	{
		$permission = $page->trashPermission();

		return Hub::getInstance()->settings->enableTrash
			&& $permission !== null
			&& $this->canView($page)
			&& Craft::$app->getUser()->checkPermission($permission);
	}

	/**
	 * Whether the current user may open the pages of a list (see ColumnService), so its items can be selected and labeled.
	 */
	public function canViewList(string $list): bool
	{
		$type = ListType::tryFrom($list);

		if ($type === ListType::Activity) {
			return Hub::getInstance()->activity->canView();
		}

		if (($page = $type?->page()) === null) {
			return false;
		}

		return $type->isTrash() ? $this->canViewTrash($page) : $this->canView($page);
	}

	/**
	 * Whether the current user may open the Hub page of the item, as they may view the page listing it.
	 */
	public function canViewPageOf(mixed $item): bool
	{
		return ($page = Page::fromItem($item)) !== null && $this->canView($page);
	}
}
