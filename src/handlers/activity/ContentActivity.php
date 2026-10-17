<?php

namespace SomehowDigital\Craft\Hub\handlers\activity;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\behaviors\DraftBehavior;
use craft\behaviors\RevisionBehavior;
use craft\controllers\ElementIndexesController;
use craft\elements\actions\DownloadAssetFile;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use craft\events\DraftEvent;
use craft\events\ElementActionEvent;
use craft\events\ElementEvent;
use craft\events\MergeElementsEvent;
use craft\events\MoveElementEvent;
use craft\events\ReplaceAssetEvent;
use craft\services\Assets;
use craft\services\Drafts;
use craft\services\Elements;
use craft\services\Structures;
use craft\services\Users;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\Hub;
use WeakMap;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event;

/**
 * Elements saved, deleted, restored, merged and moved, and element actions, recorded as a batch of what they changed.
 */
class ContentActivity extends ActivitySubscriber
{
	/** attributes Craft sets as elements are saved, which aren't changes users made */
	private const IGNORED_ATTRIBUTES = [
		'id', 'uid', 'canonicalId', 'draftId', 'revisionId', 'isProvisionalDraft', 'siteSettingsId', 'fieldLayoutId',
		'structureId', 'root', 'lft', 'rgt', 'level', 'uri', 'dateCreated', 'dateUpdated', 'dateLastMerged',
	];

	/** attributes of users that are secrets, set along with others, or recorded on their own */
	private const IGNORED_USER_ATTRIBUTES = [
		'admin', 'password', 'lastPasswordChangeDate', 'invalidLoginWindowStart', 'invalidLoginCount', 'verificationCode',
		'verificationCodeIssuedDate',
	];

	/** @var WeakMap<ElementInterface,true> elements being saved, which are moved in their structures along with it */
	private WeakMap $saving;

	/** number of drafts being applied, which are deleted once they are */
	private int $applyingDrafts = 0;

	/** whether an element action is performed, recorded as a batch */
	private bool $performing = false;

	public function __construct()
	{
		$this->saving = new WeakMap();
	}

	public function register(): void
	{
		Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, fn (ElementEvent $event) => $this->saving[$event->element] = true);
		Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, fn (ElementEvent $event) => $this->elementSaved($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, fn (ElementEvent $event) => $this->elementDeleted($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_FOR_SITE, fn (ElementEvent $event) => $this->elementDeletedForSite($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, fn (ElementEvent $event) => $this->elementRestored($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_MERGE_ELEMENTS, fn (MergeElementsEvent $event) => $this->elementsMerged($event));
		Event::on(Structures::class, Structures::EVENT_AFTER_MOVE_ELEMENT, fn (MoveElementEvent $event) => $this->elementMoved($event));
		Event::on(Assets::class, Assets::EVENT_AFTER_REPLACE_ASSET, fn (ReplaceAssetEvent $event) => $this->element(ActivityAction::Replaced, $event->asset, ['filename' => $event->filename]));

		Event::on(Elements::class, Elements::EVENT_BEFORE_PERFORM_ACTION, fn (ElementActionEvent $event) => $this->performingAction($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_PERFORM_ACTION, fn (ElementActionEvent $event) => $this->performedAction($event));
		Event::on(ElementIndexesController::class, Controller::EVENT_AFTER_ACTION, fn (ActionEvent $event) => $this->elementIndexAction($event));

		if (Hub::getInstance()->settings->activityDrafts) {
			Event::on(Drafts::class, Drafts::EVENT_AFTER_CREATE_DRAFT, fn (DraftEvent $event) => $this->draftCreated($event));
			Event::on(Drafts::class, Drafts::EVENT_BEFORE_APPLY_DRAFT, fn () => $this->applyingDrafts++);
			Event::on(Drafts::class, Drafts::EVENT_AFTER_APPLY_DRAFT, fn () => $this->applyingDrafts--);
		}
	}

	private function elementSaved(ElementEvent $event): void
	{
		$element = $event->element;
		unset($this->saving[$element]);

		if (!$this->tracks($element) || $element->propagating || $element->resaving) {
			return;
		}

		// Drafts and revisions are applied by saving the canonical element as a duplicate of them.
		$derivative = $element->updatingFromDerivative ? $element->duplicateOf : null;

		$behavior = $derivative?->getBehavior('revision');

		if ($behavior instanceof RevisionBehavior) {
			$this->element(ActivityAction::Reverted, $element, ['revision' => $behavior->revisionNum]);

			return;
		}

		$isNew = $event->isNew || $element->firstSave;
		$dirty = $element->getDirtyAttributes();
		$admin = $element instanceof User && ($isNew ? $element->admin : in_array('admin', $dirty, true));
		$password = $element instanceof User && !$isNew && in_array('password', $dirty, true);

		// Unpublished drafts are applied by saving them for the first time; duplicates are saved as new elements.
		if ($isNew) {
			$this->element(ActivityAction::Created, $element, $derivative === null && $element->duplicateOf !== null ? ['duplicateOf' => $element->duplicateOf->getUiLabel()] : []);
		} else {
			// Changes saved in the control panel are applied from provisional drafts, which are named like any other.
			$draft = $derivative !== null && !$derivative->isProvisionalDraft ? $derivative->getBehavior('draft') : null;
			$ignored = $element instanceof User ? [...self::IGNORED_ATTRIBUTES, ...self::IGNORED_USER_ATTRIBUTES] : self::IGNORED_ATTRIBUTES;
			$data = array_filter([
				'draft' => $draft instanceof DraftBehavior ? $draft->draftName : null,
				'attributes' => array_values(array_diff($dirty, $ignored)),
				'fields' => array_values($element->getDirtyFields()),
			]);

			// Users saved only to change what's recorded on its own aren't updated otherwise.
			if ($data !== [] || (!$admin && !$password)) {
				$this->element(ActivityAction::Updated, $element, $data);
			}
		}

		if ($admin) {
			$this->element($element->admin ? ActivityAction::AdminGranted : ActivityAction::AdminRevoked, $element);
		}

		if ($password) {
			$this->element(ActivityAction::PasswordChanged, $element);
		}
	}

	private function elementDeleted(ElementEvent $event): void
	{
		$element = $event->element;

		if ($this->tracks($element)) {
			$this->element(ActivityAction::Deleted, $element, $element->hardDelete ? ['hardDelete' => true] : []);

			return;
		}

		// Drafts are deleted once they're applied, and provisional ones as their changes are discarded.
		$draft = $element->getBehavior('draft');

		if (
			Hub::getInstance()->settings->activityDrafts
			&& $draft instanceof DraftBehavior
			&& !$element->isProvisionalDraft
			&& $this->applyingDrafts === 0
			&& !$this->isNested($element)
		) {
			$this->activity()->record(ActivityAction::DraftDeleted, ActivitySubject::fromElement($element), $element->getCanonicalId(), $element->getUiLabel(), array_filter(['draftName' => $draft->draftName]), $element->siteId);
		}
	}

	/**
	 * Elements deleted for a site while they're kept on others; those kept on none are deleted altogether.
	 */
	private function elementDeletedForSite(ElementEvent $event): void
	{
		if ($this->tracks($event->element)) {
			$this->element(ActivityAction::Deleted, $event->element, ['forSite' => true]);
		}
	}

	private function elementRestored(ElementEvent $event): void
	{
		if ($this->tracks($event->element)) {
			$this->element(ActivityAction::Restored, $event->element);
		}
	}

	/**
	 * Merged elements are deleted, so the one they were merged into is recorded.
	 */
	private function elementsMerged(MergeElementsEvent $event): void
	{
		$element = Craft::$app->getElements()->getElementById($event->prevailingElementId);

		if ($element !== null && $this->tracks($element)) {
			$this->element(ActivityAction::Merged, $element, ['merged' => $event->mergedElementId]);
		}
	}

	/**
	 * Elements moved in their structure on their own, as they're moved along with being saved too.
	 */
	private function elementMoved(MoveElementEvent $event): void
	{
		$element = $event->element;

		if (isset($this->saving[$element]) || !$this->tracks($element) || $element->propagating || $element->resaving) {
			return;
		}

		$this->element(ActivityAction::Moved, $element, array_filter([
			'position' => $event->action,
			'target' => $event->getTargetElement()?->getUiLabel(),
		]));
	}

	/**
	 * Drafts of elements created on purpose, rather than provisional ones of the changes not yet saved.
	 */
	private function draftCreated(DraftEvent $event): void
	{
		if (!$event->provisional && $this->tracks($event->canonical)) {
			$this->element(ActivityAction::DraftCreated, $event->canonical, array_filter(['draftName' => $event->draftName]));
		}
	}

	/**
	 * Whether changes of an element are recorded: drafts and revisions aren't until they're applied, nor are nested
	 * elements, which are saved along with their owners. Unpublished drafts have no canonical element, so they're no
	 * derivatives.
	 */
	private function tracks(ElementInterface $element): bool
	{
		return !$element->getIsDraft()
			&& !$element->getIsRevision()
			&& !$element->getIsDerivative()
			&& !$this->isNested($element);
	}

	private function isNested(ElementInterface $element): bool
	{
		return $element instanceof NestedElementInterface && $element->getPrimaryOwnerId() !== null;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function element(ActivityAction $action, ElementInterface $element, array $data = []): void
	{
		$subject = ActivitySubject::fromElement($element);

		if ($subject === ActivitySubject::Element) {
			$data['type'] = $element::displayName();
		}

		$this->activity()->record($action, $subject, $element->id, $element->getUiLabel(), $data, $element->siteId);
	}

	/**
	 * Element actions are recorded as a batch of what they changed, named like the action.
	 */
	private function performingAction(ElementActionEvent $event): void
	{
		if ($this->performing) {
			return;
		}

		$type = $event->criteria instanceof ElementQuery ? $event->criteria->elementType : null;

		$this->performing = true;
		$this->activity()->beginBatch(
			ActivityAction::Bulk,
			$type !== null ? ActivitySubject::fromElementType($type) : ActivitySubject::Element,
			$event->action->getTriggerLabel(),
		);
	}

	/**
	 * Downloading files changes nothing, so the files are recorded as downloaded.
	 */
	private function performedAction(ElementActionEvent $event): void
	{
		if ($event->action instanceof DownloadAssetFile) {
			foreach ($event->criteria->all() as $asset) {
				if ($asset instanceof Asset) {
					$this->element(ActivityAction::Downloaded, $asset);
				}
			}
		}

		$this->endAction();
	}

	/**
	 * Element actions that failed end their batch along with the request; exports are recorded by how many elements they
	 * held, in which format.
	 */
	private function elementIndexAction(ActionEvent $event): void
	{
		if ($event->action->id === 'perform-action') {
			$this->endAction();

			return;
		}

		if ($event->action->id !== 'export' || !$event->sender instanceof ElementIndexesController) {
			return;
		}

		$query = $event->sender->getElementQuery();
		$request = Craft::$app->getRequest();
		$exporter = $request->getBodyParam('type');

		$this->activity()->record(
			ActivityAction::Exported,
			$query instanceof ElementQuery ? ActivitySubject::fromElementType($query->elementType) : ActivitySubject::Element,
			data: array_filter([
				'count' => (int)(clone $query)->count(),
				'exporter' => is_string($exporter) && class_exists($exporter) && method_exists($exporter, 'displayName') ? $exporter::displayName() : null,
				'format' => is_string($format = $request->getBodyParam('format')) ? $format : null,
			]),
		);
	}

	private function endAction(): void
	{
		if ($this->performing) {
			$this->performing = false;
			$this->activity()->endBatch();
		}
	}
}
