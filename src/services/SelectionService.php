<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use craft\web\Request as WebRequest;
use craft\web\Session;
use SomehowDigital\Craft\Hub\models\ListSelection;
use yii\base\Component;

/**
 * The items selected in a list for its bulk actions, kept in the session, per list and the page it is on (its path), so the
 * selection survives filtering, sorting and paging through the list, until an action is applied to it or it is cleared.
 *
 * @internal
 */
final class SelectionService extends Component
{
	/** most items selected at once, as the actions are applied to all of them within a single request */
	public const LIMIT = 1000;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly BulkService $bulk,
		private readonly SiteScopeService $siteScope,
		private readonly ?WebRequest $request,
		private readonly ?Session $session = null,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * IDs of the items selected in a list on a page, in the order they were selected.
	 *
	 * @return list<int>
	 */
	public function getSelection(string $list, string $path): array
	{
		$ids = $this->session?->get($this->sessionKey('selection', $list, $path));

		return is_array($ids) ? array_values(array_filter($ids, is_int(...))) : [];
	}

	/**
	 * Keeps the IDs as the selection of a list on a page, without duplicates; an empty selection is dropped.
	 *
	 * @param int[] $ids
	 * @return list<int> the kept selection
	 */
	public function setSelection(string $list, string $path, array $ids): array
	{
		$session = $this->session;
		$key = $this->sessionKey('selection', $list, $path);
		$ids = array_values(array_unique(array_filter($ids, fn(int $id): bool => $id > 0)));

		if ($ids !== []) {
			$session?->set($key, $ids);
		} else {
			$session?->remove($key);
		}

		return $ids;
	}

	/**
	 * Keeps the filters a list on a page was last shown with, so all items matching them can be selected.
	 */
	public function rememberFilter(string $list, string $path, object $filter): void
	{
		$this->session?->set($this->sessionKey('filter', $list, $path), $filter);
	}

	/**
	 * Filters a list on a page was last shown with, if any.
	 */
	public function getRememberedFilter(string $list, string $path): ?object
	{
		$filter = $this->session?->get($this->sessionKey('filter', $list, $path));

		return is_object($filter) ? $filter : null;
	}

	private function sessionKey(string $kind, string $list, string $path): string
	{
		return "hub:bulk:{$kind}:{$list}:{$path}";
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
	public function variables(ListSelection $selection, object $filter): array
	{
		if ($selection->actions === []) {
			return ['bulkActions' => [], 'bulkSelection' => null];
		}

		$ids = $selection->ids;
		$matching = $this->bulk->getMatchingIds($selection->list, $filter, $ids);

		if ($selection->review) {
			$ids = $this->setSelection($selection->list, $selection->path, array_values(array_intersect($ids, $matching)));
		} else {
			$this->rememberFilter($selection->list, $selection->path, $filter);
		}

		return [
			'bulkActions' => $selection->actions,
			'bulkMultiple' => $selection->multiple,
			'bulkSelection' => [
				'path' => $selection->path,
				'ids' => $ids,
				'review' => $selection->review,
				'outside' => $selection->review ? 0 : count($selection->ids) - count($matching),
				'limit' => self::LIMIT,
				'listUrl' => $this->siteScope->pageUrl($selection->path),
				'reviewUrl' => $this->siteScope->pageUrl($selection->path, [ListSelection::PARAM => 1]),
			],
		];
	}

	/**
	 * Whether the request asks for only the selected items of a list.
	 */
	public function isRequested(): bool
	{
		return $this->request?->getQueryParam(ListSelection::PARAM) === '1';
	}
}
