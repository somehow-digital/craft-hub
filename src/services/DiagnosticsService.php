<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\StringHelper;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\helpers\Aggregator;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\models\SavedFilter;
use SomehowDigital\Craft\Hub\models\UserFilter;
use yii\base\Component;

/**
 * The checks of the diagnostics tabs, as configured on the diagnostics tab of the settings page, each flagging the items
 * a filter of its kind offered to everyone matches: Hub's default checks, each of one of Hub's predefined filters, which
 * can be disabled, reordered and given another severity, and custom ones, of any filter of their kind. Default checks
 * have a row once configured; until then, they are enabled, in their own order, before the custom ones.
 */
class DiagnosticsService extends Component
{
	public const TABLE_CHECKS = '{{%hub_checks}}';

	/** columns the elements are grouped by, by alias, to count the elements failing a check for all of them at once */
	private const GROUPS = [
		CheckKind::Entries->value => ['sectionId' => 'entries.sectionId', 'typeId' => 'entries.typeId'],
		CheckKind::Assets->value => ['volumeId' => 'assets.volumeId'],
		CheckKind::Users->value => [],
	];

	/** @var array<string,CheckDefinition>|null in their order, by their key */
	private ?array $definitions = null;

	/** @var array<string,array<string,int>> grouped counts of the elements failing each check, by its key and the site scope */
	private array $counts = [];

	/** @var array<string,array<int,int>> IDs of the items of a structure page failing each check, as keys, by its key */
	private array $failing = [];

	/**
	 * Hub's default checks of each kind, in their own order, by their handle, which is that of the predefined filter whose
	 * items they flag. Their descriptions say what's wrong with an item and what to do about it, or why it passed.
	 *
	 * @return array<string,array<string,array{name:string,description:string,passedDescription:string,severity:Severity}>> by the kind
	 */
	public function getDefaults(): array
	{
		$filters = Hub::getInstance()->filters;
		$check = fn (string $name, string $description, string $passedDescription, Severity $severity) => compact('name', 'description', 'passedDescription', 'severity');
		$stale = ['months' => $filters->getThreshold(CheckKind::Entries, 'stale')];
		$size = ['size' => $filters->getThreshold(CheckKind::Assets, 'large')];
		$pixels = ['pixels' => $filters->getThreshold(CheckKind::Assets, 'oversized')];
		$idle = ['months' => $filters->getThreshold(CheckKind::Users, 'idle')];

		return [
			CheckKind::Entries->value => [
				'unreferenced' => $check(
					Craft::t('hub', 'Unreferenced'),
					Craft::t('hub', 'No element references this entry, through a relation field or rich text. Unless it has a URL or templates query it directly, it is not shown anywhere.'),
					Craft::t('hub', 'An element references this entry.'),
					Severity::Notice,
				),
				'stale' => $check(
					Craft::t('hub', 'Stale'),
					Craft::t('hub', 'No person has edited this entry for {months, plural, =1{one month} other{# months}}. Check whether it is still accurate.', $stale),
					Craft::t('hub', 'A person has edited this entry within the last {months, plural, =1{month} other{# months}}.', $stale),
					Severity::Notice,
				),
				'drafts' => $check(
					Craft::t('hub', 'Drafts'),
					Craft::t('hub', 'This entry has drafts. Publish or discard them, so changes don’t get lost.'),
					Craft::t('hub', 'The entry has no drafts.'),
					Severity::Notice,
				),
				'disabled' => $check(
					Craft::t('hub', 'Disabled'),
					Craft::t('hub', 'This entry is disabled. Delete it if it is no longer needed.'),
					Craft::t('hub', 'The entry is enabled.'),
					Severity::Notice,
				),
			],
			CheckKind::Assets->value => [
				'unused' => $check(
					Craft::t('hub', 'Unreferenced'),
					Craft::t('hub', 'No element references this asset. Delete it if it is no longer needed.'),
					Craft::t('hub', 'An element references this asset.'),
					Severity::Warning,
				),
				'missingAlt' => $check(
					Craft::t('hub', 'Alternative text'),
					Craft::t('hub', 'This image has no alternative text. Add one, so it is accessible.'),
					Craft::t('hub', 'The asset has alternative text, or isn’t an image.'),
					Severity::Warning,
				),
				'large' => $check(
					Craft::t('hub', 'File size'),
					Craft::t('hub', 'This file is larger than {size, number} MB. Compress or resize it, as large files slow down uploads, backups and transforms.', $size),
					Craft::t('hub', 'The file isn’t larger than {size, number} MB.', $size),
					Severity::Warning,
				),
				'oversized' => $check(
					Craft::t('hub', 'Image dimensions'),
					Craft::t('hub', 'This image is wider or taller than {pixels, number} px. Downscale it, as larger images are rarely needed.', $pixels),
					Craft::t('hub', 'The asset isn’t wider or taller than {pixels, number} px, or isn’t an image.', $pixels),
					Severity::Notice,
				),
			],
			CheckKind::Users->value => [
				'locked' => $check(
					Craft::t('hub', 'Locked'),
					Craft::t('hub', 'This user is locked after too many invalid sign-in attempts. Unlock them if the attempts were theirs.'),
					Craft::t('hub', 'The user isn’t locked.'),
					Severity::Warning,
				),
				'adminsWithoutTwoFactor' => $check(
					Craft::t('hub', 'Two-step verification'),
					Craft::t('hub', 'This admin hasn’t set up an authenticator app or a passkey. Admins can do anything, so their accounts should be protected by more than a password.'),
					Craft::t('hub', 'The user isn’t an admin, or has set up an authenticator app or a passkey.'),
					Severity::Warning,
				),
				'idle' => $check(
					Craft::t('hub', 'Sign-ins'),
					Craft::t('hub', 'This user hasn’t signed in for {months, plural, =1{one month} other{# months}}. Suspend or delete them if they no longer need an account.', $idle),
					Craft::t('hub', 'The user has signed in within the last {months, plural, =1{month} other{# months}}, or isn’t active.', $idle),
					Severity::Notice,
				),
				'pending' => $check(
					Craft::t('hub', 'Pending'),
					Craft::t('hub', 'This user hasn’t activated their account. Send the activation email again, or delete the user if they no longer need an account.'),
					Craft::t('hub', 'The user isn’t pending.'),
					Severity::Notice,
				),
				'suspended' => $check(
					Craft::t('hub', 'Suspended'),
					Craft::t('hub', 'This user is suspended. Delete them if they no longer need an account, transferring their content to another user.'),
					Craft::t('hub', 'The user isn’t suspended.'),
					Severity::Notice,
				),
				'unverifiedEmail' => $check(
					Craft::t('hub', 'Email'),
					Craft::t('hub', 'This user changed their email address, but hasn’t verified it yet. Emails are sent to the previous address until then.'),
					Craft::t('hub', 'The email address is verified.'),
					Severity::Notice,
				),
			],
			CheckKind::EntryTypes->value => [
				'unused' => $check(
					Craft::t('hub', 'Usage'),
					Craft::t('hub', 'This entry type is not assigned to any section or nested entry field. Delete it if it is no longer needed.'),
					Craft::t('hub', 'The entry type is assigned to a section or nested entry field.'),
					Severity::Error,
				),
				'empty' => $check(
					Craft::t('hub', 'Entries'),
					Craft::t('hub', 'This entry type is assigned, but no entries exist. Create entries or remove the entry type.'),
					Craft::t('hub', 'Entries of this type exist, or it isn’t assigned.'),
					Severity::Warning,
				),
			],
			CheckKind::Sections->value => [
				'empty' => $check(
					Craft::t('hub', 'Entries'),
					Craft::t('hub', 'This section has no entries. Add content or delete the section.'),
					Craft::t('hub', 'The section has entries.'),
					Severity::Warning,
				),
			],
			CheckKind::Fields->value => [
				'unused' => $check(
					Craft::t('hub', 'Usage'),
					Craft::t('hub', 'This field is not placed in any field layout. Delete it if it is no longer needed.'),
					Craft::t('hub', 'The field is placed in a field layout.'),
					Severity::Error,
				),
				'empty' => $check(
					Craft::t('hub', 'Content'),
					Craft::t('hub', 'This field is placed in layouts, but holds no data. Remove it from them or delete it.'),
					Craft::t('hub', 'The field holds data, or isn’t a nested entry or relation field.'),
					Severity::Warning,
				),
			],
			CheckKind::Volumes->value => [
				'empty' => $check(
					Craft::t('hub', 'Assets'),
					Craft::t('hub', 'This volume has no assets. Upload assets or delete the volume.'),
					Craft::t('hub', 'The volume has assets.'),
					Severity::Notice,
				),
			],
		];
	}

	/**
	 * All checks, of all kinds or of one, in their order, by their key.
	 *
	 * @return array<string,CheckDefinition>
	 */
	public function getDefinitions(?CheckKind $kind = null): array
	{
		$this->definitions ??= $this->loadDefinitions();

		return $kind === null
			? $this->definitions
			: array_filter($this->definitions, fn (CheckDefinition $definition) => $definition->kind === $kind);
	}

	/**
	 * @return array<string,CheckDefinition>
	 */
	private function loadDefinitions(): array
	{
		$rows = (new Query())
			->select(['id', 'kind', 'handle', 'filter', 'name', 'description', 'passedDescription', 'icon', 'severity', 'enabled', 'sortOrder', 'uid'])
			->from(self::TABLE_CHECKS)
			->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
			->all();

		$byKind = [];

		// Default checks without a row come first, in their own order, then the configured ones in theirs.
		foreach ($this->getDefaults() as $kind => $checks) {
			$order = -count($checks);

			foreach ($checks as $handle => $check) {
				$byKind[$kind][$handle] = new CheckDefinition([
					'kind' => CheckKind::from($kind),
					'handle' => $handle,
					'filter' => $handle,
					'name' => $check['name'],
					'description' => $check['description'],
					'passedDescription' => $check['passedDescription'],
					'defaultSeverity' => $check['severity'],
					'sortOrder' => $order++,
				]);
			}
		}

		foreach ($rows as $row) {
			$kind = CheckKind::tryFrom((string)$row['kind']);
			$handle = $row['handle'] !== null ? (string)$row['handle'] : null;

			// Rows of default checks Hub no longer has are left out.
			if ($kind === null || ($handle !== null && !isset($byKind[$kind->value][$handle]))) {
				continue;
			}

			$definition = $handle !== null ? $byKind[$kind->value][$handle] : new CheckDefinition([
				'kind' => $kind,
				'filter' => (string)$row['filter'],
				'name' => (string)$row['name'],
				'description' => (string)$row['description'],
				'passedDescription' => (string)$row['passedDescription'],
				'icon' => $row['icon'] ?: null,
			]);

			$definition->id = (int)$row['id'];
			$definition->uid = (string)$row['uid'];
			$definition->severity = Severity::tryFrom((string)$row['severity']);
			$definition->enabled = (bool)$row['enabled'];
			$definition->sortOrder = (int)$row['sortOrder'];

			$byKind[$kind->value][$handle ?? $definition->uid] = $definition;
		}

		$definitions = [];

		foreach (CheckKind::cases() as $kind) {
			$checks = array_values($byKind[$kind->value] ?? []);
			usort($checks, fn (CheckDefinition $a, CheckDefinition $b) => $a->sortOrder <=> $b->sortOrder);

			foreach ($checks as $definition) {
				$definitions[$definition->getKey()] = $definition;
			}
		}

		return $definitions;
	}

	public function getDefinition(string $key): ?CheckDefinition
	{
		return $this->getDefinitions()[$key] ?? null;
	}

	public function getDefinitionById(int $id): ?CheckDefinition
	{
		foreach ($this->getDefinitions() as $definition) {
			if ($definition->id === $id) {
				return $definition;
			}
		}

		return null;
	}

	/**
	 * The enabled checks of a kind, in their order; checks of a filter that no longer exists are left out.
	 *
	 * @return CheckDefinition[]
	 */
	public function getEnabledChecks(CheckKind $kind): array
	{
		return array_values(array_filter(
			$this->getDefinitions($kind),
			fn (CheckDefinition $definition) => $definition->enabled && $definition->getFilter() !== null,
		));
	}

	/**
	 * The custom checks of a filter, which it can't be deleted while they flag its items.
	 *
	 * @return CheckDefinition[]
	 */
	public function getChecksOfFilter(SavedFilter $filter): array
	{
		return array_values(array_filter(
			$this->getDefinitions($filter->kind),
			fn (CheckDefinition $definition) => !$definition->isDefault() && $definition->filter === ($filter->handle ?? $filter->uid),
		));
	}

	/**
	 * Orders checks, failed ones first, by severity, then the checks of the kind before those of the items it holds, like
	 * those of the entries of a section, each in their order.
	 *
	 * @template T
	 * @param T[] $items
	 * @param callable(T):array{0:string,1:bool,2:Severity} $describe key, whether it passed and severity of an item
	 * @return T[]
	 */
	public function sort(array $items, CheckKind $kind, callable $describe): array
	{
		$positions = array_flip(array_keys($this->getDefinitions()));
		$prefix = $kind->value . '.';

		usort($items, function ($a, $b) use ($describe, $positions, $prefix) {
			$order = function ($item) use ($describe, $positions, $prefix) {
				[$key, $passed, $severity] = $describe($item);

				return [$passed, $passed ? 0 : $severity->rank(), !str_starts_with($key, $prefix), $positions[$key] ?? PHP_INT_MAX];
			};

			return $order($a) <=> $order($b);
		});

		return $items;
	}

	/**
	 * Whether a check flags the item with the ID, as it's one of those its filter matches.
	 */
	public function fails(CheckDefinition $definition, int $id): bool
	{
		if ($definition->kind->elementType() !== null) {
			return $this->getMatchingIds($definition, [$id]) !== [];
		}

		$this->failing[$definition->getKey()] ??= array_flip($this->getMatchingIds($definition));

		return isset($this->failing[$definition->getKey()][$id]);
	}

	/**
	 * Number of items a check flags; of entries and assets, counted for all sections and entry types, or volumes, at once.
	 *
	 * @param array<string,int|null> $criteria value of each column of the kind, by its alias, see {@see GROUPS}; missing for any
	 */
	public function countFailing(CheckDefinition $definition, array $criteria = []): int
	{
		$query = $this->getFailingQuery($definition);

		if ($query === null) {
			$this->failing[$definition->getKey()] ??= array_flip($this->getMatchingIds($definition));

			return count($this->failing[$definition->getKey()]);
		}

		$groups = self::GROUPS[$definition->kind->value] ?? [];
		$key = $definition->getKey() . ':' . implode(',', Hub::getInstance()->siteScope->getSiteIds());

		if (!isset($this->counts[$key])) {
			$queries = Hub::getInstance()->queries;
			$this->counts[$key] = Aggregator::index($queries->count($query, $groups), array_keys($groups), fn (?int $sum, array $row) => ($sum ?? 0) + (int)$row['total']);
		}

		return $this->counts[$key][Aggregator::key(array_map(fn (string $alias) => $criteria[$alias] ?? null, array_keys($groups)))] ?? 0;
	}

	/**
	 * Elements of entries, assets or users a check flags, as its filter narrows their list; null for the items of
	 * structure pages, which aren't elements.
	 */
	public function getFailingQuery(CheckDefinition $definition): ?ElementQuery
	{
		$hub = Hub::getInstance();
		$filter = $this->createListFilter($definition);

		$query = match (true) {
			$filter instanceof EntryFilter => $hub->entries->getQuery($filter),
			$filter instanceof FileFilter => $hub->files->getQuery($filter),
			$filter instanceof UserFilter => $hub->users->getQuery($filter),
			default => null,
		};

		return $query?->orderBy(null);
	}

	/**
	 * IDs of the items a check flags, among those with the IDs, if given.
	 *
	 * @param int[]|null $ids
	 * @return int[]
	 */
	private function getMatchingIds(CheckDefinition $definition, ?array $ids = null): array
	{
		$filter = $this->createListFilter($definition);

		return $filter !== null ? Hub::getInstance()->bulk->getMatchingIds($definition->kind->list(), $filter, $ids) : [];
	}

	private function createListFilter(CheckDefinition $definition): ?object
	{
		$filter = $definition->getFilter();

		return $filter !== null ? Hub::getInstance()->listFilters->createListFilter($definition->kind, $filter->getListParams()) : null;
	}

	/**
	 * Saves a new custom check, last in the order of its kind, or the changes to a check; a default check gets a row once
	 * it's saved.
	 */
	public function saveDefinition(CheckDefinition $definition): bool
	{
		if (!$definition->validate()) {
			return false;
		}

		$columns = [
			'enabled' => $definition->enabled,
			'severity' => $definition->severity?->value,
		];

		if (!$definition->isDefault()) {
			$columns += [
				'filter' => $definition->filter,
				'name' => $definition->name,
				'description' => $definition->description,
				'passedDescription' => $definition->passedDescription ?: null,
				'icon' => $definition->icon ?: null,
			];
		}

		$db = Craft::$app->getDb();

		if ($definition->id === null) {
			$definition->uid ??= StringHelper::UUID();
			$definition->sortOrder = (int)(new Query())->from(self::TABLE_CHECKS)->where(['kind' => $definition->kind->value])->max('[[sortOrder]]') + 1;
			$db->createCommand()->insert(self::TABLE_CHECKS, [
				...$columns,
				'kind' => $definition->kind->value,
				'handle' => $definition->handle,
				'sortOrder' => $definition->sortOrder,
				'uid' => $definition->uid,
			])->execute();
			$definition->id = (int)$db->getLastInsertID(self::TABLE_CHECKS);
		} else {
			$db->createCommand()->update(self::TABLE_CHECKS, $columns, ['id' => $definition->id])->execute();
		}

		$this->definitions = null;

		return true;
	}

	/**
	 * Deletes a custom check; default checks can't be deleted, only disabled.
	 */
	public function deleteDefinitionById(int $id): bool
	{
		$deleted = Craft::$app->getDb()->createCommand()->delete(self::TABLE_CHECKS, ['id' => $id, 'handle' => null])->execute() > 0;
		$this->definitions = null;

		return $deleted;
	}

	/**
	 * Orders the checks of a kind as the IDs are given.
	 *
	 * @param int[] $ids
	 */
	public function reorderDefinitions(array $ids): void
	{
		$command = Craft::$app->getDb()->createCommand();

		foreach (array_values($ids) as $order => $id) {
			$command->update(self::TABLE_CHECKS, ['sortOrder' => $order + 1], ['id' => (int)$id], updateTimestamp: false)->execute();
		}

		$this->definitions = null;
	}

	/**
	 * Gives the default checks without a row one and numbers the checks of each kind in their current order, so they can
	 * be reordered and toggled by their ID. Run once Hub is installed, see Hub::afterInstall().
	 */
	public function saveDefaults(): void
	{
		$command = Craft::$app->getDb()->createCommand();

		foreach (CheckKind::cases() as $kind) {
			$order = 0;

			foreach ($this->getDefinitions($kind) as $definition) {
				$order++;

				if ($definition->id === null) {
					$command->insert(self::TABLE_CHECKS, [
						'kind' => $kind->value,
						'handle' => $definition->handle,
						'enabled' => $definition->enabled,
						'sortOrder' => $order,
						'uid' => StringHelper::UUID(),
					])->execute();
				} elseif ($definition->sortOrder !== $order) {
					$command->update(self::TABLE_CHECKS, ['sortOrder' => $order], ['id' => $definition->id], updateTimestamp: false)->execute();
				}
			}
		}

		$this->definitions = null;
	}
}
