<?php

namespace SomehowDigital\Craft\Hub\handlers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\DefineHtmlEvent;
use SomehowDigital\Craft\Hub\assets\SidebarAsset;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ColumnService;

/**
 * Adds the Hub's panel to the edit sidebar of entries, assets and users, for users who may view their Hub page: the
 * element's maintainers, its labels, which users who may label it change right there, the checks it fails, when an entry
 * was last edited and by whom, and the elements referencing it. Everyone else gets the maintainers alone, if it has any, as
 * they're there for whoever edits it. Drafts and revisions show those of their canonical element, as Hub's lists hold those.
 */
class DefineSidebarHtmlHandler
{
	public function __invoke(DefineHtmlEvent $event): void
	{
		$element = $event->sender;

		if (!$element instanceof ElementInterface || !$element->id || ($element instanceof Entry && $element->getIsUnpublishedDraft())) {
			return;
		}

		[$page, $list] = match (true) {
			$element instanceof Entry => [Page::Entries, ColumnService::LIST_ENTRIES],
			$element instanceof Asset => [Page::Assets, ColumnService::LIST_FILES],
			$element instanceof User => [Page::Users, ColumnService::LIST_USERS],
			default => [null, null],
		};

		if ($page === null) {
			return;
		}

		$hub = Hub::getInstance();
		$canonical = $element->getCanonical(true);
		$canView = $hub->access->canView($page);
		$maintainers = $hub->maintainers->getSources($canonical, true);

		if (!$canView && $maintainers === []) {
			return;
		}

		$view = Craft::$app->getView();

		$view->registerAssetBundle(SidebarAsset::class);

		$event->html .= $view->renderTemplate('hub/_sidebar/hub', [
			'element' => $canonical,
			'list' => $list,
			'maintainers' => $maintainers,
			...($canView ? $this->hubVariables($list, $element, $canonical) : [
				'labels' => null,
				'allLabels' => null,
				'canManageLabels' => false,
				'checks' => null,
				'lastEdit' => null,
				'references' => null,
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
		$hub = Hub::getInstance();

		return [
			...$this->labelVariables($list, $canonical, !$element->getIsRevision()),
			'checks' => $hub->settings->enableDiagnostics ? match (true) {
				$canonical instanceof Entry => $hub->checks->forEntry($canonical),
				$canonical instanceof Asset => $hub->checks->forFile($canonical),
				$canonical instanceof User => $hub->checks->forUser($canonical),
				default => null,
			} : null,
			'lastEdit' => $canonical instanceof Entry ? $hub->edits->getLastEdit($canonical) : null,
			'references' => $hub->references->getReferences($element),
		];
	}

	/**
	 * The element's labels, null while labels are disabled, and, for users who may label it outside of revisions, all
	 * labels to pick from.
	 *
	 * @return array{labels:array|null,allLabels:array|null,canManageLabels:bool}
	 *
	 * @return array<string,mixed>
	 */
	private function labelVariables(string $list, ElementInterface $element, bool $editable): array
	{
		$labels = Hub::getInstance()->labels;

		if (!$labels->isEnabled()) {
			return ['labels' => null, 'allLabels' => null, 'canManageLabels' => false];
		}

		$canManage = $labels->canManage();

		return [
			'labels' => $labels->getLabelsOf($list, [(int)$element->id])[(int)$element->id] ?? [],
			'allLabels' => $editable && $labels->canApply($list) && ($labels->getAllLabels() !== [] || $canManage) ? array_values($labels->getAllLabels()) : null,
			'canManageLabels' => $canManage,
		];
	}
}
