<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\MaintainerSource;
use yii\base\Component;

/**
 * Users and user groups maintaining sections, entry types, fields and volumes, shown there and on the entries and
 * assets stored in them, so editors know who to ask for help; maintainers have no permissions of their own. They're
 * kept in the database rather than the project config, as users are, and changes to them are recorded in the activity
 * log.
 */
class MaintainerService extends Component
{
	public const TABLE = '{{%hub_maintainers}}';

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
	private ?array $users = null;

	/**
	 * Whether maintainers can be set and are shown (see Settings::$enableMaintainers).
	 */
	public function isEnabled(): bool
	{
		return Hub::getInstance()->settings->enableMaintainers;
	}

	/**
	 * Whether the current user may set the maintainers of the items of a list, while maintainers are enabled, as they may view
	 * its page (see Page).
	 */
	public function canManage(string $list): bool
	{
		return $this->isEnabled()
			&& isset(self::LISTS[$list])
			&& ($page = Page::fromList($list)) !== null
			&& Hub::getInstance()->access->canView($page)
			&& Craft::$app->getUser()->checkPermission(Hub::PERMISSION_MANAGE_MAINTAINERS);
	}

	/**
	 * The item of a list with the ID, if it exists.
	 */
	public function getItem(string $list, int $id): Section|EntryType|FieldInterface|Volume|null
	{
		return match ($list) {
			ColumnService::LIST_SECTIONS => Craft::$app->getEntries()->getSectionById($id),
			ColumnService::LIST_ENTRY_TYPES => Craft::$app->getEntries()->getEntryTypeById($id),
			ColumnService::LIST_FIELDS => Craft::$app->getFields()->getFieldById($id),
			ColumnService::LIST_VOLUMES => Craft::$app->getVolumes()->getVolumeById($id),
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
		$groups = Craft::$app->getUserGroups();

		return [
			'users' => array_values(array_filter(array_map(fn (int $id) => $users[$id] ?? null, $maintainers['userIds']))),
			'groups' => array_values(array_filter(array_map(fn (int $id) => $groups->getGroupById($id), $maintainers['groupIds']))),
		];
	}

	/**
	 * Maintainers of what an element is stored in: the volume of an asset; the section of an entry and its type; the field of a
	 * nested entry, its type and those of its owner, as they're stored in it. Items without any are left out, as is everything
	 * while maintainers are disabled.
	 *
	 * @param bool $withFields whether to include those of the fields of the element's field layout, as used while editing it
	 * @return MaintainerSource[]
	 */
	public function getSources(ElementInterface $element, bool $withFields = false): array
	{
		// Nothing is looked up through the owners of elements while nothing is maintained, as lists ask for each of their items.
		if (!$this->isEnabled() || $this->all() === []) {
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
			$maintainers = $this->getMaintainers($list, (int)$item->id);

			if ($maintainers['users'] !== [] || $maintainers['groups'] !== []) {
				$sources["$list:$item->id"] ??= new MaintainerSource($list, $item, $maintainers['users'], $maintainers['groups']);
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
				$users[$user->id] ??= $user;
			}

			foreach ($source->groups as $group) {
				$groups[$group->id] ??= $group;
			}
		}

		return ['users' => array_values($users), 'groups' => array_values($groups)];
	}

	/**
	 * What a user maintains, in the order of the lists: each item with the user, if they maintain it themselves, and their
	 * groups maintaining it; items no longer existing are left out, as is everything while maintainers are disabled.
	 *
	 * @return MaintainerSource[]
	 */
	public function getMaintainedBy(User $user): array
	{
		if (!$this->isEnabled()) {
			return [];
		}

		$groupIds = array_map(fn (UserGroup $group) => (int)$group->id, $user->getGroups());
		$maintained = [];

		foreach (array_keys(self::LISTS) as $list) {
			foreach ($this->all()[$list] ?? [] as $id => $maintainers) {
				$direct = in_array((int)$user->id, $maintainers['userIds'], true);
				$through = array_intersect($maintainers['groupIds'], $groupIds);
				$item = $direct || $through !== [] ? $this->getItem($list, $id) : null;

				if ($item !== null) {
					$groups = array_filter(array_map(fn (int $id) => Craft::$app->getUserGroups()->getGroupById($id), $through));
					$maintained[] = new MaintainerSource($list, $item, $direct ? [$user] : [], array_values($groups));
				}
			}
		}

		return $maintained;
	}

	/**
	 * What a user group maintains, by the kind of item.
	 *
	 * @return MaintainerSource[]
	 */
	public function getMaintainedByGroup(UserGroup $group): array
	{
		if (!$this->isEnabled()) {
			return [];
		}

		$maintained = [];

		foreach (array_keys(self::LISTS) as $list) {
			foreach ($this->all()[$list] ?? [] as $id => $maintainers) {
				$item = in_array((int)$group->id, $maintainers['groupIds'], true) ? $this->getItem($list, $id) : null;

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
		$groupIds = array_values(array_unique(array_filter($groupIds, fn (int $id) => Craft::$app->getUserGroups()->getGroupById($id) !== null)));

		$rows = [
			...array_map(fn (int $userId) => [$list, $itemId, $userId, null], $userIds),
			...array_map(fn (int $groupId) => [$list, $itemId, null, $groupId], $groupIds),
		];

		$db = Craft::$app->getDb();

		$db->transaction(function () use ($db, $list, $itemId, $rows) {
			$db->createCommand()->delete(self::TABLE, ['list' => $list, 'itemId' => $itemId])->execute();

			if ($rows !== []) {
				$db->createCommand()->batchInsert(self::TABLE, ['list', 'itemId', 'userId', 'groupId'], $rows)->execute();
			}
		});

		$this->maintainers = null;
		$this->users = null;

		$after = $this->getMaintainers($list, $itemId);
		$changes = $this->changes($before, $after);

		if ($changes !== [] && ($subject = ActivitySubject::fromList($list)) !== null) {
			Hub::getInstance()->activity->record(ActivityAction::MaintainersChanged, $subject, $itemId, $this->getItem($list, $itemId)?->name, $changes);
		}
	}

	/**
	 * Removes the maintainers of items that no longer exist, as Craft's garbage collection deletes them for good.
	 */
	public function deleteOrphans(): void
	{
		$command = Craft::$app->getDb()->createCommand();

		foreach (self::LISTS as $list => $table) {
			$command->delete(self::TABLE, [
				'and',
				['list' => $list],
				['not exists', (new Query())->from(['items' => $table])->where('[[items.id]] = ' . self::TABLE . '.[[itemId]]')],
			])->execute();
		}
	}

	/**
	 * Items an element is stored in, as pairs of their list and the item.
	 *
	 * @return array<int,array{0:string,1:Section|EntryType|FieldInterface|Volume}>
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
		$field = $section === null ? $element->getField() : null;
		$owner = $section === null ? $element->getOwner() : null;

		return [
			...($section !== null ? [[ColumnService::LIST_SECTIONS, $section]] : []),
			...($field !== null ? [[ColumnService::LIST_FIELDS, $field]] : []),
			[ColumnService::LIST_ENTRY_TYPES, $element->getType()],
			...($owner !== null ? $this->storedIn($owner) : []),
		];
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
			->from(self::TABLE)
			->orderBy(['id' => SORT_ASC])
			->all();

		$maintainers = [];
		foreach ($rows as $row) {
			$key = $row['userId'] !== null ? 'userIds' : 'groupIds';
			$maintainers[(string)$row['list']][(int)$row['itemId']][$key][] = (int)($row['userId'] ?? $row['groupId']);
		}

		return $this->maintainers = array_map(
			fn (array $items) => array_map(fn (array $item) => [...['userIds' => [], 'groupIds' => []], ...$item], $items),
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
		if ($this->users !== null) {
			return $this->users;
		}

		$ids = [];
		foreach ($this->all() as $items) {
			foreach ($items as $item) {
				array_push($ids, ...$item['userIds']);
			}
		}

		return $this->users = Hub::getInstance()->users->getUsers($ids);
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

		return $ids !== [] ? array_map('intval', User::find()->id($ids)->status(null)->fixedOrder()->ids()) : [];
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
		$names = fn (array $maintainers) => [
			...array_combine(array_map(fn (User $user) => "user:$user->id", $maintainers['users']), array_map(fn (User $user) => $user->getName(), $maintainers['users'])),
			...array_combine(array_map(fn (UserGroup $group) => "group:$group->id", $maintainers['groups']), array_map(fn (UserGroup $group) => (string)$group->name, $maintainers['groups'])),
		];
		$old = $names($before);
		$new = $names($after);

		return array_filter([
			'added' => array_values(array_diff_key($new, $old)),
			'removed' => array_values(array_diff_key($old, $new)),
		]);
	}
}
