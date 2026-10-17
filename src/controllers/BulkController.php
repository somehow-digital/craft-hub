<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\Response as CraftResponse;
use craft\web\View;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\helpers\Paginator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\ColumnService;
use yii\base\Exception;
use yii\base\InvalidValueException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use ZipArchive;

/**
 * Keeps the items selected in a list for its bulk actions (see BulkService::getSelection()), and applies the action
 * picked for them, or for a single item of the list, as picked from its context menu. The items the action was applied to
 * are unselected; those it was skipped for stay selected, to be reviewed again. Downloading files and exporting items send
 * them instead, and labeling keeps the items selected, so the list and its selection stay as they are. Deleting,
 * downloading and exporting are previewed first, exporting picking the exporter and format there, as are the actions
 * picking what they change the items to, like the section entries are moved to. Only users who may bulk-edit a list select its items; others apply the actions to a single item.
 */
class BulkController extends BaseController
{
	public function actionApply(): ?Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		/** @var BulkService $bulk */
		$bulk = Hub::getInstance()->bulk;
		$list = (string)$request->getRequiredBodyParam('list');
		$path = (string)$request->getRequiredBodyParam('path');
		$action = (string)$request->getRequiredBodyParam('bulkAction');
		$actions = $bulk->getActions($list);

		if (!isset($actions[$action])) {
			throw new BadRequestHttpException('Unknown action.');
		}

		// A single item, picked from its context menu, in place of the selection.
		$item = (int)$request->getBodyParam('item');
		$this->requireSelectable($list, $item);
		$selected = $bulk->getSelection($list, $path);
		$ids = $item > 0 ? [$item] : $selected;
		$label = $actions[$action]['label'];
		$keep = $actions[$action]['keep'] ?? false;

		if ($list === ColumnService::LIST_FILES && $action === BulkService::ACTION_DOWNLOAD) {
			return $this->download($bulk->getDownloadableFiles($ids), $label);
		}

		if ($action === BulkService::ACTION_EXPORT) {
			return $this->export($list, $ids, $label);
		}

		$applied = $action === BulkService::ACTION_LABELS
			? $bulk->applyLabels($list, $ids, $this->idsBodyParam('labelsAdd'), $this->idsBodyParam('labelsRemove'))
			: $bulk->apply($list, $action, $ids, $request->getBodyParams());
		$count = count($applied);

		if ($count === 0) {
			return $this->asFailure(Craft::t('hub', '“{action}” couldn’t be applied to the selected items.', ['action' => $label]));
		}

		if (!$keep) {
			$bulk->setSelection($list, $path, array_values(array_diff($selected, $applied)));
		}

		$skipped = count(array_diff($ids, $applied));
		$message = Craft::t('hub', '“{action}” applied to {count, plural, =1{one item} other{# items}}.', ['action' => $label, 'count' => $count]);

		if ($skipped > 0) {
			$message .= ' ' . Craft::t('hub', '{count, plural, =1{One item was} other{# items were}} skipped, as you may not change {count, plural, =1{it} other{them}} or {count, plural, =1{it} other{they}} couldn’t be saved, so {count, plural, =1{it stays} other{they stay}} selected.', ['count' => $skipped]);
		}

		// With nothing left to review, the list is shown again, with its kept filters; otherwise the page is shown again.
		$done = $skipped === 0 && $item === 0 && !$keep;

		return $this->asSuccess($message, ['count' => $count, 'skipped' => $skipped], $done ? $this->hubUrl($path) : null);
	}

	/**
	 * Previews what an action takes along for the selection of a list, or a single item of it, as HTML: deleting, see
	 * DeletionService::getPreview(), downloading files, listing the files sent and their size, exporting items, picking the
	 * exporter and format of the items exported (see BulkService::getExporters()), or deleting the transforms
	 * of files, listing them by file (see BaseController::transformsPreview()). The actions picking what they change the
	 * items to offer what they can be changed to (see previewPicked()); deleting users lists them along with the entries
	 * they're an author of, picking who those are transferred to, deleting the drafts of entries lists them by entry, and
	 * restoring trashed items with their relations lists the trashed elements restored along with each. The items are listed by
	 * the page asked for (`page`), the totals covering all of them. Responds with the number of items the action applies
	 * to, so nothing is offered without any.
	 */
	public function actionPreview(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		$hub = Hub::getInstance();
		$list = (string)$request->getRequiredBodyParam('list');
		$action = (string)$request->getBodyParam('bulkAction', BulkService::ACTION_DELETE);

		if (!($hub->bulk->getActions($list)[$action]['preview'] ?? false)) {
			throw new BadRequestHttpException('Unknown action.');
		}

		$item = (int)$request->getBodyParam('item');
		$this->requireSelectable($list, $item);
		$ids = $item > 0 ? [$item] : $hub->bulk->getSelection($list, (string)$request->getRequiredBodyParam('path'));
		$page = (int)$request->getBodyParam('page', 1);

		if ($action === BulkService::ACTION_DELETE_TRANSFORMS) {
			return $this->transformsPreview($ids, $page);
		}

		if ($hub->bulk->getActions($list)[$action]['fields'] ?? false) {
			return $this->previewPicked($list, $action, $ids);
		}

		if ($action === BulkService::ACTION_DELETE_DRAFTS) {
			[$entries, $drafts] = $hub->entries->getDeletableDrafts($ids);
			[$pageInfo, $shown] = (new Paginator($page))->items($entries);

			return $this->renderPreview('drafts-preview', count($entries), $ids, [
				'items' => array_map(fn (Entry $entry) => ['element' => $entry, 'count' => count($drafts[$entry->id]), 'children' => $drafts[$entry->id]], $shown),
				'pageInfo' => $pageInfo,
				'drafts' => array_sum(array_map('count', $drafts)),
			]);
		}

		if ($action === BulkService::ACTION_RESTORE_WITH_RELATIONS) {
			$service = Craft::$app->getElements();
			$elements = array_values(array_filter(
				$hub->bulk->getElements($list, $ids),
				fn (ElementInterface $element) => (!$element instanceof Asset || $element->keptFile) && $service->canSave($element),
			));
			$relations = $hub->bulk->getRestoredRelations(array_map(fn (ElementInterface $element) => (int)$element->id, $elements));
			$related = array_replace([], ...array_values($relations));
			[$pageInfo, $shown] = (new Paginator($page))->items($elements);

			return $this->renderPreview('relations-preview', count($elements), $ids, [
				'items' => array_map(fn (ElementInterface $element) => ['element' => $element, 'children' => array_values($relations[$element->id] ?? [])], $shown),
				'pageInfo' => $pageInfo,
				'entries' => count(array_filter($related, fn (ElementInterface $element) => $element instanceof Entry)),
				'files' => count(array_filter($related, fn (ElementInterface $element) => $element instanceof Asset)),
			]);
		}

		if ($list === ColumnService::LIST_USERS && $action === BulkService::ACTION_DELETE) {
			$users = $hub->users->getDeletableUsers($ids);
			$userIds = array_map(fn (User $user) => (int)$user->id, $users);
			$counts = $hub->users->getEntryCounts($userIds);
			[$pageInfo, $shown] = (new Paginator($page))->items($users);

			return $this->renderPreview('user-deletion-preview', count($users), $ids, [
				'items' => array_map(fn (User $user) => ['element' => $user, 'meta' => $user->email, 'count' => $counts[$user->id] ?? 0], $shown),
				'pageInfo' => $pageInfo,
				'entries' => array_sum($counts),
				'inheritors' => $hub->users->getInheritors($userIds),
			]);
		}

		if ($action === BulkService::ACTION_EXPORT) {
			$elements = $hub->bulk->getExportableElements($list, $ids);

			return $this->asJson([
				'total' => count($elements),
				'html' => $this->getView()->renderTemplate('hub/_components/export-preview', [
					'total' => count($elements),
					'skipped' => count(array_unique($ids)) - count($elements),
					'exporters' => $hub->bulk->getExporters($list),
					'formats' => $hub->bulk->getExportFormats(),
				], View::TEMPLATE_MODE_CP),
			]);
		}

		if ($action === BulkService::ACTION_DOWNLOAD) {
			$assets = $hub->bulk->getDownloadableFiles($ids);
			[$pageInfo, $shown] = (new Paginator($page))->items($assets);

			return $this->asJson([
				'total' => count($assets),
				'html' => $this->getView()->renderTemplate('hub/_components/download-preview', [
					'assets' => $shown,
					'pageInfo' => $pageInfo,
					'skipped' => count(array_unique($ids)) - count($assets),
					'size' => array_sum(array_map(fn (Asset $asset) => (int)$asset->size, $assets)),
					'volumes' => count(array_unique(array_map(fn (Asset $asset) => (int)$asset->volumeId, $assets))),
				], View::TEMPLATE_MODE_CP),
			]);
		}

		$preview = $hub->deletion->getPreview($list, $ids, $page);

		return $this->asJson([
			'total' => $preview->total,
			'html' => $this->getView()->renderTemplate('hub/_components/deletion-preview', ['preview' => $preview, 'pageInfo' => $preview->pageInfo], View::TEMPLATE_MODE_CP),
		]);
	}

	/**
	 * Previews an action picking what it changes the items to, offering what they can be changed to: the users who may
	 * author the entries whose authors can be changed, the sections the entries can be moved to, the folders the files can
	 * be moved to, the transforms of the files Craft can transform, or the groups the users can be added to and removed from.
	 * Nothing is offered without any, so the action applies to none of the items then.
	 *
	 * @param int[] $ids
	 */
	private function previewPicked(string $list, string $action, array $ids): Response
	{
		$hub = Hub::getInstance();
		$service = Craft::$app->getElements();

		if ($action === BulkService::ACTION_CHANGE_AUTHOR) {
			$entries = array_values(array_filter($hub->bulk->getElementsOf($list, $ids, Entry::class), fn (Entry $entry) => $hub->entries->canChangeAuthors($entry)));
			$authors = $hub->entries->getAuthorCandidates($entries);

			return $this->renderPreview('author-preview', $authors !== [] ? count($entries) : 0, $ids, ['authors' => $authors]);
		}

		if ($action === BulkService::ACTION_MOVE_TO_SECTION) {
			$entries = $hub->bulk->getElementsOf($list, $ids, Entry::class);
			$targets = $hub->entries->getSectionTargets($entries);
			$movable = array_filter($entries, function (Entry $entry) use ($hub, $targets) {
				foreach ($targets as $target) {
					if ($hub->entries->canMoveTo($entry, $target['section'])) {
						return true;
					}
				}

				return false;
			});

			return $this->renderPreview('section-preview', count($movable), $ids, ['targets' => $targets]);
		}

		if ($action === BulkService::ACTION_MOVE_TO_FOLDER) {
			$folders = $hub->files->getFolderTargets();
			$movable = array_filter($hub->bulk->getElementsOf($list, $ids, Asset::class), fn (Asset $asset) => $service->canSave($asset) && $service->canDelete($asset));

			return $this->renderPreview('folder-preview', $folders !== [] ? count($movable) : 0, $ids, ['folders' => $folders]);
		}

		if ($action === BulkService::ACTION_GENERATE_TRANSFORMS) {
			$transforms = $hub->files->getNamedTransforms();
			$assets = $hub->files->getTransformableFiles($ids);

			return $this->renderPreview('generate-transforms-preview', $transforms !== [] ? count($assets) : 0, $ids, ['transforms' => $transforms]);
		}

		$current = Craft::$app->getUser()->getIdentity();
		$users = array_values(array_filter($hub->bulk->getElementsOf($list, $ids, User::class), fn (User $user) => $user->id !== $current?->id && $service->canSave($user)));
		$groups = $hub->users->getAssignableGroups();

		return $this->renderPreview('groups-preview', $groups !== [] ? count($users) : 0, $ids, [
			'groups' => $groups,
			'counts' => $hub->users->getGroupCounts(array_map(fn (User $user) => (int)$user->id, $users)),
		]);
	}

	/**
	 * Responds with a preview of an action, rendered by its component, with the number of the items it applies to, and the
	 * number of the selected items left out.
	 *
	 * @param int[] $ids
	 * @param array<string,mixed> $variables
	 */
	private function renderPreview(string $component, int $total, array $ids, array $variables): Response
	{
		return $this->asJson([
			'total' => $total,
			'html' => $this->getView()->renderTemplate("hub/_components/$component", [
				...$variables,
				'total' => $total,
				'skipped' => count(array_unique($ids)) - $total,
			], View::TEMPLATE_MODE_CP),
		]);
	}

	/**
	 * Changes the selection of a list on a page, in this order: clearing it, unselecting and selecting items by their IDs,
	 * then selecting or unselecting all items matching the filters the list was last shown with (`matching`: `add` or
	 * `remove`). A change that would select more than BulkService::SELECTION_LIMIT items is refused, keeping the
	 * selection as it was. Responds with the selection.
	 */
	public function actionSelect(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		/** @var BulkService $bulk */
		$bulk = Hub::getInstance()->bulk;
		$list = (string)$request->getRequiredBodyParam('list');
		$path = (string)$request->getRequiredBodyParam('path');

		// Lists without actions have nothing to select.
		if ($bulk->getActions($list) === []) {
			throw new BadRequestHttpException('Unknown list.');
		}

		$this->requireSelectable($list);

		$kept = $bulk->getSelection($list, $path);
		$ids = $request->getBodyParam('clear') ? [] : $kept;
		$ids = array_values(array_diff($ids, $this->idsBodyParam('remove')));
		$ids = [...$ids, ...$this->idsBodyParam('add')];

		$filter = $bulk->getRememberedFilter($list, $path);
		$matching = $request->getBodyParam('matching');

		if ($filter !== null && $matching === 'remove') {
			$ids = array_values(array_diff($ids, $bulk->getMatchingIds($list, $filter, $ids)));
		} elseif ($filter !== null && $matching === 'add') {
			// One more than the limit is enough to tell that it is exceeded.
			$ids = [...$ids, ...$bulk->getMatchingIds($list, $filter, limit: BulkService::SELECTION_LIMIT + 1)];
		}

		if (count(array_unique($ids)) > BulkService::SELECTION_LIMIT) {
			return $this->asJson([
				'ids' => $kept,
				'error' => Craft::t('hub', 'Up to {limit, number} items can be selected at once. Narrow down the filters, or apply an action to the selected items first.', ['limit' => BulkService::SELECTION_LIMIT]),
			]);
		}

		return $this->asJson(['ids' => $bulk->setSelection($list, $path, $ids)]);
	}

	/**
	 * Requires the user to be able to select several items of a list, unless a single item is picked from its context menu.
	 *
	 * @throws ForbiddenHttpException
	 */
	private function requireSelectable(string $list, int $item = 0): void
	{
		if ($item === 0 && !Hub::getInstance()->bulk->canSelect($list)) {
			throw new ForbiddenHttpException('User is not authorized to bulk-edit these items.');
		}
	}

	/**
	 * Positive IDs of a body param holding a list of them.
	 *
	 * @return int[]
	 */
	private function idsBodyParam(string $name): array
	{
		$ids = Craft::$app->getRequest()->getBodyParam($name);

		return is_array($ids) ? array_values(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)) : [];
	}

	/**
	 * Sends the items the user may view exported by the exporter picked (`exporter`, the first one by default), in the format
	 * picked (`format`, CSV by default) for formattable ones, like Craft's export of element indexes, recording the items
	 * exported in the activity log as a batch.
	 *
	 * @param int[] $ids
	 * @throws BadRequestHttpException
	 */
	private function export(string $list, array $ids, string $label): ?Response
	{
		$request = Craft::$app->getRequest();
		$hub = Hub::getInstance();
		$exporters = $hub->bulk->getExporters($list);
		$exporter = $exporters[(string)$request->getBodyParam('exporter', array_key_first($exporters))] ?? null;
		$format = (string)$request->getBodyParam('format', CraftResponse::FORMAT_CSV);

		if ($exporter === null || ($exporter::isFormattable() && !isset($hub->bulk->getExportFormats()[$format]))) {
			throw new BadRequestHttpException('Unknown exporter or format.');
		}

		$elements = $hub->bulk->getExportableElements($list, $ids);
		$query = $hub->bulk->getExportQuery($list, $elements);

		if ($elements === [] || $query === null) {
			return $this->asFailure(Craft::t('hub', '“{action}” couldn’t be applied to the selected items.', ['action' => $label]));
		}

		$subject = ActivitySubject::fromList($list) ?? ActivitySubject::Element;
		$hub->activity->beginBatch(ActivityAction::Bulk, $subject, $label);

		foreach ($elements as $element) {
			$hub->activity->record(ActivityAction::Exported, $subject, (int)$element->id, $element->getUiLabel(), siteId: $element instanceof User ? null : $element->siteId);
		}

		$hub->activity->endBatch();

		// The filename is set before exporting, in case the exporter starts sending the data.
		$filename = $exporter->getFilename();

		if ($exporter::isFormattable()) {
			$this->response->format = $format;
			$filename .= '.' . $format;
		}

		$this->response->setDownloadHeaders($filename);
		$export = $exporter->export($query);

		if ($exporter::isFormattable()) {
			$export = is_callable($export) ? $export() : $export;

			if (!is_iterable($export)) {
				throw new InvalidValueException(get_class($exporter) . '::export() must return an array or generator function since isFormattable() returns true.');
			}

			$this->response->data = $export;

			if ($format === CraftResponse::FORMAT_JSON) {
				$this->response->formatters[CraftResponse::FORMAT_JSON]['prettyPrint'] = true;
			} elseif ($format === CraftResponse::FORMAT_XML) {
				Craft::$app->language = 'en-US';
				$this->response->formatters[CraftResponse::FORMAT_XML]['rootTag'] = StringHelper::toCamelCase($query->elementType::pluralLowerDisplayName());
			}
		} elseif (is_callable($export) || is_resource($export) || (is_array($export) && isset($export[0]) && is_resource($export[0]))) {
			$this->response->stream = $export;
		} else {
			$this->response->data = $export;
			$this->response->format = CraftResponse::FORMAT_RAW;
		}

		return $this->response;
	}

	/**
	 * Sends a single file as it is and several in a zip archive, like Craft's asset download, by volume and path, recording
	 * the files downloaded in the activity log as a batch.
	 *
	 * @param Asset[] $assets
	 */
	private function download(array $assets, string $label): ?Response
	{
		if ($assets === []) {
			return $this->asFailure(Craft::t('hub', '“{action}” couldn’t be applied to the selected items.', ['action' => $label]));
		}

		$activity = Hub::getInstance()->activity;
		$activity->beginBatch(ActivityAction::Bulk, ActivitySubject::Asset, $label);

		foreach ($assets as $asset) {
			$activity->record(ActivityAction::Downloaded, ActivitySubject::Asset, $asset->id, $asset->getUiLabel(), siteId: $asset->siteId);
		}

		$activity->endBatch();

		if (count($assets) === 1) {
			$asset = $assets[0];

			return $this->response->sendStreamAsFile($asset->getStream(), $asset->getFilename(), [
				'fileSize' => $asset->size,
				'mimeType' => $asset->getMimeType(),
			]);
		}

		$path = Craft::$app->getPath()->getTempPath() . '/' . StringHelper::UUID() . '.zip';
		$zip = new ZipArchive();

		if ($zip->open($path, ZipArchive::CREATE) !== true) {
			throw new Exception('Cannot create zip at ' . $path);
		}

		App::maxPowerCaptain();

		foreach ($assets as $asset) {
			$zip->addFromString($asset->getVolume()->name . '/' . $asset->getPath(), $asset->getContents());
		}

		$zip->close();
		$this->response->on(Response::EVENT_AFTER_SEND, fn () => FileHelper::unlink($path));

		return $this->response->sendFile($path, 'files.zip');
	}
}
