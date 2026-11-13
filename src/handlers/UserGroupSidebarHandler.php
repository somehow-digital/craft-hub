<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\handlers;

use craft\models\UserGroup;
use craft\services\UserGroups;
use craft\web\Controller;
use craft\web\CpScreenResponseBehavior;
use craft\web\Response;
use craft\web\View;
use SomehowDigital\Craft\Hub\assets\SidebarAsset;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\ListType;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\ActivityFeedService;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\services\MaintainerService;
use yii\base\ActionEvent;

/**
 * Adds the Hub's panel to the sidebar of Craft's user group page, for users who may view the group's Hub page: its labels,
 * what it maintains and, for users who may view the activity log, its latest activity. User groups aren't elements, so
 * they have no sidebar event; the panel is added to the screen instead. Craft Team's only group is shown on a page without
 * a sidebar, and a group yet to be created has nothing to show.
 *
 * @internal
 */
final readonly class UserGroupSidebarHandler
{
	public function __construct(
		private AccessService $access,
		private LabelService $labels,
		private MaintainerService $maintainers,
		private ActivityService $activity,
		private ActivityFeedService $feed,
		private View $view,
		private UserGroups $craftUserGroups,
	) {}

	public function __invoke(ActionEvent $event): void
	{
		$controller = $event->action->controller;
		$response = $event->result;

		if ($event->action->id !== 'edit-group' || !$controller instanceof Controller || !$response instanceof Response) {
			return;
		}

		$screen = $response->getBehavior(CpScreenResponseBehavior::NAME);
		$group = $this->group(Fill::map($controller->actionParams));

		if (!$screen instanceof CpScreenResponseBehavior || !$group instanceof UserGroup || !$this->access->canView(Page::Users)) {
			return;
		}

		$view = $this->view;

		$view->registerAssetBundle(SidebarAsset::class);

		$html = $view->renderTemplate('hub/_sidebar/hub', [
			...$this->labels->getItemVariables(ListType::UserGroups->value, (int) $group->id),
			'element' => $group,
			'list' => ListType::UserGroups->value,
			'maintainers' => [],
			'activitySubject' => ActivitySubject::UserGroup->value,
			'checks' => null,
			'lastEdit' => null,
			'references' => null,
			'referenced' => null,
			'maintained' => $this->maintainers->getMaintainedByGroup($group),
			'activities' => $this->activity->canView() ? $this->feed->getItemActivities(ActivitySubject::UserGroup, (int) $group->id, DefineSidebarHtmlHandler::ACTIVITY_LIMIT) : null,
		]);

		$existing = $screen->metaSidebarHtml;

		$screen->metaSidebarHtml((Fill::text(is_callable($existing) ? $existing() : $existing) ?? '') . $html);
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

		return Fill::id($id) !== null ? $this->craftUserGroups->getGroupById(Fill::int($id)) : null;
	}
}
