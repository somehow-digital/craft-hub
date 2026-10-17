<?php

namespace SomehowDigital\Craft\Hub\handlers;

use Craft;
use craft\models\UserGroup;
use craft\web\Controller;
use craft\web\CpScreenResponseBehavior;
use craft\web\Response;
use SomehowDigital\Craft\Hub\assets\SidebarAsset;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ColumnService;
use yii\base\ActionEvent;

/**
 * Adds the Hub's panel to the sidebar of Craft's user group page, for users who may view the group's Hub page: its labels,
 * what it maintains and, for users who may view the activity log, its latest activity. User groups aren't elements, so
 * they have no sidebar event; the panel is added to the screen instead. Craft Team's only group is shown on a page without
 * a sidebar, and a group yet to be created has nothing to show.
 */
class UserGroupSidebarHandler
{
	public function __invoke(ActionEvent $event): void
	{
		$controller = $event->action->controller;
		$response = $event->result;

		if ($event->action->id !== 'edit-group' || !$controller instanceof Controller || !$response instanceof Response) {
			return;
		}

		$screen = $response->getBehavior(CpScreenResponseBehavior::NAME);
		$group = $this->group($controller->actionParams);
		$hub = Hub::getInstance();

		if (!$screen instanceof CpScreenResponseBehavior || $group === null || !$hub->access->canView(Page::Users)) {
			return;
		}

		$view = Craft::$app->getView();

		$view->registerAssetBundle(SidebarAsset::class);

		$html = $view->renderTemplate('hub/_sidebar/hub', [
			...$hub->labels->getItemVariables(ColumnService::LIST_USER_GROUPS, (int)$group->id),
			'element' => $group,
			'list' => ColumnService::LIST_USER_GROUPS,
			'maintainers' => [],
			'activitySubject' => ActivitySubject::UserGroup->value,
			'checks' => null,
			'lastEdit' => null,
			'references' => null,
			'referenced' => null,
			'maintained' => $hub->maintainers->getMaintainedByGroup($group),
			'activities' => $hub->activity->canView() ? $hub->activity->getItemActivities(ActivitySubject::UserGroup, (int)$group->id, DefineSidebarHtmlHandler::ACTIVITY_LIMIT) : null,
		]);

		$existing = $screen->metaSidebarHtml;

		$screen->metaSidebarHtml((is_callable($existing) ? $existing() : $existing) . $html);
	}

	/**
	 * The group of the page: the one that failed to save, or that of the URL.
	 *
	 * @param array<string,mixed> $params
	 */
	private function group(array $params): ?UserGroup
	{
		$group = $params['group'] ?? null;
		$id = $group instanceof UserGroup ? $group->id : $params['groupId'] ?? null;

		return $id ? Craft::$app->getUserGroups()->getGroupById((int)$id) : null;
	}
}
