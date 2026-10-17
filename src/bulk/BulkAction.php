<?php

namespace SomehowDigital\Craft\Hub\bulk;

use craft\base\ElementInterface;
use SomehowDigital\Craft\Hub\Hub;

/**
 * An action applied to the items of a list (see ColumnService::LIST_*), offered by BulkService. It holds all there is to the
 * action: how it's offered, who it's offered to, what it applies to, its preview and what it does.
 */
abstract class BulkAction
{
	public function __construct(protected readonly string $list)
	{
	}

	/**
	 * How the action is offered in the menu of the list.
	 *
	 * The `confirm` text asks for confirmation; with a `preview`, only if the preview can't be loaded. The `preview` tells
	 * what the action applies to or takes along first, in a dialog titled after the action or its own `titles`, naming the
	 * item (`one`) or the number of items (`some`), which picks what it changes the items to if it has `fields`, so it's
	 * only applied from there. `labels` picks the labels to add and remove first, `keep` keeps the items selected, `file`
	 * sends a file rather than applying it in place, and `unless` leaves it out of the context menu of items in that state
	 * (see the `bulkItem` macro).
	 *
	 * @return array{label:string,icon:string,confirm?:string,preview?:bool,titles?:array{one:string,some:string},loading?:string,labels?:bool,keep?:bool,file?:bool,fields?:bool,unless?:string}
	 */
	abstract public function definition(): array;

	/**
	 * Whether the action is offered to the current user.
	 */
	abstract public function isAvailable(): bool;

	/**
	 * Applies the action to the items with the IDs, skipping those it doesn't apply to or that fail to save.
	 *
	 * @param int[] $ids
	 * @param array<string,mixed> $params what was picked in its preview
	 * @return int[] IDs of the items it was applied to
	 */
	public function apply(array $ids, array $params): array
	{
		return [];
	}

	/**
	 * Preview of the action for the items with the IDs, null for actions without one; items are listed by the page.
	 *
	 * @param int[] $ids
	 */
	public function preview(array $ids, int $page): ?BulkPreview
	{
		return null;
	}

	protected function hub(): Hub
	{
		return Hub::getInstance();
	}

	/**
	 * IDs of the elements the callback succeeded for.
	 *
	 * @template T of ElementInterface
	 * @param T[] $items
	 * @param callable(T):bool $callback
	 * @return int[]
	 */
	protected function succeeded(array $items, callable $callback): array
	{
		return array_values(array_map(
			fn (ElementInterface $item) => (int)$item->id,
			array_filter($items, fn (mixed $item) => $callback($item)),
		));
	}

	/**
	 * Positive integer of a param, or 0.
	 *
	 * @param array<string,mixed> $params
	 */
	protected function intParam(array $params, string $name): int
	{
		$value = $params[$name] ?? null;

		return is_numeric($value) && (int)$value > 0 ? (int)$value : 0;
	}

	/**
	 * Definition of an action picking what it changes the items to in its preview.
	 *
	 * @return array<string,mixed>
	 */
	protected function picked(string $label, string $icon, string $one, string $some, string $loading): array
	{
		return ['label' => $label, 'icon' => $icon, 'preview' => true, 'fields' => true, 'titles' => ['one' => $one, 'some' => $some], 'loading' => $loading];
	}
}
