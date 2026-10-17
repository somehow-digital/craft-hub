<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use yii\base\Component;

/**
 * Columns of the lists, which users can show, hide and reorder; their choice is kept in their user preferences.
 * The column naming each row comes first in every list and is always shown, so it isn't one of them.
 */
class ColumnService extends Component
{
	public const LIST_SECTIONS = 'sections';
	public const LIST_ENTRY_TYPES = 'entryTypes';
	public const LIST_FIELDS = 'fields';
	public const LIST_VOLUMES = 'volumes';
	public const LIST_ENTRIES = 'entries';
	public const LIST_FILES = 'files';

	/** user preference holding the columns of each list, as their order and the hidden ones */
	public const PREFERENCE = 'hubColumns';

	/**
	 * Columns of a list in their default order. Sortable columns are named like the column they sort by.
	 *
	 * @return array<string,array{label:string,title?:string,class?:string,labelHidden?:bool}>
	 */
	public function getDefinitions(string $list): array
	{
		return match ($list) {
			self::LIST_SECTIONS => [
				'type' => ['label' => Craft::t('app', 'Type')],
				'entryTypes' => ['label' => Craft::t('hub', 'Entry Types')],
				'uri' => ['label' => Craft::t('hub', 'URI')],
				'entries' => ['label' => Craft::t('app', 'Entries'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'Enabled / Drafts / Disabled')],
				'references' => ['label' => Craft::t('hub', 'References'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'References from Entries and Link fields into this section')],
				'updated' => ['label' => Craft::t('hub', 'Last updated')],
				'diagnostics' => ['label' => Craft::t('hub', 'Diagnostics')],
			],
			self::LIST_ENTRY_TYPES => [
				'usage' => ['label' => Craft::t('hub', 'Usage')],
				'relations' => ['label' => Craft::t('hub', 'Relations'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'Sections and nested entry fields using this entry type, Entries fields that can reference and Link fields that can link to entries of this type')],
				'entries' => ['label' => Craft::t('app', 'Entries'), 'class' => 'hub-num'],
				'updated' => ['label' => Craft::t('hub', 'Last updated')],
				'diagnostics' => ['label' => Craft::t('hub', 'Diagnostics')],
			],
			self::LIST_FIELDS => [
				'type' => ['label' => Craft::t('app', 'Type')],
				'layouts' => ['label' => Craft::t('hub', 'Relations')],
				'data' => ['label' => Craft::t('hub', 'Data'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'Nested entries or references')],
				'status' => ['label' => Craft::t('app', 'Status')],
				'diagnostics' => ['label' => Craft::t('hub', 'Diagnostics')],
			],
			self::LIST_VOLUMES => [
				'fs' => ['label' => Craft::t('app', 'Filesystem')],
				'types' => ['label' => Craft::t('hub', 'Types')],
				'uri' => ['label' => Craft::t('hub', 'URI')],
				'assets' => ['label' => Craft::t('app', 'Assets'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'With references / Without references')],
				'size' => ['label' => Craft::t('app', 'Size'), 'class' => 'hub-num'],
				'alt' => ['label' => Craft::t('hub', 'Missing alt text'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'Images without alternative text')],
				'references' => ['label' => Craft::t('hub', 'References'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'References from Assets and Link fields into this volume')],
				'uploaded' => ['label' => Craft::t('hub', 'Last upload')],
				'diagnostics' => ['label' => Craft::t('hub', 'Diagnostics')],
			],
			self::LIST_ENTRIES => [
				'status' => ['label' => Craft::t('app', 'Status')],
				'type' => ['label' => Craft::t('app', 'Entry Type')],
				'section' => ['label' => Craft::t('app', 'Section')],
				'field' => ['label' => Craft::t('app', 'Field'), 'title' => Craft::t('hub', 'Nested entry field holding the entry')],
				'owner' => ['label' => Craft::t('hub', 'Owner'), 'title' => Craft::t('hub', 'Element owning the nested entry')],
				'references' => ['label' => Craft::t('hub', 'References'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'References from relation fields to this entry')],
				'posted' => ['label' => Craft::t('app', 'Post Date')],
				'updated' => ['label' => Craft::t('hub', 'Last updated')],
				'diagnostics' => ['label' => Craft::t('hub', 'Diagnostics')],
				'url' => ['label' => Craft::t('app', 'URL'), 'class' => 'hub-visit-cell', 'labelHidden' => true],
			],
			self::LIST_FILES => [
				'volume' => ['label' => Craft::t('app', 'Volume')],
				'kind' => ['label' => Craft::t('app', 'Type')],
				'dimensions' => ['label' => Craft::t('app', 'Dimensions'), 'class' => 'hub-num'],
				'size' => ['label' => Craft::t('app', 'Size'), 'class' => 'hub-num'],
				'references' => ['label' => Craft::t('hub', 'References'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'References from relation fields to this file')],
				'uploaded' => ['label' => Craft::t('hub', 'Uploaded')],
				'diagnostics' => ['label' => Craft::t('hub', 'Diagnostics')],
				'url' => ['label' => Craft::t('app', 'URL'), 'class' => 'hub-visit-cell', 'labelHidden' => true],
			],
			default => [],
		};
	}

	/**
	 * Columns of a list in the order the current user chose, with whether they show them.
	 * Columns added since they chose are shown, after the others.
	 *
	 * @param string[] $omit columns the list doesn't have on this page, like the section on a section's page
	 * @return list<array{key:string,label:string,title:string|null,class:string|null,labelHidden:bool,visible:bool}>
	 */
	public function getColumns(string $list, array $omit = []): array
	{
		$definitions = array_diff_key($this->getDefinitions($list), array_flip($omit));
		['order' => $order, 'hidden' => $hidden] = $this->getPreference($list);

		$keys = array_unique([...array_intersect($order, array_keys($definitions)), ...array_keys($definitions)]);

		return array_map(fn (string $key) => [
			'key' => $key,
			'label' => $definitions[$key]['label'],
			'title' => $definitions[$key]['title'] ?? null,
			'class' => $definitions[$key]['class'] ?? null,
			'labelHidden' => $definitions[$key]['labelHidden'] ?? false,
			'visible' => !in_array($key, $hidden, true),
		], array_values($keys));
	}

	/**
	 * Keys of the columns the user shows; the data of hidden ones isn't loaded, until they are shown.
	 *
	 * @param list<array{key:string,visible:bool}> $columns columns of a list, see {@see getColumns()}
	 * @return string[]
	 */
	public function getShownKeys(array $columns): array
	{
		return array_values(array_map(fn (array $column) => $column['key'], array_filter($columns, fn (array $column) => $column['visible'])));
	}

	/**
	 * Keeps the order and the hidden ones of the given columns of a list; unknown columns are left out.
	 * Columns not given, like those a page omits, keep their place among them and whether they are shown.
	 *
	 * @param string[] $order
	 * @param string[] $hidden
	 */
	public function saveColumns(string $list, array $order, array $hidden): void
	{
		$known = array_keys($this->getDefinitions($list));
		$order = array_values(array_unique(array_intersect($order, $known)));
		$hidden = array_intersect($hidden, $order);
		$saved = $this->getPreference($list);

		// The given columns take the places of the given columns in the full order, one after the other.
		$previous = array_values(array_unique([...array_intersect($saved['order'], $known), ...$known]));
		$next = $order;
		$merged = [];
		foreach ($previous as $key) {
			$merged[] = in_array($key, $order, true) ? array_shift($next) : $key;
		}

		$this->savePreference($list, [
			'order' => $merged,
			'hidden' => array_values(array_unique([...array_diff($saved['hidden'], $order), ...$hidden])),
		]);
	}

	/**
	 * Shows the columns of a list in their default order again.
	 */
	public function resetColumns(string $list): void
	{
		$this->savePreference($list, null);
	}

	/**
	 * @return array{order:string[],hidden:string[]}
	 */
	private function getPreference(string $list): array
	{
		$userId = Craft::$app->getUser()->getId();
		$lists = $userId !== null ? Craft::$app->getUsers()->getUserPreference((int)$userId, self::PREFERENCE, []) : [];
		$value = is_array($lists) ? ($lists[$list] ?? []) : [];

		return [
			'order' => $this->strings($value['order'] ?? []),
			'hidden' => $this->strings($value['hidden'] ?? []),
		];
	}

	/**
	 * @param array{order:string[],hidden:string[]}|null $value null to forget the columns of the list
	 */
	private function savePreference(string $list, ?array $value): void
	{
		$user = Craft::$app->getUser()->getIdentity();

		if ($user === null) {
			return;
		}

		$users = Craft::$app->getUsers();
		$lists = $users->getUserPreference($user->id, self::PREFERENCE, []);
		$lists = is_array($lists) ? $lists : [];

		if ($value !== null) {
			$lists[$list] = $value;
		} else {
			unset($lists[$list]);
		}

		$users->saveUserPreferences($user, [self::PREFERENCE => $lists]);
	}

	/**
	 * @return string[]
	 */
	private function strings(mixed $values): array
	{
		return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
	}
}
