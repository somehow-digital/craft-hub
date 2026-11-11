<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\base\ElementExporterInterface;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use craft\helpers\StringHelper;
use craft\web\Response;
use SomehowDigital\Craft\Hub\base\SendsFileInterface;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\ListType;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\ActivityService;
use SomehowDigital\Craft\Hub\services\BulkService;
use yii\base\Application;
use yii\base\InvalidValueException;
use yii\web\BadRequestHttpException;

/**
 * Sends the items the user may view exported by the exporter picked (`exporter`, the first one by default), in the
 * format picked (`format`, CSV by default) for formattable ones, like Craft's export of element indexes, recording them
 * in the activity log as a batch.
 *
 * @internal
 */
final class Export extends BaseBulkAction implements SendsFileInterface
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly ActivityService $activity,
		private readonly Application $craftApp,
	) {
		parent::__construct($list, $access);
	}

	/** page and permission of exporting each list */
	private const PERMISSIONS = [
		ListType::Entries->value => [Page::Entries, Permission::ExportEntries->value],
		ListType::Files->value => [Page::Assets, Permission::ExportAssets->value],
		ListType::Users->value => [Page::Users, Permission::ExportUsers->value],
		ListType::TrashedEntries->value => [Page::Entries, Permission::ExportTrashedEntries->value],
		ListType::TrashedFiles->value => [Page::Assets, Permission::ExportTrashedAssets->value],
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

		return $this->access->canAct($page, $permission);
	}

	public function preview(array $ids, int $page): BulkPreview
	{
		$bulk = $this->bulk;

		return new BulkPreview('export-preview', count($bulk->getExportableElements($this->list, $ids)), [
			'exporters' => $bulk->getExporters($this->list),
			'formats' => $bulk->getExportFormats(),
		]);
	}

	public function send(Response $response, array $ids, array $params): ?Response
	{
		$exporters = $this->bulk->getExporters($this->list);
		$exporter = $exporters[Row::stringOrNull($params, 'exporter') ?? (string) array_key_first($exporters)] ?? null;
		$format = Row::stringOrNull($params, 'format') ?? Response::FORMAT_CSV;

		if ($exporter === null || ($exporter::isFormattable() && !isset($this->bulk->getExportFormats()[$format]))) {
			throw new BadRequestHttpException('Unknown exporter or format.');
		}

		$elements = $this->bulk->getExportableElements($this->list, $ids);
		$query = $this->bulk->getExportQuery($this->list, $elements);

		if ($elements === [] || !$query instanceof ElementQuery) {
			return null;
		}

		$this->record($elements);

		// The filename is set before exporting, in case the exporter starts sending the data.
		$filename = $exporter->getFilename();

		if ($exporter::isFormattable()) {
			$response->format = $format;
			$filename .= '.' . $format;
		}

		$response->setDownloadHeaders($filename);
		$export = $exporter->export($query);

		return $exporter::isFormattable()
			? $this->formatted($response, $exporter, $export, $format, $query)
			: $this->raw($response, $export);
	}

	/**
	 * Records the elements exported, as a batch.
	 *
	 * @param ElementInterface[] $elements
	 */
	private function record(array $elements): void
	{
		$subject = ActivitySubject::fromList($this->list) ?? ActivitySubject::Element;
		$this->activity->beginBatch(ActivityAction::Bulk, $subject, $this->definition()['label']);

		try {
			foreach ($elements as $element) {
				$this->activity->record(ActivityAction::Exported, $subject, (int) $element->id, $element->getUiLabel(), siteId: $element instanceof User ? null : $element->siteId);
			}
		} finally {
			$this->activity->endBatch();
		}
	}

	/**
	 * @param ElementQuery<int,ElementInterface> $query
	 */
	private function formatted(Response $response, ElementExporterInterface $exporter, mixed $export, string $format, ElementQuery $query): Response
	{
		$export = is_callable($export) ? $export() : $export;

		if (!is_iterable($export)) {
			throw new InvalidValueException($exporter::class . '::export() must return an array or generator function since isFormattable() returns true.');
		}

		$response->data = $export;

		if ($format === Response::FORMAT_JSON) {
			$this->format($response, Response::FORMAT_JSON, ['prettyPrint' => true]);
		} elseif ($format === Response::FORMAT_XML) {
			$this->craftApp->language = 'en-US';
			$this->format($response, Response::FORMAT_XML, ['rootTag' => StringHelper::toCamelCase($query->elementType::pluralLowerDisplayName())]);
		}

		return $response;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function format(Response $response, string $format, array $options): void
	{
		$current = $response->formatters[$format] ?? [];
		$response->formatters[$format] = [...(is_array($current) ? $current : []), ...$options];
	}

	private function raw(Response $response, mixed $export): Response
	{
		if (is_callable($export) || is_resource($export) || (is_array($export) && isset($export[0]) && is_resource($export[0]))) {
			$response->stream = $export;
		} else {
			$response->data = $export;
			$response->format = Response::FORMAT_RAW;
		}

		return $response;
	}
}
