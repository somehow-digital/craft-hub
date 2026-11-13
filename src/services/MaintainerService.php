<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Connection;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;
use craft\services\Entries;
use craft\services\Fields;
use craft\services\UserGroups;
use craft\services\Volumes;
use craft\web\User as WebUser;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\models\MaintainerSource;
use SomehowDigital\Craft\Hub\models\Settings;
use yii\base\Component;

/**
 * Users and user groups maintaining sections, entry types, fields and volumes, shown there and on the entries and
 * assets stored in them, so editors know who to ask for help; maintainers have no permissions of their own. They're
 * kept in the database rather than the project config, as users are, and changes to them are recorded in the activity
 * log.
 *
 * @internal
 */
final class MaintainerService extends Component
{
	/** tables holding the items of each list, by its name, which maintainers of items no longer in them are removed for */
	public const LISTS = [
		ColumnService::LIST_SECTIONS => Table::SECTIONS,
		ColumnService::LIST_ENTRY_TYPES => Table::ENTRYTYPES,
		ColumnService::LIST_FIELDS => Table::FIELDS,
		ColumnService::LIST_VOLUMES => Table::VOLUMES,
	];

	/** @var array<string,array<int,array{userIds:int[],groupIds:int[]}>>|null */
	private ?array $maintainers = null;

	/** @var array<int,User>|null */
	private ?array $loadedUsers = null;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly AccessService $access,
		private readonly ActivityService $activity,
		private readonly UserService $users,
		private readonly Entries $craftEntries,
		private readonly Fields $craftFields,
		private readonly Volumes $craftVolumes,
		private readonly UserGroups $craftUserGroups,
		private readonly Connection $db,
		private readonly ?WebUser $user = null,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * Whether maintainers can be set and are shown (see Settings::$enableMaintainers).
	 */
	public function isEnabled(): bool
	{
		return $this->settings->enableMaintainers;
	}

	/**
	 * Whether the current user may set the maintainers of the items of a list, while maintainers are enabled, as they may view
	 * its page (see Page).
	 */
	public function canManage(string $list): bool
	{
		return $this->settings->enableMaintainers
			&& isset(self::LISTS[$list])
			&& ($page = Page::fromList($list)) instanceof Page
			&& $this->access->canView($page)
			&& $this->user?->checkPermission(Permission::ManageMaintainers->value) === true;
	}

	/**
	 * The item of a list with the ID, if it exists.
	 */
	public function getItem(string $list, int $id): Section|EntryType|FieldInterface|Volume|null
	{
		return match ($list) {
			ColumnService::LIST_SECTIONS => $this->craftEntries->getSectionById($id),
			ColumnService::LIST_ENTRY_TYPES => $this->craftEntries->getEntryTypeById($id),
			ColumnService::LIST_FIELDS => $this->craftFields->getFieldById($id),
			ColumnService::LIST_VOLUMES => $this->craftVolumes->getVolumeById($id),
			default => null,
		};
	}

	/**
	 * Maintainers of an item of a list, in the order they were set; users deleted since are left out.
	 *
	 * @return array{users:User[],groups:UserGroup[]}
	 */
	public function getMaintainers(string $list, int $id): array
	{
		$maintainers = $this->all()[$list][$id] ?? ['userIds' => [], 'groupIds' => []];
		$users = $this->loadUsers();
		$groups = $this->craftUserGroups;

		return [
			'users' => array_values(array_filter(array_map(fn(int $id) => $users[$id] ?? null, $maintainers['userIds']))),
			'groups' => array_values(array_filter(array_map($groups->getGroupById(...), $maintainers['groupIds']))),
		];
	}

	/**
	 * Number of the maintainers of an item of a list, from their IDs, for what sums up its maintainers without loading them.
	 */
	public function countMaintainers(string $list, int $id): int
	{
		$maintainers = $this->all()[$list][$id] ?? ['userIds' => [], 'groupIds' => []];

		return count($maintainers['userIds']) + count($maintainers['groupIds']);
	}

	/**
	 * Number of the maintainers of what an element is stored in, like those {@see getSources()} lists, from their IDs.
	 */
	public function countSources(ElementInterface $element, bool $withFields = false): int
	{
		if (!$this->settings->enableMaintainers || $this->all() === []) {
			return 0;
		}

		$items = $this->storedIn($element);

		if ($withFields) {
			foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
				$items[] = [ColumnService::LIST_FIELDS, $field];
			}
		}

		$counts = [];
		foreach ($items as [$list, $item]) {
			$counts[$list . ':' . ($item->id ?? '')] ??= $this->countMaintainers($list, (int) $item->id);
		}

		return array_sum($counts);
	}

	/**
	 * Number of the items a user maintains, themselves or through their groups, like those {@see getMaintainedBy()} lists.
	 */
	public function countMaintainedBy(User $user): int
	{
		if (!$this->settings->enableMaintainers) {
			return 0;
		}

		$groupIds = array_map(fn(UserGroup $group): int => (int) $group->id, $user->getGroups());

		return $this->countItems(fn(array $maintainers): bool => in_array((int) $user->id, $maintainers['userIds'], true) || array_intersect($maintainers['groupIds'], $groupIds) !== []);
	}

	/**
	 * Number of the items a user group maintains, like those {@see getMaintainedByGroup()} lists.
	 */
	public function countMaintainedByGroup(UserGroup $group): int
	{
		return $this->settings->enableMaintainers ? $this->countItems(fn(array $maintainers): bool => in_array((int) $group->id, $maintainers['groupIds'], true)) : 0;
	}

	/**
	 * Maintainers of what an element is stored in: the volume of an asset; the section of an entry and its type; the field of a
	 * nested entry, its type and those of its owner, as they're stored in it. Items without any are left out, as is everything
	 * while maintainers are disabled.
	 *
	 * @param bool $withFields whether to include those of the fields of the element's field layout, as used while editing it
	 * @return list<MaintainerSource>
	 */
	public function getSources(ElementInterface $element, bool $withFields = false): array
	{
		// Nothing is looked up through the owners of elements while nothing is maintained, as lists ask for each of their items.
		if (!$this->settings->enableMaintainers || $this->all() === []) {
			return [];
		}

		$items = $this->storedIn($element);

		if ($withFields) {
			foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
				$items[] = [ColumnService::LIST_FIELDS, $field];
			}
		}

		$sources = [];
		foreach ($items as [$list, $item]) {
			$maintainers = $this->getMaintainers($list, (int) $item->id);

			if ($maintainers['users'] !== [] || $maintainers['groups'] !== []) {
				$sources[$list . ':' . ($item->id ?? '')] ??= new MaintainerSource($list, $item, $maintainers['users'], $maintainers['groups']);
			}
		}

		return array_values($sources);
	}

	/**
	 * Maintainers of an element, each of them once, for showing them all together.
	 *
	 * @return array{users:User[],groups:UserGroup[]}
	 */
	public function getMaintainersOf(ElementInterface $element): array
	{
		$users = [];
		$groups = [];

		foreach ($this->getSources($element) as $source) {
			foreach ($source->users as $user) {
				$users[(int) $user->id] ??= $user;
			}

			foreach ($source->groups as $group) {
				$groups[(int) $group->id] ??= $group;
			}
		}

		return ['users' => array_values($users), 'groups' => array_values($groups)];
	}

	/**
	 * What a user maintains, in the order of the lists: each item with the user, if they maintain it themselves, and their
	 * groups maintaining it; items no longer existing are left out, as is everything while maintainers are disabled.
	 *
	 * @return list<MaintainerSource>
	 */
	public function getMaintainedBy(User $user): array
	{
		if (!$this->settings->enableMaintainers) {
			return [];
		}

		$groupIds = array_map(fn(UserGroup $group): int => (int) $group->id, $user->getGroups());
		$maintained = [];

		foreach (array_keys(self::LISTS) as $list) {
			foreach ($this->all()[$list] ?? [] as $id => $maintainers) {
				$direct = in_array((int) $user->id, $maintainers['userIds'], true);
				$through = array_intersect($maintainers['groupIds'], $groupIds);
				$item = $direct || $through !== [] ? $this->getItem($list, $id) : null;

				if ($item !== null) {
					$groups = array_filter(array_map($this->craftUserGroups->getGroupById(...), $through));
					$maintained[] = new MaintainerSource($list, $item, $direct ? [$user] : [], array_values($groups));
				}
			}
		}

		return $maintained;
	}

	/**
	 * What a user group maintains, by the kind of item.
	 *
	 * @return list<MaintainerSource>
	 */
	public function getMaintainedByGroup(UserGroup $group): array
	{
		if (!$this->settings->enableMaintainers) {
			return [];
		}

		$maintained = [];

		foreach (array_keys(self::LISTS) as $list) {
			foreach ($this->all()[$list] ?? [] as $id => $maintainers) {
				$item = in_array((int) $group->id, $maintainers['groupIds'], true) ? $this->getItem($list, $id) : null;

				if ($item !== null) {
					$maintained[] = new MaintainerSource($list, $item, [], [$group]);
				}
			}
		}

		return $maintained;
	}

	/**
	 * Makes the users and groups with the IDs the maintainers of an item of a list, and only them, in that order; unknown ones
	 * are left out.
	 *
	 * @param int[] $userIds
	 * @param int[] $groupIds
	 */
	public function setMaintainers(string $list, int $itemId, array $userIds, array $groupIds): void
	{
		$before = $this->getMaintainers($list, $itemId);
		$userIds = $this->knownUserIds($userIds);
		$groupIds = array_values(array_unique(array_filter($groupIds, fn(int $id): bool => $this->craftUserGroups->getGroupById($id) instanceof UserGroup)));

		$rows = [
			...array_map(fn(int $userId): array => [$list, $itemId, $userId, null], $userIds),
			...array_map(fn(int $groupId): array => [$list, $itemId, null, $groupId], $groupIds),
		];

		$db = $this->db;

		$db->transaction(function () use ($db, $list, $itemId, $rows): void {
			$db->createCommand()->delete(Tables::MAINTAINERS, ['list' => $list, 'itemId' => $itemId])->execute();

			if ($rows !== []) {
				$db->createCommand()->batchInsert(Tables::MAINTAINERS, ['list', 'itemId', 'userId', 'groupId'], $rows)->execute();
			}
		});

		$this->maintainers = null;
		$this->loadedUsers = null;

		$after = $this->getMaintainers($list, $itemId);
		$changes = $this->changes($before, $after);

		if ($changes !== [] && ($subject = ActivitySubject::fromList($list)) instanceof ActivitySubject) {
			$this->activity->record(ActivityAction::MaintainersChanged, $subject, $itemId, $this->getItem($list, $itemId)?->name, $changes);
		}
	}

	/**
	 * Removes the maintainers of items that no longer exist, as Craft's garbage collection deletes them for good.
	 */
	public function deleteOrphans(): void
	{
		$command = $this->db->createCommand();

		foreach (self::LISTS as $list => $table) {
			$command->delete(Tables::MAINTAINERS, [
				'and',
				['list' => $list],
				['not exists', (new Query())->from(['items' => $table])->where('[[items.id]] = ' . Tables::MAINTAINERS . '.[[itemId]]')],
			])->execute();
		}
	}

	/**
	 * Items an element is stored in, as pairs of their list and the item.
	 *
	 * @return list<array{0:string,1:Section|EntryType|FieldInterface|Volume}>
	 */
	private function storedIn(ElementInterface $element): array
	{
		if ($element instanceof Asset) {
			return [[ColumnService::LIST_VOLUMES, $element->getVolume()]];
		}

		if (!$element instanceof Entry) {
			return [];
		}

		$section = $element->getSection();
		$field = $section instanceof Section ? null : $element->getField();
		$owner = $section instanceof Section ? null : $element->getOwner();

		return [
			...($section instanceof Section ? [[ColumnService::LIST_SECTIONS, $section]] : []),
			...($field instanceof ElementContainerFieldInterface ? [[ColumnService::LIST_FIELDS, $field]] : []),
			[ColumnService::LIST_ENTRY_TYPES, $element->getType()],
			...($owner instanceof ElementInterface ? $this->storedIn($owner) : []),
		];
	}

	/**
	 * Number of the maintained items of the lists whose maintainers match, by their IDs.
	 *
	 * @param callable(array{userIds:int[],groupIds:int[]}):bool $matches
	 */
	private function countItems(callable $matches): int
	{
		$count = 0;

		foreach (array_keys(self::LISTS) as $list) {
			$count += count(array_filter($this->all()[$list] ?? [], $matches));
		}

		return $count;
	}

	/**
	 * IDs of the users and groups maintaining each item, by its list and ID, read once per request as there are few of them.
	 *
	 * @return array<string,array<int,array{userIds:int[],groupIds:int[]}>>
	 */
	private function all(): array
	{
		if ($this->maintainers !== null) {
			return $this->maintainers;
		}

		$rows = (new Query())
			->select(['list', 'itemId', 'userId', 'groupId'])
			->from(Tables::MAINTAINERS)
			->orderBy(['id' => SORT_ASC])
			->all();

		$maintainers = [];
		foreach ($rows as $row) {
			$key = Row::value($row, 'userId') !== null ? 'userIds' : 'groupIds';
			$maintainers[Row::string($row, 'list')][Row::int($row, 'itemId')][$key][] = Fill::int((Row::value($row, 'userId') ?? Row::value($row, 'groupId')));
		}

		return $this->maintainers = array_map(
			fn(array $items): array => array_map(fn(array $item): array => [...['userIds' => [], 'groupIds' => []], ...$item], $items),
			$maintainers,
		);
	}

	/**
	 * All users maintaining anything, whatever their status, by their ID.
	 *
	 * @return array<int,User>
	 */
	private function loadUsers(): array
	{
		if ($this->loadedUsers !== null) {
			return $this->loadedUsers;
		}

		$ids = [];
		foreach ($this->all() as $items) {
			foreach ($items as $item) {
				array_push($ids, ...$item['userIds']);
			}
		}

		return $this->loadedUsers = $this->users->getUsers($ids);
	}

	/**
	 * The users with the IDs that exist, in the given order.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	private function knownUserIds(array $ids): array
	{
		$ids = array_values(array_unique($ids));

		return $ids !== [] ? array_map(Fill::int(...), User::find()->id($ids)->status(null)->fixedOrder()->ids()) : [];
	}

	/**
	 * Names of the maintainers added and removed, as kept along with the activity.
	 *
	 * @param array{users:User[],groups:UserGroup[]} $before
	 * @param array{users:User[],groups:UserGroup[]} $after
	 * @return array{added?:string[],removed?:string[]}
	 */
	private function changes(array $before, array $after): array
	{
		$names = fn(array $maintainers): array => [
			...array_combine(array_map(fn(User $user): string => 'user:' . ($user->id ?? ''), $maintainers['users']), array_map(fn(User $user): string => $user->getName(), $maintainers['users'])),
			...array_combine(array_map(fn(UserGroup $group): string => 'group:' . ($group->id ?? ''), $maintainers['groups']), array_map(fn(UserGroup $group): string => (string) $group->name, $maintainers['groups'])),
		];
		$old = $names($before);
		$new = $names($after);

		return array_filter([
			'added' => array_values(array_diff_key($new, $old)),
			'removed' => array_values(array_diff_key($old, $new)),
		]);
	}
}
