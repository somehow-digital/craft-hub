<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use yii\base\Component;

/**
 * The items selected in a list for its bulk actions, kept in the session, per list and the page it is on (its path), so the
 * selection survives filtering, sorting and paging through the list, until an action is applied to it or it is cleared.
 */
class SelectionService extends Component
{
	/** most items selected at once, as the actions are applied to all of them within a single request */
	public const LIMIT = 1000;

	/**
	 * IDs of the items selected in a list on a page, in the order they were selected.
	 *
	 * @return int[]
	 */
	public function getSelection(string $list, string $path): array
	{
		$ids = Craft::$app->getSession()->get($this->sessionKey('selection', $list, $path));

		return is_array($ids) ? array_values(array_filter($ids, 'is_int')) : [];
	}

	/**
	 * Keeps the IDs as the selection of a list on a page, without duplicates; an empty selection is dropped.
	 *
	 * @param int[] $ids
	 * @return int[] the kept selection
	 */
	public function setSelection(string $list, string $path, array $ids): array
	{
		$session = Craft::$app->getSession();
		$key = $this->sessionKey('selection', $list, $path);
		$ids = array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));

		$ids !== [] ? $session->set($key, $ids) : $session->remove($key);

		return $ids;
	}

	/**
	 * Keeps the filters a list on a page was last shown with, so all items matching them can be selected.
	 */
	public function rememberFilter(string $list, string $path, object $filter): void
	{
		Craft::$app->getSession()->set($this->sessionKey('filter', $list, $path), $filter);
	}

	/**
	 * Filters a list on a page was last shown with, if any.
	 */
	public function getRememberedFilter(string $list, string $path): ?object
	{
		$filter = Craft::$app->getSession()->get($this->sessionKey('filter', $list, $path));

		return is_object($filter) ? $filter : null;
	}

	private function sessionKey(string $kind, string $list, string $path): string
	{
		return "hub:bulk:$kind:$list:$path";
	}
}
