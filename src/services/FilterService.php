<?php

namespace SomehowDigital\Craft\Hub\services;

use Closure;
use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQuery;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use SomehowDigital\Craft\Hub\conditions\InfoCondition;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\helpers\FilterParams;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\SavedFilter;
use SomehowDigital\Craft\Hub\models\UserFilter;
use yii\base\Component;

/**
 * The saved filters of the lists, picked from the list's filter bar: Hub's predefined filters, which can be disabled,
 * reordered and renamed on the filters tab of the settings page, some given another threshold there too, custom ones
 * created there, matching the items their condition matches, and the users' own ones, saved from the list with the filters
 * it shows. Predefined filters have a row once configured; until then, they are enabled, in their own order, before the
 * custom ones. Checks of the diagnostics tabs flag the items a filter offered to everyone matches, see DiagnosticsService.
 */
class FilterService extends Component
{
	public const TABLE = '{{%hub_filters}}';

	/** query param of a list applying the condition of a custom filter, by its UID */
	public const PARAM = 'filter';

	/** query params of a list that aren't its filters, so they aren't saved: the site scope, the page, the sorting and the review of the selection */
	private const IGNORED_PARAMS = [Hub::SITE_PARAM, 'site', 'page', 'sort', 'dir', 'selection'];

	/** saved filters keep at most this many params, of at most this length, as their URL does */
	private const MAX_PARAMS = 50;
	private const MAX_LENGTH = 1000;

	/** @var array<string,SavedFilter>|null those offered to everyone, in their order, by their key */
	private ?array $filters = null;

	/**
	 * Hub's predefined filters of each kind, in their own order, by their handle, with the query params of the list they set.
	 * Filters with a threshold, like the size of large files, describe themselves and set their params by it.
	 *
	 * @return array<string,array<string,array{name:string,description:string|Closure(int):string,icon:string,params:array<string,string>|Closure(int):array<string,string>,threshold:array{label:string,instructions:string,unit:string,default:int}|null}>> by the kind
	 */
	public function getDefaults(): array
	{
		$filter = fn (string $name, string|Closure $description, string $icon, array|Closure $params, ?array $threshold = null) => compact('name', 'description', 'icon', 'params', 'threshold');
		$months = fn (string $instructions) => ['label' => Craft::t('hub', 'Threshold'), 'instructions' => $instructions, 'unit' => Craft::t('hub', 'months'), 'default' => 12];

		return [
			CheckKind::Entries->value => [
				'mine' => $filter(Craft::t('hub', 'My entries'), Craft::t('hub', 'Entries you are an author of.'), 'user', ['author' => FilterParams::ME]),
				'editedByMe' => $filter(Craft::t('hub', 'Last edited by me'), Craft::t('hub', 'Entries you were the last person to edit.'), 'user-pen', ['editor' => FilterParams::ME]),
				'myDrafts' => $filter(Craft::t('hub', 'My drafts'), Craft::t('hub', 'Entries with drafts or unsaved changes of yours.'), 'file-pen', ['myDrafts' => '1']),
				'unreferenced' => $filter(Craft::t('hub', 'Unreferenced entries'), Craft::t('hub', 'Entries no element references, through a relation field or rich text.'), 'link-slash', ['usage' => EntryService::USAGE_UNREFERENCED]),
				'stale' => $filter(
					Craft::t('hub', 'Stale entries'),
					fn (int $months) => Craft::t('hub', 'Entries no person has edited for {months, plural, =1{one month} other{# months}}.', ['months' => $months]),
					'clock',
					['stale' => '1'],
					$months(Craft::t('hub', 'Entries no person has edited within this many months are stale.')),
				),
				'drafts' => $filter(Craft::t('hub', 'Entries with drafts'), Craft::t('hub', 'Entries with drafts that are neither published nor discarded.'), 'pen-to-square', ['drafts' => '1']),
				'disabled' => $filter(Craft::t('hub', 'Disabled entries'), Craft::t('hub', 'Entries that are disabled.'), 'eye-slash', ['status' => 'disabled']),
				'scheduled' => $filter(Craft::t('hub', 'Scheduled entries'), Craft::t('hub', 'Entries with a post date in the future.'), 'calendar', ['status' => 'pending']),
				'expired' => $filter(Craft::t('hub', 'Expired entries'), Craft::t('hub', 'Entries past their expiry date.'), 'calendar-xmark', ['status' => 'expired']),
			],
			CheckKind::Assets->value => [
				'mine' => $filter(Craft::t('hub', 'My uploads'), Craft::t('hub', 'Assets you uploaded.'), 'upload', ['uploader' => FilterParams::ME]),
				'unused' => $filter(Craft::t('hub', 'Unreferenced assets'), Craft::t('hub', 'Assets no element references.'), 'link-slash', ['usage' => FileService::USAGE_UNUSED]),
				'missingAlt' => $filter(Craft::t('hub', 'Images without alternative text'), Craft::t('hub', 'Images without alternative text.'), 'universal-access', ['alt' => '1']),
				'large' => $filter(
					Craft::t('hub', 'Large files'),
					fn (int $size) => Craft::t('hub', 'Files larger than {size, number} MB.', ['size' => $size]),
					'weight-hanging',
					fn (int $size) => ['sizeMin' => (string)$size],
					['label' => Craft::t('hub', 'Threshold'), 'instructions' => Craft::t('hub', 'Files larger than this many megabytes are large.'), 'unit' => 'MB', 'default' => 5],
				),
				'oversized' => $filter(
					Craft::t('hub', 'Oversized images'),
					fn (int $pixels) => Craft::t('hub', 'Images wider or taller than {pixels, number} px.', ['pixels' => $pixels]),
					'expand',
					['oversized' => '1'],
					['label' => Craft::t('hub', 'Threshold'), 'instructions' => Craft::t('hub', 'Images wider or taller than this many pixels are oversized.'), 'unit' => 'px', 'default' => 4000],
				),
				'images' => $filter(Craft::t('hub', 'Images'), Craft::t('hub', 'Image files.'), 'image', ['kind' => 'image']),
			],
			CheckKind::Users->value => [
				'admins' => $filter(Craft::t('hub', 'Admins'), Craft::t('hub', 'Users who are admins.'), 'crown', ['admin' => '1']),
				'adminsWithoutTwoFactor' => $filter(Craft::t('hub', 'Admins without two-step verification'), Craft::t('hub', 'Admins without an authenticator app or a passkey.'), 'shield-halved', ['admin' => '1', 'twoFactor' => 'without']),
				'idle' => $filter(
					Craft::t('hub', 'Idle users'),
					fn (int $months) => Craft::t('hub', 'Active users who haven’t signed in for {months, plural, =1{one month} other{# months}}.', ['months' => $months]),
					'clock',
					['login' => UserFilter::LOGIN_IDLE],
					$months(Craft::t('hub', 'Active users who haven’t signed in within this many months are idle.')),
				),
				'pending' => $filter(Craft::t('hub', 'Pending users'), Craft::t('hub', 'Users who haven’t activated their account yet.'), 'hourglass', ['status' => 'pending']),
				'suspended' => $filter(Craft::t('hub', 'Suspended users'), Craft::t('hub', 'Users who are suspended.'), 'ban', ['status' => 'suspended']),
				'locked' => $filter(Craft::t('hub', 'Locked users'), Craft::t('hub', 'Users locked out after too many failed sign-ins.'), 'lock', ['status' => 'locked']),
				'unverifiedEmail' => $filter(Craft::t('hub', 'Users with unverified email'), Craft::t('hub', 'Users who changed their email address, but haven’t verified it yet.'), 'envelope', ['unverified' => '1']),
			],
			CheckKind::EntryTypes->value => [
				'unused' => $filter(Craft::t('hub', 'Unused entry types'), Craft::t('hub', 'Entry types not assigned to any section or nested entry field.'), 'ghost', ['kind' => 'unused']),
				'empty' => $filter(Craft::t('hub', 'Empty entry types'), Craft::t('hub', 'Entry types that are assigned, but have no entries.'), 'box-open', ['kind' => 'empty']),
				'stale' => $filter(
					Craft::t('hub', 'Stale entry types'),
					fn (int $months) => Craft::t('hub', 'Entry types whose entries no person has edited for {months, plural, =1{one month} other{# months}}.', ['months' => $months]),
					'clock',
					['activity' => EntryStatistics::ACTIVITY_STALE],
					$months(Craft::t('hub', 'Entry types whose entries no person has edited within this many months are stale.')),
				),
			],
			CheckKind::Sections->value => [
				'empty' => $filter(Craft::t('hub', 'Empty sections'), Craft::t('hub', 'Sections without entries.'), 'box-open', ['empty' => '1']),
				'stale' => $filter(
					Craft::t('hub', 'Stale sections'),
					fn (int $months) => Craft::t('hub', 'Sections whose entries no person has edited for {months, plural, =1{one month} other{# months}}.', ['months' => $months]),
					'clock',
					['activity' => EntryStatistics::ACTIVITY_STALE],
					$months(Craft::t('hub', 'Sections whose entries no person has edited within this many months are stale.')),
				),
				'withoutUrls' => $filter(Craft::t('hub', 'Sections without URLs'), Craft::t('hub', 'Sections whose entries have no URLs.'), 'globe', ['urls' => ListService::URLS_NO]),
			],
			CheckKind::Fields->value => [
				'unused' => $filter(Craft::t('hub', 'Unused fields'), Craft::t('hub', 'Fields not placed in any field layout.'), 'ghost', ['unused' => '1']),
				'empty' => $filter(Craft::t('hub', 'Empty fields'), Craft::t('hub', 'Nested entry and relation fields placed in layouts, but without data.'), 'box-open', ['empty' => '1']),
			],
			CheckKind::Volumes->value => [
				'empty' => $filter(Craft::t('hub', 'Empty volumes'), Craft::t('hub', 'Volumes without assets.'), 'box-open', ['empty' => '1']),
				'missingAlt' => $filter(Craft::t('hub', 'Volumes missing alternative text'), Craft::t('hub', 'Volumes with images without alternative text.'), 'universal-access', ['altMin' => '1']),
				'withoutUrls' => $filter(Craft::t('hub', 'Volumes without URLs'), Craft::t('hub', 'Volumes whose assets have no URLs.'), 'globe', ['urls' => ListService::URLS_NO]),
			],
		];
	}

	/**
	 * The filters offered to everyone, predefined and custom ones, of all kinds or of one, in their order, by their key.
	 *
	 * @return array<string,SavedFilter>
	 */
	public function getFilters(?CheckKind $kind = null): array
	{
		$this->filters ??= $this->loadFilters();

		return $kind === null
			? $this->filters
			: array_filter($this->filters, fn (SavedFilter $filter) => $filter->kind === $kind);
	}

	/**
	 * @return array<string,SavedFilter>
	 */
	private function loadFilters(): array
	{
		$rows = $this->query()
			->where(['userId' => null])
			->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
			->all();

		$configured = [];
		foreach ($rows as $row) {
			if ($row['handle'] !== null) {
				$configured[$row['kind'] . '.' . $row['handle']] = $row;
			}
		}

		$byKind = [];

		// Predefined filters without a row come first, in their own order, then the configured ones in theirs.
		foreach ($this->getDefaults() as $kind => $filters) {
			$order = -count($filters);

			foreach ($filters as $handle => $filter) {
				$threshold = $configured["$kind.$handle"]['threshold'] ?? null;
				$value = $threshold !== null ? (int)$threshold : $filter['threshold']['default'] ?? 0;

				$byKind[$kind][$handle] = new SavedFilter([
					'kind' => CheckKind::from($kind),
					'handle' => $handle,
					'defaultName' => $filter['name'],
					'description' => $filter['description'] instanceof Closure ? ($filter['description'])($value) : $filter['description'],
					'icon' => $filter['icon'],
					'params' => $filter['params'] instanceof Closure ? ($filter['params'])($value) : $filter['params'],
					'threshold' => $threshold !== null ? (int)$threshold : null,
					'thresholdField' => $filter['threshold'],
					'sortOrder' => $order++,
				]);
			}
		}

		foreach ($rows as $row) {
			$kind = CheckKind::tryFrom((string)$row['kind']);
			$handle = $row['handle'] !== null ? (string)$row['handle'] : null;

			// Rows of predefined filters Hub no longer has are left out.
			if ($kind === null || ($handle !== null && !isset($byKind[$kind->value][$handle]))) {
				continue;
			}

			$filter = $handle !== null ? $byKind[$kind->value][$handle] : $this->createFilter($row, $kind);
			$filter->id = (int)$row['id'];
			$filter->uid = (string)$row['uid'];
			$filter->name = (string)$row['name'];
			$filter->enabled = (bool)$row['enabled'];
			$filter->sortOrder = (int)$row['sortOrder'];

			$byKind[$kind->value][$handle ?? $filter->uid] = $filter;
		}

		$filters = [];

		foreach (CheckKind::cases() as $kind) {
			$items = array_values($byKind[$kind->value] ?? []);
			usort($items, fn (SavedFilter $a, SavedFilter $b) => $a->sortOrder <=> $b->sortOrder);

			foreach ($items as $filter) {
				$filters[$filter->getKey()] = $filter;
			}
		}

		return $filters;
	}

	public function getFilterById(int $id): ?SavedFilter
	{
		foreach ($this->getFilters() as $filter) {
			if ($filter->id === $id) {
				return $filter;
			}
		}

		return null;
	}

	/**
	 * The filter offered to everyone of a kind, by its handle if it's predefined, or by its UID if it's a custom one.
	 */
	public function getFilter(CheckKind $kind, string $handle): ?SavedFilter
	{
		return $this->getFilters($kind)[$kind->value . '.' . $handle] ?? null;
	}

	/**
	 * Threshold of a predefined filter, like the months after which entries are stale, as configured or Hub's own.
	 */
	public function getThreshold(CheckKind $kind, string $handle): int
	{
		return $this->getFilter($kind, $handle)?->getThreshold() ?? 0;
	}

	/**
	 * The enabled filters of a kind offered to everyone, in their order.
	 *
	 * @return SavedFilter[]
	 */
	public function getEnabledFilters(CheckKind $kind): array
	{
		return array_values(array_filter($this->getFilters($kind), fn (SavedFilter $filter) => $filter->enabled));
	}

	/**
	 * The custom filter of a kind with the UID, whose condition a list applies; disabled ones too, as being disabled only
	 * keeps them out of the filter bar.
	 */
	public function getCustomFilter(CheckKind $kind, string $uid): ?SavedFilter
	{
		$filter = $this->getFilter($kind, $uid);

		return $filter !== null && $filter->isCustom() ? $filter : null;
	}

	/**
	 * A user's own filters of a kind, by their name.
	 *
	 * @return SavedFilter[]
	 */
	public function getOwnFilters(CheckKind $kind, int $userId): array
	{
		$rows = $this->query()
			->where(['userId' => $userId, 'kind' => $kind->value])
			->orderBy(['name' => SORT_ASC, 'id' => SORT_ASC])
			->all();

		$filters = array_map(fn (array $row) => $this->createFilter($row, $kind), $rows);

		// Names sort naturally, ignoring case, as the lists do.
		usort($filters, fn (SavedFilter $a, SavedFilter $b) => strnatcasecmp($a->name, $b->name));

		return $filters;
	}

	/**
	 * A user's own filter with the ID; null if it isn't theirs.
	 */
	public function getOwnFilterById(int $id, int $userId): ?SavedFilter
	{
		$row = $this->query()->where(['id' => $id, 'userId' => $userId])->one();
		$kind = $row !== null ? CheckKind::tryFrom((string)$row['kind']) : null;

		return $kind !== null ? $this->createFilter($row, $kind) : null;
	}

	/**
	 * A user's own filter of a kind with the name, ignoring case, to save the filters by it again.
	 */
	public function getOwnFilterByName(CheckKind $kind, int $userId, string $name): ?SavedFilter
	{
		foreach ($this->getOwnFilters($kind, $userId) as $filter) {
			if (mb_strtolower($filter->name) === mb_strtolower(trim($name))) {
				return $filter;
			}
		}

		return null;
	}

	/**
	 * Query params of a list that are its filters, as they're saved: the site scope, the page, the sorting and the review of
	 * the selection are left out, as are empty and malformed ones, in the order of their names.
	 *
	 * @return array<string,string>
	 */
	public function normalizeParams(mixed $params): array
	{
		if (!is_array($params)) {
			return [];
		}

		$normalized = [];

		foreach ($params as $name => $value) {
			$value = is_scalar($value) ? trim((string)$value) : '';

			if (!is_string($name) || in_array($name, self::IGNORED_PARAMS, true) || !preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $name) || $value === '' || mb_strlen($value) > self::MAX_LENGTH) {
				continue;
			}

			$normalized[$name] = $value;
		}

		ksort($normalized);

		return array_slice($normalized, 0, self::MAX_PARAMS, true);
	}

	/**
	 * Condition narrowing a query of the elements of a kind to those the custom filter with the UID matches, on the selected
	 * sites; the condition narrows a query of its own, so its rules don't override the list's filters on the same param. A
	 * filter that no longer exists matches nothing.
	 *
	 * @return array<string,mixed>
	 */
	public function elementCondition(CheckKind $kind, string $uid): array
	{
		$filter = $this->getCustomFilter($kind, $uid);
		$condition = $filter?->getCondition();
		/** @var class-string<ElementInterface>|null $elementType */
		$elementType = $kind->elementType();

		if ($elementType === null || !$condition instanceof ElementConditionInterface) {
			return ['elements.id' => 0];
		}

		/** @var ElementQuery $query */
		$query = $elementType::find()->status(null);

		// Users aren't site-specific.
		if ($kind !== CheckKind::Users) {
			$query->siteId(Hub::getInstance()->siteScope->getSiteIds());
		}

		$condition->modifyQuery($query);

		return ['elements.id' => $query->select(['elements.id'])];
	}

	/**
	 * Condition the items of a structure list match, of the custom filter with the UID; null for none, which matches all of
	 * them. A filter that no longer exists matches nothing.
	 *
	 * @return InfoCondition|false|null false to match nothing
	 */
	public function infoCondition(CheckKind $kind, ?string $uid): InfoCondition|false|null
	{
		if ($uid === null) {
			return null;
		}

		$condition = $this->getCustomFilter($kind, $uid)?->getCondition();

		return $condition instanceof InfoCondition ? $condition : false;
	}

	/**
	 * Variables of the saved filters of a list (see the `saved-filters` component): those offered to everyone that are
	 * enabled and the user's own ones, each with the query params of the list it shows, the custom filter applied, and
	 * whether the user may manage the filters offered to everyone.
	 *
	 * @param string $path page the list is on, which the filters link to
	 * @param string|null $current UID of the custom filter applied
	 * @return array{kind:string,path:string,current:string|null,shared:array<int,array<string,mixed>>,own:array<int,array<string,mixed>>,canManage:bool,manageUrl:string}
	 */
	public function getMenu(CheckKind $kind, string $path, ?string $current = null): array
	{
		$user = Craft::$app->getUser();
		$item = fn (SavedFilter $filter) => [
			'id' => $filter->id,
			'name' => $filter->getName(),
			'description' => $filter->description,
			'icon' => $filter->getIcon(),
			'params' => $filter->getListParams(),
			'custom' => $filter->isCustom() ? $filter->uid : null,
		];

		return [
			'kind' => $kind->value,
			'path' => $path,
			'current' => $current,
			'shared' => array_map($item, $this->getEnabledFilters($kind)),
			'own' => $user->getId() !== null ? array_map($item, $this->getOwnFilters($kind, (int)$user->getId())) : [],
			'canManage' => $user->checkPermission(Hub::PERMISSION_MANAGE_FILTERS),
			'manageUrl' => 'hub/manage/settings/filters#hub-filters-' . $kind->value,
		];
	}

	/**
	 * Saves a new filter, last in the order of its kind, or the changes to a filter; a predefined filter gets a row once it's
	 * saved, keeping only its name, its threshold and whether it's enabled.
	 */
	public function saveFilter(SavedFilter $filter): bool
	{
		if (!$filter->validate()) {
			return false;
		}

		$columns = [
			'name' => $filter->name !== '' ? $filter->name : null,
			'enabled' => $filter->enabled,
		];

		if ($filter->isPredefined()) {
			$columns['threshold'] = $filter->threshold;
		} else {
			$columns += [
				'description' => $filter->description !== '' ? $filter->description : null,
				'icon' => $filter->icon ?: null,
				'params' => $filter->params !== [] ? Json::encode($filter->params) : null,
				'matchCondition' => $filter->isCustom() ? Json::encode($filter->condition) : null,
			];
		}

		$db = Craft::$app->getDb();

		if ($filter->id === null) {
			$filter->uid ??= StringHelper::UUID();
			$filter->sortOrder = $filter->isOwn() ? 0 : (int)(new Query())->from(self::TABLE)->where(['kind' => $filter->kind->value, 'userId' => null])->max('[[sortOrder]]') + 1;
			$db->createCommand()->insert(self::TABLE, [
				...$columns,
				'kind' => $filter->kind->value,
				'handle' => $filter->handle,
				'userId' => $filter->userId,
				'sortOrder' => $filter->sortOrder,
				'uid' => $filter->uid,
			])->execute();
			$filter->id = (int)$db->getLastInsertID(self::TABLE);
		} else {
			$db->createCommand()->update(self::TABLE, $columns, ['id' => $filter->id])->execute();
		}

		$this->filters = null;

		return true;
	}

	/**
	 * Deletes a custom filter, or a user's own filter if the user ID is given; predefined filters can't be deleted, only
	 * disabled.
	 */
	public function deleteFilterById(int $id, ?int $userId = null): bool
	{
		$deleted = Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['id' => $id, 'handle' => null, 'userId' => $userId])->execute() > 0;
		$this->filters = null;

		return $deleted;
	}

	/**
	 * Orders the filters of a kind offered to everyone as the IDs are given.
	 *
	 * @param int[] $ids
	 */
	public function reorderFilters(array $ids): void
	{
		$command = Craft::$app->getDb()->createCommand();

		foreach (array_values($ids) as $order => $id) {
			$command->update(self::TABLE, ['sortOrder' => $order + 1], ['id' => (int)$id, 'userId' => null], updateTimestamp: false)->execute();
		}

		$this->filters = null;
	}

	/**
	 * Gives the predefined filters without a row one and numbers the filters of each kind in their current order, so they can
	 * be reordered and toggled by their ID. Run once Hub is installed, see Hub::afterInstall().
	 */
	public function saveDefaults(): void
	{
		$command = Craft::$app->getDb()->createCommand();

		foreach (CheckKind::cases() as $kind) {
			$order = 0;

			foreach ($this->getFilters($kind) as $filter) {
				$order++;

				if ($filter->id === null) {
					$command->insert(self::TABLE, [
						'kind' => $kind->value,
						'handle' => $filter->handle,
						'enabled' => $filter->enabled,
						'sortOrder' => $order,
						'uid' => StringHelper::UUID(),
					])->execute();
				} elseif ($filter->sortOrder !== $order) {
					$command->update(self::TABLE, ['sortOrder' => $order], ['id' => $filter->id], updateTimestamp: false)->execute();
				}
			}
		}

		$this->filters = null;
	}

	private function query(): Query
	{
		return (new Query())
			->select(['id', 'kind', 'handle', 'userId', 'name', 'description', 'icon', 'params', 'matchCondition', 'threshold', 'enabled', 'sortOrder', 'uid'])
			->from(self::TABLE);
	}

	/**
	 * A custom filter or a user's own one from its row.
	 *
	 * @param array<string,mixed> $row
	 */
	private function createFilter(array $row, CheckKind $kind): SavedFilter
	{
		$params = $this->decode($row['params'] ?? null);

		return new SavedFilter([
			'id' => (int)$row['id'],
			'kind' => $kind,
			'userId' => $row['userId'] !== null ? (int)$row['userId'] : null,
			'name' => (string)$row['name'],
			'description' => (string)$row['description'],
			'icon' => $row['icon'] ?: null,
			'params' => $this->normalizeParams($params),
			'condition' => $this->decode($row['matchCondition'] ?? null),
			'enabled' => (bool)$row['enabled'],
			'sortOrder' => (int)$row['sortOrder'],
			'uid' => (string)$row['uid'],
		]);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function decode(mixed $value): ?array
	{
		$config = is_string($value) && $value !== '' ? Json::decodeIfJson($value) : null;

		return is_array($config) ? $config : null;
	}
}
