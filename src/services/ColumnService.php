<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use SomehowDigital\Craft\Hub\enums\ListType;
use SomehowDigital\Craft\Hub\Hub;
use yii\base\Component;

/**
 * Columns of the lists, which users can show, hide and reorder; their choice is kept in their user preferences.
 * The column naming each row comes first in every list and is always shown, so it isn't one of them.
 * Fixed columns, those linking to the settings, the URL or the edit page of each row, are always shown last, in their order;
 * users can't hide or reorder them.
 */
class ColumnService extends Component
{
	public const LIST_SECTIONS = ListType::Sections->value;
	public const LIST_ENTRY_TYPES = ListType::EntryTypes->value;
	public const LIST_FIELDS = ListType::Fields->value;
	public const LIST_VOLUMES = ListType::Volumes->value;
	public const LIST_ENTRIES = ListType::Entries->value;
	public const LIST_FILES = ListType::Files->value;
	public const LIST_USERS = ListType::Users->value;
	public const LIST_USER_GROUPS = ListType::UserGroups->value;
	public const LIST_TRASHED_ENTRIES = ListType::TrashedEntries->value;
	public const LIST_TRASHED_FILES = ListType::TrashedFiles->value;
	public const LIST_ACTIVITY = ListType::Activity->value;

	/** user preference holding the columns of each list, as their order and the hidden ones */
	public const PREFERENCE = 'hubColumns';

	/**
	 * Columns of a list in their default order. Sortable columns are named like the column they sort by.
	 *
	 * @return array<string,array{label:string,title?:string,class?:string,labelHidden?:bool,fixed?:bool}>
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
				'updated' => ['label' => Craft::t('hub', 'Last edited'), 'title' => Craft::t('hub', 'When a person last edited any of the entries')],
				'maintainers' => ['label' => Craft::t('hub', 'Maintainers'), 'title' => Craft::t('hub', 'Who to ask for help')],
				'settings' => ['label' => Craft::t('app', 'Settings'), 'class' => 'hub-visit-cell', 'labelHidden' => true, 'fixed' => true],
			],
			self::LIST_ENTRY_TYPES => [
				'usage' => ['label' => Craft::t('hub', 'Usage')],
				'relations' => ['label' => Craft::t('hub', 'Relations'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'Sections and nested entry fields using this entry type, Entries fields that can reference and Link fields that can link to entries of this type')],
				'entries' => ['label' => Craft::t('app', 'Entries'), 'class' => 'hub-num'],
				'updated' => ['label' => Craft::t('hub', 'Last edited'), 'title' => Craft::t('hub', 'When a person last edited any of the entries')],
				'maintainers' => ['label' => Craft::t('hub', 'Maintainers'), 'title' => Craft::t('hub', 'Who to ask for help')],
				'settings' => ['label' => Craft::t('app', 'Settings'), 'class' => 'hub-visit-cell', 'labelHidden' => true, 'fixed' => true],
			],
			self::LIST_FIELDS => [
				'type' => ['label' => Craft::t('app', 'Type')],
				'layouts' => ['label' => Craft::t('hub', 'Relations')],
				'data' => ['label' => Craft::t('hub', 'Data'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'Nested entries or references')],
				'status' => ['label' => Craft::t('app', 'Status')],
				'maintainers' => ['label' => Craft::t('hub', 'Maintainers'), 'title' => Craft::t('hub', 'Who to ask for help')],
				'settings' => ['label' => Craft::t('app', 'Settings'), 'class' => 'hub-visit-cell', 'labelHidden' => true, 'fixed' => true],
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
				'maintainers' => ['label' => Craft::t('hub', 'Maintainers'), 'title' => Craft::t('hub', 'Who to ask for help')],
				'settings' => ['label' => Craft::t('app', 'Settings'), 'class' => 'hub-visit-cell', 'labelHidden' => true, 'fixed' => true],
			],
			self::LIST_ENTRIES => [
				'status' => ['label' => Craft::t('app', 'Status')],
				'type' => ['label' => Craft::t('app', 'Entry Type')],
				'origin' => ['label' => Craft::t('hub', 'Origin'), 'title' => Craft::t('hub', 'Section or nested entry field holding the entry')],
				'owner' => ['label' => Craft::t('hub', 'Owner'), 'title' => Craft::t('hub', 'Element owning the nested entry')],
				'creator' => ['label' => Craft::t('hub', 'Creator'), 'title' => Craft::t('hub', 'Authors of the entry')],
				'references' => ['label' => Craft::t('hub', 'References'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'References from relation fields to this entry')],
				'posted' => ['label' => Craft::t('app', 'Post Date')],
				'updated' => ['label' => Craft::t('hub', 'Last edited'), 'title' => Craft::t('hub', 'When a person last edited the entry')],
				'maintainers' => ['label' => Craft::t('hub', 'Maintainers'), 'title' => Craft::t('hub', 'Who to ask for help')],
				'url' => ['label' => Craft::t('app', 'URL'), 'class' => 'hub-visit-cell', 'labelHidden' => true, 'fixed' => true],
				'edit' => ['label' => Craft::t('app', 'Edit'), 'class' => 'hub-visit-cell', 'labelHidden' => true, 'fixed' => true],
			],
			self::LIST_FILES => [
				'volume' => ['label' => Craft::t('app', 'Volume')],
				'kind' => ['label' => Craft::t('app', 'Type')],
				'dimensions' => ['label' => Craft::t('app', 'Dimensions'), 'class' => 'hub-num'],
				'size' => ['label' => Craft::t('app', 'Size'), 'class' => 'hub-num'],
				'references' => ['label' => Craft::t('hub', 'References'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'References from relation fields to this file')],
				'uploaded' => ['label' => Craft::t('hub', 'Uploaded')],
				'uploader' => ['label' => Craft::t('hub', 'Uploader'), 'title' => Craft::t('hub', 'User who uploaded the file')],
				'maintainers' => ['label' => Craft::t('hub', 'Maintainers'), 'title' => Craft::t('hub', 'Who to ask for help')],
				'url' => ['label' => Craft::t('app', 'Download'), 'class' => 'hub-visit-cell', 'labelHidden' => true, 'fixed' => true],
				'edit' => ['label' => Craft::t('app', 'Edit'), 'class' => 'hub-visit-cell', 'labelHidden' => true, 'fixed' => true],
			],
			self::LIST_TRASHED_ENTRIES => [
				'type' => ['label' => Craft::t('app', 'Entry Type')],
				'origin' => ['label' => Craft::t('hub', 'Origin'), 'title' => Craft::t('hub', 'Section or nested entry field holding the entry')],
				'owner' => ['label' => Craft::t('hub', 'Owner'), 'title' => Craft::t('hub', 'Element owning the nested entry')],
				'deleted' => ['label' => Craft::t('hub', 'Deleted')],
				'deleter' => ['label' => Craft::t('hub', 'Deleted by'), 'title' => Craft::t('hub', 'User who deleted the entry, as recorded in the activity log')],
			],
			self::LIST_TRASHED_FILES => [
				'volume' => ['label' => Craft::t('app', 'Volume')],
				'kind' => ['label' => Craft::t('app', 'Type')],
				'size' => ['label' => Craft::t('app', 'Size'), 'class' => 'hub-num'],
				'file' => ['label' => Craft::t('app', 'File'), 'title' => Craft::t('hub', 'Whether the file was kept when the asset was deleted, so it can be restored')],
				'deleted' => ['label' => Craft::t('hub', 'Deleted')],
				'deleter' => ['label' => Craft::t('hub', 'Deleted by'), 'title' => Craft::t('hub', 'User who deleted the file, as recorded in the activity log')],
			],
			self::LIST_USERS => [
				'status' => ['label' => Craft::t('app', 'Status')],
				'email' => ['label' => Craft::t('app', 'Email')],
				'groups' => ['label' => Craft::t('app', 'User Groups')],
				'entries' => ['label' => Craft::t('app', 'Entries'), 'class' => 'hub-num', 'title' => Craft::t('hub', 'Entries the user is an author of')],
				'twoFactor' => ['label' => Craft::t('hub', '2FA'), 'title' => Craft::t('hub', 'Whether the user has set up an authenticator app or a passkey')],
				'login' => ['label' => Craft::t('app', 'Last Login')],
				'created' => ['label' => Craft::t('hub', 'Created')],
				'edit' => ['label' => Craft::t('app', 'Edit'), 'class' => 'hub-visit-cell', 'labelHidden' => true, 'fixed' => true],
			],
			self::LIST_USER_GROUPS => [
				'description' => ['label' => Craft::t('app', 'Description')],
				'users' => ['label' => Craft::t('app', 'Users'), 'class' => 'hub-num'],
				'permissions' => ['label' => Craft::t('app', 'Permissions'), 'class' => 'hub-num'],
				'twoFactor' => ['label' => Craft::t('hub', 'Two-step verification')],
				'settings' => ['label' => Craft::t('app', 'Settings'), 'class' => 'hub-visit-cell', 'labelHidden' => true, 'fixed' => true],
			],
			self::LIST_ACTIVITY => [
				'action' => ['label' => Craft::t('hub', 'Action')],
				'user' => ['label' => Craft::t('app', 'User'), 'title' => Craft::t('hub', 'User who did it')],
				'details' => ['label' => Craft::t('hub', 'Details')],
				'site' => ['label' => Craft::t('app', 'Site')],
				'ip' => ['label' => Craft::t('hub', 'IP address')],
				'date' => ['label' => Craft::t('app', 'Date')],
				'batch' => ['label' => Craft::t('hub', 'Changes'), 'class' => 'hub-visit-cell', 'labelHidden' => true, 'fixed' => true],
			],
			default => [],
		};
	}

	/**
	 * Columns of a list in the order the current user chose, with whether they show them.
	 * Columns added since they chose are shown, after the others; fixed columns are always shown, last.
	 * The settings column links to the native settings, which only admins can open, so others don't get it; the maintainers
	 * column is only there while maintainers are enabled.
	 *
	 * @param string[] $omit columns the list doesn't have on this page, like the section on a section's page
	 * @return list<array{key:string,label:string,title:string|null,class:string|null,labelHidden:bool,fixed:bool,visible:bool}>
	 */
	public function getColumns(string $list, array $omit = []): array
	{
		if (!Craft::$app->getUser()->getIsAdmin()) {
			$omit[] = 'settings';
		}

		if (!Hub::getInstance()->maintainers->isEnabled()) {
			$omit[] = 'maintainers';
		}

		$definitions = array_diff_key($this->getDefinitions($list), array_flip($omit));
		['order' => $order, 'hidden' => $hidden] = $this->getPreference($list);

		$fixed = array_keys(array_filter($definitions, fn (array $definition) => $definition['fixed'] ?? false));
		$movable = array_diff(array_keys($definitions), $fixed);
		$keys = array_unique([...array_intersect($order, $movable), ...$movable, ...$fixed]);

		return array_map(fn (string $key) => [
			'key' => $key,
			'label' => $definitions[$key]['label'],
			'title' => $definitions[$key]['title'] ?? null,
			'class' => $definitions[$key]['class'] ?? null,
			'labelHidden' => $definitions[$key]['labelHidden'] ?? false,
			'fixed' => in_array($key, $fixed, true),
			'visible' => in_array($key, $fixed, true) || !in_array($key, $hidden, true),
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
	 * Keeps the order and the hidden ones of the given columns of a list; unknown and fixed columns are left out.
	 * Columns not given, like those a page omits, keep their place among them and whether they are shown.
	 *
	 * @param string[] $order
	 * @param string[] $hidden
	 */
	public function saveColumns(string $list, array $order, array $hidden): void
	{
		$known = array_keys(array_filter($this->getDefinitions($list), fn (array $definition) => !($definition['fixed'] ?? false)));
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
