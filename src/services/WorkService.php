<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Connection;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\web\User as WebUser;
use DateTime;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\helpers\Row;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\MaintainerSource;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use yii\base\Component;
use yii\caching\CacheInterface;
use yii\caching\TagDependency;

/**
 * What the current user is responsible for, as told by what they authored, uploaded and maintain, which is what the "My
 * Work" page, the badge of the "Hub" navigation item and the dashboard widget show. Nothing is assigned to anyone; items
 * are only snoozed, by each user for themselves, as checks of content that's fine as it is would never be cleared.
 *
 * @internal
 */
final class WorkService extends Component
{
	/** path of the page, whose tabs are below it */
	public const PATH = 'hub/manage/work';

	/** sections of the page, by the path of their tab or the panel of the overview they are shown in */
	public const SECTION_DIAGNOSTICS = 'diagnostics';
	public const SECTION_DRAFTS = 'drafts';
	public const SECTION_COMING_UP = 'coming-up';
	public const SECTION_MAINTAINED = 'maintained';

	/** checks listed on the overview, each linking to the rest */
	public const ITEM_LIMIT = 5;

	/** drafts and entries coming up listed on the overview */
	public const LIST_LIMIT = 100;

	/** items listed per check on the diagnostics tab, each linking to the rest */
	public const CHECK_ITEM_LIMIT = 10;

	/** days an item can be snoozed for; 0 dismisses it for good */
	public const SNOOZE_DAYS = [7, 30, 90, 0];

	/** seconds the counts of a user are kept for, as the navigation shows them on every page */
	private const COUNTS_DURATION = 300;

	/** @var array<int,array{definition:CheckDefinition,query:ElementQuery<array-key,covariant ElementInterface>}>|null memoized, as the counts and the page share them */
	private ?array $diagnosticsQueries = null;

	/** whether the counts of the current user were forgotten since they were last counted in this request */
	private bool $forgotten = false;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly SiteScopeService $siteScope,
		private readonly Settings $settings,
		private readonly MaintainerService $maintainers,
		private readonly AccessService $access,
		private readonly DiagnosticsService $diagnostics,
		private readonly EntryService $entries,
		private readonly StructureService $structure,
		private readonly CheckService $checks,
		private readonly VolumeService $volumes,
		private readonly QueryService $queries,
		private readonly CacheInterface $cache,
		private readonly Connection $db,
		private readonly ?WebUser $user = null,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * Whether the current user may open their work.
	 */
	public function canView(): bool
	{
		$user = $this->user;

		return $user instanceof \craft\web\User && $user->checkPermission(Permission::PLUGIN) && $user->checkPermission(Permission::ViewWork->value);
	}

	/**
	 * How many items each section of the page holds for the current user, and each check flags, kept for a few minutes per
	 * user and site scope, or until they snooze something or change their entries or assets. Counting the checks takes the
	 * longest on large sites, so the navigation, the widget and the page all show these.
	 *
	 * @return array{drafts:int,diagnostics:int,comingUp:int,checks:array<string,int>} checks by their key
	 */
	public function getCounts(): array
	{
		$userId = $this->userId();

		if ($userId === null || !$this->canView()) {
			return ['drafts' => 0, 'diagnostics' => 0, 'comingUp' => 0, 'checks' => []];
		}

		$key = sprintf('hub:work:counts:%d:%s', $userId, implode(',', $this->siteScope->getSiteIds()));

		return $this->cache->getOrSet($key, fn(): array => $this->countAll(), self::COUNTS_DURATION, new TagDependency(['tags' => $this->cacheTag($userId)]));
	}

	/**
	 * The sections of the page, in their order, by their ID: what the tabs, the panels of the overview and the dashboard
	 * widget all name them and say of them, so they can't tell them apart. Those with a count are the numbers summing up the
	 * user's work; those without are always there. Diagnostics lead to their tab, the rest to their panel.
	 *
	 * @return array<string,array{label:string,description:string,icon:string,color:string,url:string,count:int|null}>
	 */
	public function getSections(): array
	{
		$counts = $this->getCounts();
		$entries = $this->canViewEntries();

		$sections = array_filter([
			self::SECTION_DIAGNOSTICS => $this->settings->enableDiagnostics ? [
				'label' => Craft::t('hub', 'Diagnostics'),
				'description' => Craft::t('hub', 'Issues found in entries you authored and assets you uploaded, most urgent first.'),
				'icon' => 'list-check',
				'color' => 'orange',
				'count' => $counts['diagnostics'],
			] : null,
			self::SECTION_DRAFTS => $entries ? [
				'label' => Craft::t('hub', 'Drafts'),
				'description' => Craft::t('hub', 'New entries and changes you started and haven’t published yet.'),
				'icon' => 'file-pen',
				'color' => 'blue',
				'count' => $counts['drafts'],
			] : null,
			self::SECTION_COMING_UP => $entries ? [
				'label' => Craft::t('hub', 'Coming up'),
				'description' => Craft::t('hub', 'Your entries that publish or expire within {days, plural, =1{one day} other{# days}}.', ['days' => EntryService::COMING_UP_DAYS]),
				'icon' => 'calendar-days',
				'color' => 'teal',
				'count' => $counts['comingUp'],
			] : null,
			self::SECTION_MAINTAINED => [
				'label' => Craft::t('hub', 'Maintained'),
				'description' => Craft::t('hub', 'Sections, entry types, fields and volumes you look after, yourself or through a group.'),
				'icon' => 'wrench',
				'color' => 'gray',
				'count' => null,
			],
		]);

		$result = [];

		foreach ($sections as $id => $section) {
			$result[$id] = [...$section, 'url' => $id === self::SECTION_DIAGNOSTICS
				? $this->siteScope->pageUrl(self::PATH . "/{$id}")
				: $this->siteScope->pageUrl(self::PATH) . "#hub-work-{$id}"];
		}

		return $result;
	}

	/**
	 * Drafts the current user started, of new and of existing entries, and their unsaved changes, the last edited first. Each
	 * is the draft itself rather than its entry, so it leads to what they were working on.
	 *
	 * @param int $limit items listed
	 * @return array{items:array<int,array{element:ElementInterface,note:string,date:DateTime|null}>,total:int}
	 */
	public function getDrafts(int $limit = self::LIST_LIMIT): array
	{
		$query = $this->draftsQuery();
		$total = $this->total($query);
		$items = [];

		foreach ($this->items($query, $total, $limit) as $entry) {
			$items[] = ['element' => $entry, 'note' => $this->draftNote($entry), 'date' => $entry->dateUpdated];
		}

		return ['items' => $items, 'total' => $total];
	}

	/**
	 * Checks flagging entries the current user is an author of and assets they uploaded, the most urgent first, each with
	 * the first of the items, if any are asked for, and a link to all of them; items they snoozed for a check aren't
	 * counted, and checks without any are left out. Their counts are those of {@see getCounts()}.
	 *
	 * @param int $limit items listed per check
	 * @param string|null $key the check to read alone, see CheckDefinition::getKey()
	 * @return list<Check>
	 */
	public function getDiagnostics(int $limit = 0, ?string $key = null): array
	{
		$checks = [];
		$totals = $this->getCounts()['checks'];

		foreach ($this->diagnosticsQueries() as ['definition' => $definition, 'query' => $query]) {
			$total = $totals[$definition->getKey()] ?? 0;

			if ($total > 0 && ($key === null || $definition->getKey() === $key)) {
				$check = new Check($definition->getListName(), $definition->getListDescription(), '', $definition->getSeverity(), $definition->getIcon(), false);
				$check->key = $definition->getKey();
				$check->count = $total;
				$check->items = $this->items((clone $query)->orderBy(['elements.dateUpdated' => SORT_DESC]), $total, $limit);
				$checks[] = $check->link($this->listUrl($definition), Craft::t('hub', 'Show list'));
			}
		}

		usort($checks, fn(Check $a, Check $b): int => $a->severity->rank() <=> $b->severity->rank());

		return $checks;
	}

	/**
	 * Entries of the current user being published or expiring soon, soonest first, each with which it is and when.
	 *
	 * @param int $limit items listed
	 * @return array{items:array<int,array{element:ElementInterface,note:string,date:DateTime|null}>,total:int}
	 */
	public function getComingUp(int $limit = self::LIST_LIMIT): array
	{
		$query = $this->comingUpQuery();
		$total = $this->total($query);
		$now = new DateTime();
		$items = [];

		foreach ($this->items($query, $total, $limit) as $entry) {
			$publishes = $entry->postDate !== null && $entry->postDate > $now;
			$items[] = [
				'element' => $entry,
				'note' => $publishes ? Craft::t('hub', 'Publishes') : Craft::t('hub', 'Expires'),
				'date' => $publishes ? $entry->postDate : $entry->expiryDate,
			];
		}

		return ['items' => $items, 'total' => $total];
	}

	/**
	 * What the current user maintains, themselves or through their groups, on the pages they may view, each with how many
	 * of its checks fail and the severity of the most urgent one, the most urgent first.
	 *
	 * @return list<array{source:MaintainerSource,failed:int,severity:Severity|null}>
	 */
	public function getMaintained(): array
	{
		$user = $this->user?->getIdentity();

		if (!$user instanceof User) {
			return [];
		}

		$maintained = [];

		foreach ($this->maintainers->getMaintainedBy($user) as $source) {
			$page = Page::fromList($source->list);

			if ($page instanceof Page && $this->access->canView($page)) {
				$failed = array_filter($this->checksOf($source), fn(Check $check): bool => !$check->passed);
				$ranks = array_map(fn(Check $check): Severity => $check->severity, $failed);
				usort($ranks, fn(Severity $a, Severity $b): int => $a->rank() <=> $b->rank());
				$maintained[] = ['source' => $source, 'failed' => count($failed), 'severity' => $ranks[0] ?? null];
			}
		}

		usort($maintained, fn(array $a, array $b): int => ($a['severity']?->rank() ?? PHP_INT_MAX) <=> ($b['severity']?->rank() ?? PHP_INT_MAX));

		return $maintained;
	}

	/**
	 * Number of what the current user snoozed, like {@see getSnoozed()} lists, counted rather than loaded, so items no
	 * longer there are counted until they're cleaned up.
	 */
	public function countSnoozed(): int
	{
		$userId = $this->userId();

		return $userId !== null ? (int) $this->snoozedQuery($userId)->count() : 0;
	}

	/**
	 * What the current user snoozed, what returns soonest first and what they dismissed last; items and checks no longer there
	 * are left out.
	 *
	 * @return list<array{element:ElementInterface,check:CheckDefinition,until:DateTime|null}>
	 */
	public function getSnoozed(): array
	{
		$userId = $this->userId();

		if ($userId === null) {
			return [];
		}

		$rows = $this->snoozedQuery($userId)
			->select(['elementId', 'checkKey', 'dateUntil'])
			->orderBy(['elementId' => SORT_ASC])
			->all();

		$diagnostics = $this->diagnostics;
		$ids = [];

		foreach ($rows as $row) {
			$elementType = $diagnostics->getDefinition(Row::string($row, 'checkKey'))?->kind->elementType();

			if ($elementType !== null) {
				$ids[$elementType][] = Row::int($row, 'elementId');
			}
		}

		/** @var array<int,ElementInterface> $elements */
		$elements = [];

		foreach ($ids as $elementType => $elementIds) {
			/** @var array<int,ElementInterface> $found */
			$found = $elementType::find()->id(array_unique($elementIds))->siteId('*')->unique()->status(null)->indexBy('id')->all();
			$elements += $found;
		}

		$snoozed = [];

		foreach ($rows as $row) {
			$check = $diagnostics->getDefinition(Row::string($row, 'checkKey'));
			$element = $elements[Row::int($row, 'elementId')] ?? null;

			if ($check instanceof CheckDefinition && $element !== null) {
				$snoozed[] = ['element' => $element, 'check' => $check, 'until' => Row::date($row, 'dateUntil')];
			}
		}

		// Those snoozed for good come last.
		usort($snoozed, static function (array $a, array $b): int {
			$x = $a['until']?->getTimestamp();
			$y = $b['until']?->getTimestamp();

			if ($x === null || $y === null) {
				return $x === null ? ($y === null ? 0 : 1) : -1;
			}

			return $x <=> $y;
		});

		return $snoozed;
	}

	/**
	 * Snoozes of the current user that are still on.
	 *
	 * @return Query<array-key,mixed>
	 */
	private function snoozedQuery(int $userId): Query
	{
		return (new Query())
			->from(Tables::SNOOZES)
			->where(['userId' => $userId])
			->andWhere(['or', ['dateUntil' => null], ['>', 'dateUntil', Db::prepareDateForDb(new DateTime())]]);
	}

	/**
	 * Snoozes an element for a check, for the current user: for the days given, or for good without any.
	 */
	public function snooze(CheckDefinition $check, int $elementId, ?int $days): void
	{
		$userId = (int) $this->userId();

		$this->db->createCommand()->upsert(Tables::SNOOZES, [
			'userId' => $userId,
			'elementId' => $elementId,
			'checkKey' => $check->getKey(),
			'dateUntil' => $days !== null ? Db::prepareDateForDb((new DateTime())->modify("+{$days} days")) : null,
		])->execute();

		TagDependency::invalidate($this->cache, $this->cacheTag($userId));
	}

	/**
	 * Lets an element be flagged by a check for the current user again.
	 */
	public function wake(string $checkKey, int $elementId): void
	{
		$userId = (int) $this->userId();

		$this->db->createCommand()->delete(Tables::SNOOZES, ['userId' => $userId, 'elementId' => $elementId, 'checkKey' => $checkKey])->execute();

		TagDependency::invalidate($this->cache, $this->cacheTag($userId));
	}

	/**
	 * Forgets the counts of the current user, as they changed their entries or assets, so what they fixed leaves their
	 * work right away; changes of others show once the counts run out. Only once until they're counted again, as bulk
	 * actions save many elements.
	 */
	public function forgetCounts(): void
	{
		$userId = $this->userId();

		if ($userId !== null && !$this->forgotten) {
			$this->forgotten = true;
			TagDependency::invalidate($this->cache, $this->cacheTag($userId));
		}
	}

	/**
	 * Removes the snoozes that ran out and those of checks that no longer exist; those of users and elements are deleted
	 * along with them.
	 */
	public function deleteExpired(): void
	{
		$keys = array_map(fn(CheckDefinition $definition): string => $definition->getKey(), $this->diagnostics->getDefinitions());

		$this->db->createCommand()->delete(Tables::SNOOZES, [
			'or',
			['<=', 'dateUntil', Db::prepareDateForDb(new DateTime())],
			['not in', 'checkKey', $keys],
		])->execute();
	}

	/**
	 * @return array{drafts:int,diagnostics:int,comingUp:int,checks:array<string,int>}
	 */
	private function countAll(): array
	{
		$this->forgotten = false;
		$checks = [];

		foreach ($this->diagnosticsQueries() as ['definition' => $definition, 'query' => $query]) {
			$checks[$definition->getKey()] = $this->total($query);
		}

		return [
			'drafts' => $this->total($this->draftsQuery()),
			'diagnostics' => array_sum($checks),
			'comingUp' => $this->total($this->comingUpQuery()),
			'checks' => $checks,
		];
	}

	/**
	 * Queries of the items of the current user each enabled check of entries and assets flags, on the pages they may view.
	 *
	 * @return array<int,array{definition:CheckDefinition,query:ElementQuery<array-key,covariant ElementInterface>}>
	 */
	private function diagnosticsQueries(): array
	{
		if ($this->diagnosticsQueries !== null) {
			return $this->diagnosticsQueries;
		}

		$userId = $this->userId();
		$queries = [];

		if ($userId !== null && $this->settings->enableDiagnostics) {
			foreach ([CheckKind::Entries, CheckKind::Assets] as $kind) {
				if ($this->access->canView($kind->page())) {
					foreach ($this->diagnostics->getEnabledChecks($kind) as $definition) {
						$query = $this->diagnostics->getFailingQuery($definition);

						if ($query instanceof ElementQuery) {
							$this->narrowFirst($query, $kind === CheckKind::Entries ? $this->authoredCondition($userId) : ['assets.uploaderId' => $userId]);
							$query->andWhere($this->notSnoozedCondition($userId, $definition->getKey()));
							$queries[] = ['definition' => $definition, 'query' => $query];
						}
					}
				}
			}
		}

		return $this->diagnosticsQueries = $queries;
	}

	/**
	 * Drafts and unsaved changes the current user started, of entries of sections: those of nested entries are parts of them.
	 * Drafts of new entries they never saved are left out, as Craft discards them.
	 *
	 * @return EntryQuery<array-key,Entry>|null
	 */
	private function draftsQuery(): ?EntryQuery
	{
		$userId = $this->userId();

		if ($userId === null || !$this->canViewEntries()) {
			return null;
		}

		$query = Entry::find()
			->drafts()
			->provisionalDrafts(null)
			->draftCreator($userId)
			->savedDraftsOnly()
			->siteId($this->siteScope->getSiteIds())
			->unique()
			->status(null)
			->andWhere(['not', ['entries.sectionId' => null]])
			->orderBy(['elements.dateUpdated' => SORT_DESC]);

		if (($viewable = $this->entries->viewableCondition()) !== null) {
			$query->andWhere($viewable);
		}

		return $query;
	}

	/**
	 * What a draft is, or its name for those of existing entries, which there can be several of.
	 */
	private function draftNote(ElementInterface $draft): string
	{
		return match (true) {
			$draft->isProvisionalDraft => Craft::t('hub', 'Unsaved changes'),
			$draft->getIsUnpublishedDraft() => Craft::t('hub', 'New entry'),
			default => Fill::text(ArrayHelper::getValue($draft, 'draftName')) ?? Craft::t('hub', 'Draft'),
		};
	}

	/**
	 * Enabled entries of the current user being published or expiring within the next days, soonest first.
	 *
	 * @return EntryQuery<array-key,Entry>|null
	 */
	private function comingUpQuery(): ?EntryQuery
	{
		$filter = $this->ownEntries();

		if (!$filter instanceof EntryFilter) {
			return null;
		}

		$filter->comingUp = true;
		$now = Db::prepareDateForDb(new DateTime());

		return $this->entries->getQuery($filter)
			->orderBy([sprintf("CASE WHEN [[entries.postDate]] > '%s' THEN [[entries.postDate]] ELSE [[entries.expiryDate]] END", $now) => SORT_ASC]);
	}

	/**
	 * Filter of the entries the current user is an author of, unless they may not view entries.
	 */
	private function ownEntries(): ?EntryFilter
	{
		$userId = $this->userId();

		if ($userId === null || !$this->canViewEntries()) {
			return null;
		}

		$filter = new EntryFilter();
		$filter->authors = [$userId];

		return $filter;
	}

	/**
	 * Condition matching the queried entries the user is an author of, whatever authors a check's own filter asks for.
	 *
	 * @return list<mixed>
	 */
	private function authoredCondition(int $userId): array
	{
		return ['exists', (new Query())
			->from(['hubWorkAuthors' => Table::ENTRIES_AUTHORS])
			->where('[[hubWorkAuthors.entryId]] = [[entries.id]]')
			->andWhere(['hubWorkAuthors.authorId' => $userId])];
	}

	/**
	 * Puts a condition before those of a query. Databases plan large queries partly in the order of their conditions,
	 * PostgreSQL once they join more than eight tables, so the user's few items are found first rather than evaluating the
	 * conditions of a check, like the references of an element, for all elements of the site.
	 *
	 * @param array<int|string,mixed> $condition
	 *
	 * @param ElementQuery<array-key,covariant ElementInterface> $query
	 */
	private function narrowFirst(ElementQuery $query, array $condition): void
	{
		$query->where($query->where !== null && $query->where !== [] ? ['and', $condition, $query->where] : $condition);
	}

	/**
	 * Condition leaving out the queried elements the user snoozed a check for.
	 *
	 * @return list<mixed>
	 */
	private function notSnoozedCondition(int $userId, string $checkKey): array
	{
		return ['not exists', (new Query())
			->from(['hubSnoozes' => Tables::SNOOZES])
			->where('[[hubSnoozes.elementId]] = [[elements.id]]')
			->andWhere(['hubSnoozes.userId' => $userId, 'hubSnoozes.checkKey' => $checkKey])
			->andWhere(['or', ['hubSnoozes.dateUntil' => null], ['>', 'hubSnoozes.dateUntil', Db::prepareDateForDb(new DateTime())]])];
	}

	/**
	 * The list of the items of the current user a check flags, on the tab of their kind, along with those they snoozed, as
	 * lists don't know of those.
	 */
	private function listUrl(CheckDefinition $definition): string
	{
		$tab = $definition->kind === CheckKind::Entries ? 'entries' : 'assets';

		return $this->siteScope->pageUrl(self::PATH . "/{$tab}", $definition->getFilter()?->getListParams() ?? []);
	}

	/**
	 * The checks of what a user maintains, while diagnostics are enabled. Only whether they pass is shown, so the elements
	 * it holds aren't counted.
	 *
	 * @return Check[]
	 */
	private function checksOf(MaintainerSource $source): array
	{

		if (!$this->settings->enableDiagnostics) {
			return [];
		}

		$id = (int) $source->item->id;

		return match ($source->list) {
			ColumnService::LIST_SECTIONS => ($info = $this->structure->getSection($id)) instanceof SectionInfo ? $this->checks->forSection($info, counted: false) : [],
			ColumnService::LIST_ENTRY_TYPES => ($info = $this->structure->getEntryType($id)) instanceof EntryTypeInfo ? $this->checks->forEntryType($info, counted: false) : [],
			ColumnService::LIST_FIELDS => ($info = $this->structure->getField($id)) instanceof FieldInfo ? $this->checks->forField($info) : [],
			ColumnService::LIST_VOLUMES => ($info = $this->volumes->getVolume($id)) instanceof VolumeInfo ? $this->checks->forVolume($info, counted: false) : [],
			default => [],
		};
	}

	private function canViewEntries(): bool
	{
		return $this->access->canView(Page::Entries);
	}

	private function userId(): ?int
	{
		$id = $this->user?->getId();

		return $id !== null ? (int) $id : null;
	}

	private function cacheTag(int $userId): string
	{
		return "hub:work:{$userId}";
	}

	/**
	 * @param ElementQuery<array-key,covariant ElementInterface>|null $query
	 */
	private function total(?ElementQuery $query): int
	{
		return $query instanceof ElementQuery ? $this->queries->total(clone $query) : 0;
	}

	/**
	 * @template T of ElementInterface
	 * @param int $total the elements the query matches
	 * @param ElementQuery<array-key,covariant T>|null $query
	 * @return list<T>
	 */
	private function items(?ElementQuery $query, int $total, int $limit = self::ITEM_LIMIT): array
	{
		return $query instanceof ElementQuery && $limit > 0 && $total > 0 ? $this->queries->page(clone $query, 0, $limit, $total) : [];
	}
}
