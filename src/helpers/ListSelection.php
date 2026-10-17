<?php

namespace SomehowDigital\Craft\Hub\helpers;

use Craft;
use craft\elements\db\ElementQuery;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\SelectionService;

/**
 * The selection of a list on a page for its bulk actions, and whether only the selected items are shown, to review them
 * before applying an action. Nothing is selected in lists without actions, nor by users who may not bulk-edit the list,
 * who apply the actions to one item at a time, so they don't review a selection either.
 */
final class ListSelection
{
	/** query param of a list showing only its selected items, to review them before applying a bulk action */
	public const PARAM = 'selection';

	/**
	 * @param array<string,array<string,mixed>> $actions bulk actions of the list, see BulkService::getActions()
	 * @param bool $multiple whether several items can be selected, see BulkService::canSelect()
	 * @param int[] $ids IDs of the selected items
	 * @param bool $review whether only the selected items are shown
	 */
	public function __construct(
		public readonly string $list,
		public readonly string $path,
		private readonly array $actions,
		private readonly bool $multiple,
		public readonly array $ids,
		public readonly bool $review,
	) {
	}

	/**
	 * The selection of a list on a page, reviewed as the query params of the request ask for it.
	 *
	 * @param string $path page the list is on
	 */
	public static function fromRequest(string $list, string $path): self
	{
		$bulk = Hub::getInstance()->bulk;
		$actions = $bulk->getActions($list);
		$multiple = $actions !== [] && $bulk->canSelect($list);

		return new self(
			$list,
			$path,
			$actions,
			$multiple,
			$multiple ? Hub::getInstance()->selection->getSelection($list, $path) : [],
			$multiple && self::isRequested(),
		);
	}

	/**
	 * Whether the request asks for only the selected items of the list.
	 */
	public static function isRequested(): bool
	{
		return Craft::$app->getRequest()->getQueryParam(self::PARAM) === '1';
	}

	/**
	 * Limits a query to the selected items while reviewing them.
	 */
	public function limitQuery(ElementQuery $query): void
	{
		if ($this->review) {
			$query->id($this->ids !== [] ? $this->ids : [0]);
		}
	}

	/**
	 * Limits the items of a list, keyed by their IDs, to the selected ones while reviewing them.
	 *
	 * @template T
	 * @param array<int,T> $items
	 * @return array<int,T>
	 */
	public function limitItems(array $items): array
	{
		return $this->review ? array_intersect_key($items, array_flip($this->ids)) : $items;
	}

	/**
	 * Template variables of the list's bulk actions and its selection (see the `bulk` component).
	 * The filters are kept for selecting all items matching them, along with how many selected items they leave out.
	 * While reviewing, the filters are those the list is limited to, so selected items no longer in it, like deleted
	 * ones, are unselected.
	 *
	 * @param object $filter filters of the list shown
	 * @return array<string,mixed>
	 */
	public function variables(object $filter): array
	{
		if ($this->actions === []) {
			return ['bulkActions' => [], 'bulkSelection' => null];
		}

		$hub = Hub::getInstance();
		$bulk = $hub->bulk;
		$ids = $this->ids;
		$matching = $bulk->getMatchingIds($this->list, $filter, $ids);

		if ($this->review) {
			$ids = Hub::getInstance()->selection->setSelection($this->list, $this->path, array_values(array_intersect($ids, $matching)));
		} else {
			Hub::getInstance()->selection->rememberFilter($this->list, $this->path, $filter);
		}

		return [
			'bulkActions' => $this->actions,
			'bulkMultiple' => $this->multiple,
			'bulkSelection' => [
				'path' => $this->path,
				'ids' => $ids,
				'review' => $this->review,
				'outside' => $this->review ? 0 : count($this->ids) - count($matching),
				'limit' => SelectionService::LIMIT,
				'listUrl' => $hub->siteScope->pageUrl($this->path),
				'reviewUrl' => $hub->siteScope->pageUrl($this->path, [self::PARAM => 1]),
			],
		];
	}
}
