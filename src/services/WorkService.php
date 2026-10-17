<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\MaintainerSource;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * What the current user is responsible for, as told by what they authored, uploaded and maintain, which is what the "My
 * Work" page, the badge of the "Hub" navigation item and the dashboard widget show. Nothing is assigned to anyone; items
 * are only snoozed, by each user for themselves, as checks of content that's fine as it is would never be cleared.
 */
class WorkService extends Component
{
	/** items a check is snoozed for by a user, until a date or, without one, for good */
	public const TABLE_SNOOZES = '{{%hub_snoozes}}';

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

	/** @var array<int,array{definition:CheckDefinition,query:ElementQuery}>|null memoized, as the counts and the page share them */
	private ?array $diagnosticsQueries = null;

	/**
	 * Whether the current user may open their work.
	 */
	public function canView(): bool
	{
		$user = Craft::$app->getUser();

		return $user->checkPermission('accessPlugin-' . Hub::getInstance()->id) && $user->checkPermission(Hub::PERMISSION_VIEW_WORK);
	}

	/**
	 * How many items each section of the page holds for the current user, kept for a few minutes per user and site scope,
	 * or until they snooze something.
	 *
	 * @return array{drafts:int,diagnostics:int,comingUp:int}
	 */
	public function getCounts(): array
	{
		$userId = $this->userId();

		if ($userId === null || !$this->canView()) {
			return ['drafts' => 0, 'diagnostics' => 0, 'comingUp' => 0];
		}

		$key = sprintf('hub:work:counts:%d:%s', $userId, implode(',', Hub::getInstance()->siteScope->getSiteIds()));

		return Craft::$app->getCache()->getOrSet($key, fn () => $this->countAll(), self::COUNTS_DURATION, new TagDependency(['tags' => $this->cacheTag($userId)]));
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
		$hub = Hub::getInstance();
		$counts = $this->getCounts();
		$entries = $this->canViewEntries();

		$sections = array_filter([
			self::SECTION_DIAGNOSTICS => $hub->settings->enableDiagnostics ? [
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

		foreach ($sections as $id => $section) {
			$sections[$id]['url'] = $id === self::SECTION_DIAGNOSTICS
				? $hub->siteScope->pageUrl(self::PATH . "/$id")
				: $hub->siteScope->pageUrl(self::PATH) . "#hub-work-$id";
		}

		return $sections;
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
		$items = [];

		foreach ($query !== null ? $this->items($query, $limit) : [] as $entry) {
			$items[] = ['element' => $entry, 'note' => $this->draftNote($entry), 'date' => $entry->dateUpdated];
		}

		return ['items' => $items, 'total' => $this->total($query)];
	}

	/**
	 * Checks flagging entries the current user is an author of and assets they uploaded, the most urgent first, each with
	 * the first of the items, if any are asked for, and a link to all of them; items they snoozed for a check aren't
	 * counted, and checks without any are left out.
	 *
	 * @param int $limit items listed per check
	 * @return Check[]
	 */
	public function getDiagnostics(int $limit = 0): array
	{
		$checks = [];

		foreach ($this->diagnosticsQueries() as ['definition' => $definition, 'query' => $query]) {
			$total = $this->total($query);

			if ($total > 0) {
				$check = new Check($definition->getListName(), $definition->getListDescription(), '', $definition->getSeverity(), $definition->getIcon(), false);
				$check->key = $definition->getKey();
				$check->count = $total;
				$check->items = $this->items((clone $query)->orderBy(['elements.dateUpdated' => SORT_DESC]), $limit);
				$checks[] = $check->link($this->listUrl($definition), Craft::t('hub', 'Show list'));
			}
		}

		usort($checks, fn (Check $a, Check $b) => $a->severity->rank() <=> $b->severity->rank());

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
		$now = new DateTime();
		$items = [];

		foreach ($query !== null ? $this->items($query, $limit) : [] as $entry) {
			/** @var Entry $entry */
			$publishes = $entry->postDate !== null && $entry->postDate > $now;
			$items[] = [
				'element' => $entry,
				'note' => $publishes ? Craft::t('hub', 'Publishes') : Craft::t('hub', 'Expires'),
				'date' => $publishes ? $entry->postDate : $entry->expiryDate,
			];
		}

		return ['items' => $items, 'total' => $this->total($query)];
	}

	/**
	 * What the current user maintains, themselves or through their groups, on the pages they may view, each with how many
	 * of its checks fail and the severity of the most urgent one, the most urgent first.
	 *
	 * @return array<int,array{source:MaintainerSource,failed:int,severity:Severity|null}>
	 */
	public function getMaintained(): array
	{
		$hub = Hub::getInstance();
		$user = Craft::$app->getUser()->getIdentity();

		if (!$user instanceof User) {
			return [];
		}

		$maintained = [];

		foreach ($hub->maintainers->getMaintainedBy($user) as $source) {
			$page = Page::fromList($source->list);

			if ($page !== null && $hub->access->canView($page)) {
				$failed = array_filter($this->checksOf($source), fn (Check $check) => !$check->passed);
				$ranks = array_map(fn (Check $check) => $check->severity, $failed);
				usort($ranks, fn (Severity $a, Severity $b) => $a->rank() <=> $b->rank());
				$maintained[] = ['source' => $source, 'failed' => count($failed), 'severity' => $ranks[0] ?? null];
			}
		}

		usort($maintained, fn (array $a, array $b) => ($a['severity']?->rank() ?? PHP_INT_MAX) <=> ($b['severity']?->rank() ?? PHP_INT_MAX));

		return $maintained;
	}

	/**
	 * What the current user snoozed, what returns soonest first and what they dismissed last; items and checks no longer there
	 * are left out.
	 *
	 * @return array<int,array{element:ElementInterface,check:CheckDefinition,until:DateTime|null}>
	 */
	public function getSnoozed(): array
	{
		$userId = $this->userId();

		if ($userId === null) {
			return [];
		}

		$rows = (new Query())
			->select(['elementId', 'checkKey', 'dateUntil'])
			->from(self::TABLE_SNOOZES)
			->where(['userId' => $userId])
			->andWhere(['or', ['dateUntil' => null], ['>', 'dateUntil', Db::prepareDateForDb(new DateTime())]])
			->orderBy(['elementId' => SORT_ASC])
			->all();

		$diagnostics = Hub::getInstance()->diagnostics;
		$ids = [];

		foreach ($rows as $row) {
			$elementType = $diagnostics->getDefinition((string)$row['checkKey'])?->kind->elementType();

			if ($elementType !== null) {
				$ids[$elementType][] = (int)$row['elementId'];
			}
		}

		$elements = [];

		foreach ($ids as $elementType => $elementIds) {
			$elements += $elementType::find()->id(array_unique($elementIds))->siteId('*')->unique()->status(null)->indexBy('id')->all();
		}

		$snoozed = [];

		foreach ($rows as $row) {
			$check = $diagnostics->getDefinition((string)$row['checkKey']);
			$element = $elements[(int)$row['elementId']] ?? null;

			if ($check !== null && $element !== null) {
				$snoozed[] = ['element' => $element, 'check' => $check, 'until' => $row['dateUntil'] !== null ? DateTimeHelper::toDateTime($row['dateUntil']) : null];
			}
		}

		usort($snoozed, fn (array $a, array $b) => [$a['until'] === null, $a['until']] <=> [$b['until'] === null, $b['until']]);

		return $snoozed;
	}

	/**
	 * Snoozes an element for a check, for the current user: for the days given, or for good without any.
	 */
	public function snooze(CheckDefinition $check, int $elementId, ?int $days): void
	{
		$userId = (int)$this->userId();

		Craft::$app->getDb()->createCommand()->upsert(self::TABLE_SNOOZES, [
			'userId' => $userId,
			'elementId' => $elementId,
			'checkKey' => $check->getKey(),
			'dateUntil' => $days !== null ? Db::prepareDateForDb((new DateTime())->modify("+$days days")) : null,
		])->execute();

		TagDependency::invalidate(Craft::$app->getCache(), $this->cacheTag($userId));
	}

	/**
	 * Lets an element be flagged by a check for the current user again.
	 */
	public function wake(string $checkKey, int $elementId): void
	{
		$userId = (int)$this->userId();

		Craft::$app->getDb()->createCommand()->delete(self::TABLE_SNOOZES, ['userId' => $userId, 'elementId' => $elementId, 'checkKey' => $checkKey])->execute();

		TagDependency::invalidate(Craft::$app->getCache(), $this->cacheTag($userId));
	}

	/**
	 * Removes the snoozes that ran out and those of checks that no longer exist; those of users and elements are deleted
	 * along with them.
	 */
	public function deleteExpired(): void
	{
		$keys = array_map(fn (CheckDefinition $definition) => $definition->getKey(), Hub::getInstance()->diagnostics->getDefinitions());

		Craft::$app->getDb()->createCommand()->delete(self::TABLE_SNOOZES, [
			'or',
			['<=', 'dateUntil', Db::prepareDateForDb(new DateTime())],
			['not in', 'checkKey', $keys],
		])->execute();
	}

	/**
	 * @return array{drafts:int,diagnostics:int,comingUp:int}
	 */
	private function countAll(): array
	{
		return [
			'drafts' => $this->total($this->draftsQuery()),
			'diagnostics' => array_sum(array_map(fn (array $item) => $this->total($item['query']), $this->diagnosticsQueries())),
			'comingUp' => $this->total($this->comingUpQuery()),
		];
	}

	/**
	 * Queries of the items of the current user each enabled check of entries and assets flags, on the pages they may view.
	 *
	 * @return array<int,array{definition:CheckDefinition,query:ElementQuery}>
	 */
	private function diagnosticsQueries(): array
	{
		if ($this->diagnosticsQueries !== null) {
			return $this->diagnosticsQueries;
		}

		$hub = Hub::getInstance();
		$userId = $this->userId();
		$queries = [];

		if ($userId !== null && $hub->settings->enableDiagnostics) {
			foreach ([CheckKind::Entries, CheckKind::Assets] as $kind) {
				if ($hub->access->canView($kind->page())) {
					foreach ($hub->diagnostics->getEnabledChecks($kind) as $definition) {
						$query = $hub->diagnostics->getFailingQuery($definition);

						if ($query !== null) {
							$query->andWhere($kind === CheckKind::Entries ? $this->authoredCondition($userId) : ['assets.uploaderId' => $userId]);
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
	 */
	private function draftsQuery(): ?EntryQuery
	{
		$userId = $this->userId();

		if ($userId === null || !$this->canViewEntries()) {
			return null;
		}

		$hub = Hub::getInstance();
		$query = Entry::find()
			->drafts()
			->provisionalDrafts(null)
			->draftCreator($userId)
			->savedDraftsOnly()
			->siteId($hub->siteScope->getSiteIds())
			->unique()
			->status(null)
			->andWhere(['not', ['entries.sectionId' => null]])
			->orderBy(['elements.dateUpdated' => SORT_DESC]);

		if (($viewable = $hub->entries->viewableCondition()) !== null) {
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
			default => $draft->draftName ?? Craft::t('hub', 'Draft'),
		};
	}

	/**
	 * Enabled entries of the current user being published or expiring within the next days, soonest first.
	 */
	private function comingUpQuery(): ?EntryQuery
	{
		$filter = $this->ownEntries();

		if ($filter === null) {
			return null;
		}

		$filter->comingUp = true;
		$now = Db::prepareDateForDb(new DateTime());

		return Hub::getInstance()->entries->getQuery($filter)
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
	 * @return array<int,mixed>
	 */
	private function authoredCondition(int $userId): array
	{
		return ['exists', (new Query())
			->from(['hubWorkAuthors' => Table::ENTRIES_AUTHORS])
			->where('[[hubWorkAuthors.entryId]] = [[entries.id]]')
			->andWhere(['hubWorkAuthors.authorId' => $userId])];
	}

	/**
	 * Condition leaving out the queried elements the user snoozed a check for.
	 *
	 * @return array<int,mixed>
	 */
	private function notSnoozedCondition(int $userId, string $checkKey): array
	{
		return ['not exists', (new Query())
			->from(['hubSnoozes' => self::TABLE_SNOOZES])
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

		return Hub::getInstance()->siteScope->pageUrl(self::PATH . "/$tab", $definition->getFilter()?->getListParams() ?? []);
	}

	/**
	 * The checks of what a user maintains, while diagnostics are enabled.
	 *
	 * @return Check[]
	 */
	private function checksOf(MaintainerSource $source): array
	{
		$hub = Hub::getInstance();

		if (!$hub->settings->enableDiagnostics) {
			return [];
		}

		$id = (int)$source->item->id;

		return match ($source->list) {
			ColumnService::LIST_SECTIONS => ($info = $hub->structure->getSection($id)) !== null ? $hub->checks->forSection($info) : [],
			ColumnService::LIST_ENTRY_TYPES => ($info = $hub->structure->getEntryType($id)) !== null ? $hub->checks->forEntryType($info) : [],
			ColumnService::LIST_FIELDS => ($info = $hub->structure->getField($id)) !== null ? $hub->checks->forField($info) : [],
			ColumnService::LIST_VOLUMES => ($info = $hub->volumes->getVolume($id)) !== null ? $hub->checks->forVolume($info) : [],
			default => [],
		};
	}

	private function canViewEntries(): bool
	{
		return Hub::getInstance()->access->canView(Page::Entries);
	}

	private function userId(): ?int
	{
		$id = Craft::$app->getUser()->getId();

		return $id !== null ? (int)$id : null;
	}

	private function cacheTag(int $userId): string
	{
		return "hub:work:$userId";
	}

	private function total(?ElementQuery $query): int
	{
		return $query !== null ? Hub::getInstance()->queries->total(clone $query) : 0;
	}

	/**
	 * @return ElementInterface[]
	 */
	private function items(ElementQuery $query, int $limit = self::ITEM_LIMIT): array
	{
		return $limit > 0 ? Hub::getInstance()->queries->page(clone $query, 0, $limit) : [];
	}
}
