<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\User;
use craft\helpers\StringHelper;
use craft\web\Response;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ColumnService;
use yii\base\InvalidValueException;
use yii\web\BadRequestHttpException;

/**
 * Sends the items the user may view exported by the exporter picked (`exporter`, the first one by default), in the format picked
 * (`format`, CSV by default) for formattable ones, like Craft's export of element indexes, recording them in the activity log
 * as a batch.
 */
class Export extends BulkAction implements SendsFile
{
	/** page and permission of exporting each list */
	private const PERMISSIONS = [
		ColumnService::LIST_ENTRIES => [Page::Entries, Hub::PERMISSION_EXPORT_ENTRIES],
		ColumnService::LIST_FILES => [Page::Assets, Hub::PERMISSION_EXPORT_ASSETS],
		ColumnService::LIST_USERS => [Page::Users, Hub::PERMISSION_EXPORT_USERS],
		ColumnService::LIST_TRASHED_ENTRIES => [Page::Entries, Hub::PERMISSION_EXPORT_TRASHED_ENTRIES],
		ColumnService::LIST_TRASHED_FILES => [Page::Assets, Hub::PERMISSION_EXPORT_TRASHED_ASSETS],
	];

	public function definition(): array
	{
		return [
			'label' => Craft::t('app', 'Export'),
			'icon' => 'file-export',
			'file' => true,
			'preview' => true,
			'titles' => [
				'one' => Craft::t('hub', 'Export “{name}”?'),
				'some' => Craft::t('hub', 'Export {count} selected items?'),
			],
			'loading' => Craft::t('hub', 'Checking which items are exported…'),
		];
	}

	public function isAvailable(): bool
	{
		[$page, $permission] = self::PERMISSIONS[$this->list];

		return $this->hub()->access->canAct($page, $permission);
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		$bulk = $this->hub()->bulk;

		return new BulkPreview('export-preview', count($bulk->getExportableElements($this->list, $ids)), [
			'exporters' => $bulk->getExporters($this->list),
			'formats' => $bulk->getExportFormats(),
		]);
	}

	public function send(Response $response, array $ids, array $params): ?Response
	{
		$hub = $this->hub();
		$exporters = $hub->bulk->getExporters($this->list);
		$exporter = $exporters[(string)($params['exporter'] ?? array_key_first($exporters))] ?? null;
		$format = (string)($params['format'] ?? Response::FORMAT_CSV);

		if ($exporter === null || ($exporter::isFormattable() && !isset($hub->bulk->getExportFormats()[$format]))) {
			throw new BadRequestHttpException('Unknown exporter or format.');
		}

		$elements = $hub->bulk->getExportableElements($this->list, $ids);
		$query = $hub->bulk->getExportQuery($this->list, $elements);

		if ($elements === [] || $query === null) {
			return null;
		}

		$subject = ActivitySubject::fromList($this->list) ?? ActivitySubject::Element;
		$hub->activity->beginBatch(ActivityAction::Bulk, $subject, $this->definition()['label']);

		foreach ($elements as $element) {
			$hub->activity->record(ActivityAction::Exported, $subject, (int)$element->id, $element->getUiLabel(), siteId: $element instanceof User ? null : $element->siteId);
		}

		$hub->activity->endBatch();

		// The filename is set before exporting, in case the exporter starts sending the data.
		$filename = $exporter->getFilename();

		if ($exporter::isFormattable()) {
			$response->format = $format;
			$filename .= '.' . $format;
		}

		$response->setDownloadHeaders($filename);
		$export = $exporter->export($query);

		if ($exporter::isFormattable()) {
			$export = is_callable($export) ? $export() : $export;

			if (!is_iterable($export)) {
				throw new InvalidValueException(get_class($exporter) . '::export() must return an array or generator function since isFormattable() returns true.');
			}

			$response->data = $export;

			if ($format === Response::FORMAT_JSON) {
				$response->formatters[Response::FORMAT_JSON]['prettyPrint'] = true;
			} elseif ($format === Response::FORMAT_XML) {
				Craft::$app->language = 'en-US';
				$response->formatters[Response::FORMAT_XML]['rootTag'] = StringHelper::toCamelCase($query->elementType::pluralLowerDisplayName());
			}
		} elseif (is_callable($export) || is_resource($export) || (is_array($export) && isset($export[0]) && is_resource($export[0]))) {
			$response->stream = $export;
		} else {
			$response->data = $export;
			$response->format = Response::FORMAT_RAW;
		}

		return $response;
	}
}
