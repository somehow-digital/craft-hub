<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\handlers;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\DefineHtmlEvent;
use craft\web\View;
use SomehowDigital\Craft\Hub\assets\SidebarAsset;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\ListType;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\ActivityFeedService;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\CheckService;
use SomehowDigital\Craft\Hub\services\EditService;
use SomehowDigital\Craft\Hub\services\LabelService;
use SomehowDigital\Craft\Hub\services\MaintainerService;
use SomehowDigital\Craft\Hub\services\ReferenceService;

/**
 * Adds the Hub's panel to the edit sidebar of entries, assets and users, for users who may view their Hub page: the
 * element's maintainers, its labels, which users who may label it change right there, the checks it fails, when an entry
 * was last edited and by whom, the elements referencing it and those it references, what a user maintains, and, for users
 * who may view the activity log, its latest activity. Everyone else gets the maintainers alone, if it has any, as
 * they're there for whoever edits it. Drafts and revisions show those of their canonical element, as Hub's lists hold those.
 *
 * @internal
 */
final readonly class DefineSidebarHtmlHandler
{
	/** latest activities shown in the panel */
	public const ACTIVITY_LIMIT = 5;

	public function __construct(
		private AccessService $access,
		private MaintainerService $maintainers,
		private LabelService $labels,
		private CheckService $checks,
		private EditService $edits,
		private ReferenceService $references,
		private ActivityService $activity,
		private ActivityFeedService $feed,
		private Settings $settings,
		private View $view,
	) {}

	public function __invoke(DefineHtmlEvent $event): void
	{
		$element = $event->sender;

		if (!$element instanceof ElementInterface || $element->id === null || ($element instanceof Entry && $element->getIsUnpublishedDraft())) {
			return;
		}

		[$page, $list] = match (true) {
			$element instanceof Entry => [Page::Entries, ListType::Entries->value],
			$element instanceof Asset => [Page::Assets, ListType::Files->value],
			$element instanceof User => [Page::Users, ListType::Users->value],
			default => [null, ''],
		};

		if ($page === null) {
			return;
		}

		$canonical = $element->getCanonical(true);
		$canView = $this->access->canView($page);
		$maintainers = $this->maintainers->getSources($canonical, true);

		if (!$canView && $maintainers === []) {
			return;
		}

		$view = $this->view;

		$view->registerAssetBundle(SidebarAsset::class);

		$event->html .= $view->renderTemplate('hub/_sidebar/hub', [
			'element' => $canonical,
			'list' => $list,
			'maintainers' => $maintainers,
			'activitySubject' => ActivitySubject::fromElement($canonical)->value,
			...($canView ? $this->hubVariables($list, $element, $canonical) : [
				'labels' => null,
				'allLabels' => null,
				'canManageLabels' => false,
				'checks' => null,
				'lastEdit' => null,
				'references' => null,
				'referenced' => null,
				'maintained' => null,
				'activities' => null,
			]),
		]);
	}

	/**
	 * What the panel shows of the element to users who may view its Hub page.
	 *
	 * @return array<string,mixed>
	 */
	private function hubVariables(string $list, ElementInterface $element, ElementInterface $canonical): array
	{

		return [
			...$this->labels->getItemVariables($list, (int) $canonical->id, !$element->getIsRevision()),
			'checks' => $this->settings->enableDiagnostics ? match (true) {
				$canonical instanceof Entry => $this->checks->forEntry($canonical),
				$canonical instanceof Asset => $this->checks->forFile($canonical),
				$canonical instanceof User => $this->checks->forUser($canonical),
				default => null,
			} : null,
			'lastEdit' => $canonical instanceof Entry ? $this->edits->getLastEdit($canonical) : null,
			'references' => $this->references->getReferences($element),
			'referenced' => $this->references->getReferenced($element),
			'maintained' => $canonical instanceof User ? $this->maintainers->getMaintainedBy($canonical) : [],
			'activities' => $this->activity->canView() ? $this->feed->getItemActivities(ActivitySubject::fromElement($canonical), (int) $canonical->id, self::ACTIVITY_LIMIT) : null,
		];
	}
}
