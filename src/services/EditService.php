<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use SomehowDigital\Craft\Hub\Hub;
use yii\base\Component;

/**
 * Keeps when entries were last edited by a person, which is what tells whether they are stale.
 *
 * Craft's `dateUpdated` changes with every save, also those made by scripts, imports, propagation to other sites or
 * changing an entry's status. So Hub records the saves of signed-in users on the web, outside of the queue, that change
 * an entry's content: its fields or attributes other than its status. Entries without a record fall back to their
 * `dateUpdated`; existing entries are given one from their revisions and Craft's change tracking, see {@see backfill()}.
 */
class EditService extends Component
{
	/** the last edit of each entry by a person, with the user who made it */
	public const TABLE = '{{%hub_edits}}';

	/** attributes Craft sets as elements are saved, and the status, none of which are edits of the content */
	public const IGNORED_ATTRIBUTES = [
		'id', 'uid', 'canonicalId', 'draftId', 'revisionId', 'isProvisionalDraft', 'siteSettingsId', 'fieldLayoutId',
		'structureId', 'root', 'lft', 'rgt', 'level', 'uri', 'dateCreated', 'dateUpdated', 'dateLastMerged',
		'enabled', 'enabledForSite', 'archived',
	];

	/**
	 * Records the save of an entry as an edit, if a person made it and it changed the entry's content.
	 */
	public function record(Entry $entry, bool $isNew): void
	{
		if (
			$entry->getIsDraft()
			|| $entry->getIsRevision()
			|| $entry->propagating
			|| $entry->resaving
			|| !$this->isPerson()
			|| (!$isNew && !$this->isEdit($entry))
		) {
			return;
		}

		Craft::$app->getDb()->createCommand()->upsert(self::TABLE, [
			'elementId' => $entry->id,
			'userId' => Craft::$app->getUser()->getId(),
			'dateEdited' => Db::prepareDateForDb(new DateTime()),
		])->execute();
	}

	/**
	 * When each of the entries was last edited by a person, falling back to when they were last updated.
	 *
	 * @param Entry[] $entries
	 * @return array<int,DateTime|null> keyed by entry ID
	 */
	public function getLastEdited(array $entries): array
	{
		$dates = $this->getRecorded(array_map(fn (Entry $entry) => $entry->id, $entries));
		$lastEdited = [];

		foreach ($entries as $entry) {
			$lastEdited[$entry->id] = $dates[$entry->id]['date'] ?? $entry->dateUpdated;
		}

		return $lastEdited;
	}

	/**
	 * When the entry was last edited by a person, falling back to when it was last updated, and by whom, if known.
	 *
	 * @return array{date:DateTime|null,user:User|null}
	 */
	public function getLastEdit(Entry $entry): array
	{
		$recorded = $this->getRecorded([$entry->id])[$entry->id] ?? null;

		return [
			'date' => $recorded['date'] ?? $entry->dateUpdated,
			'user' => isset($recorded['userId']) ? User::find()->id($recorded['userId'])->status(null)->one() : null,
		];
	}

	/**
	 * SQL of when the queried entry was last edited by a person, falling back to when it was last updated, for
	 * conditions and sorting.
	 */
	public function lastEditedSql(): string
	{
		$edited = (new Query())
			->select(['hubEdits.dateEdited'])
			->from(['hubEdits' => self::TABLE])
			->where('[[hubEdits.elementId]] = [[elements.id]]');

		return sprintf('COALESCE((%s), [[elements.dateUpdated]])', $edited->createCommand()->getRawSql());
	}

	/**
	 * Condition matching the queried entries by when they were last edited, like the `dateUpdated` param of element queries.
	 *
	 * @param mixed $param a date param, like `< 2024-01-01` or `['and', '>= …', '< …']`
	 * @return array<int|string,mixed>|null
	 */
	public function lastEditedCondition(mixed $param): ?array
	{
		return Db::parseDateParam($this->lastEditedSql(), $param);
	}

	/**
	 * Condition matching the queried entries last edited by one of the users.
	 *
	 * @param int[] $userIds
	 * @return array<int,mixed>
	 */
	public function editedByCondition(array $userIds): array
	{
		return ['in', 'elements.id', (new Query())->select(['hubEditors.elementId'])->from(['hubEditors' => self::TABLE])->where(['hubEditors.userId' => $userIds])];
	}

	/**
	 * Users who last edited entries, with how many entries they last edited, most entries first, optionally of a
	 * section, entry type or field only.
	 *
	 * @return array<int,array{user:User,count:int}> keyed by user ID
	 */
	public function getEditors(?int $sectionId = null, ?int $typeId = null, ?int $fieldId = null): array
	{
		$counts = (new Query())
			->select(['hubEdits.userId', 'count' => 'COUNT(*)'])
			->from(['hubEdits' => self::TABLE])
			->innerJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[hubEdits.elementId]]')
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
			->where(['elements.dateDeleted' => null])
			->andWhere(['not', ['hubEdits.userId' => null]])
			->andFilterWhere(['entries.sectionId' => $sectionId, 'entries.typeId' => $typeId, 'entries.fieldId' => $fieldId])
			->groupBy(['hubEdits.userId'])
			->orderBy(['count' => SORT_DESC])
			->pairs();

		if ($counts === []) {
			return [];
		}

		$users = Hub::getInstance()->users->getUsers(array_map('intval', array_keys($counts)));
		$editors = [];

		foreach ($counts as $id => $count) {
			if (isset($users[$id])) {
				$editors[(int)$id] = ['user' => $users[$id], 'count' => (int)$count];
			}
		}

		return $editors;
	}

	/**
	 * Gives each existing entry a record of its last edit by a person, from what Craft knows of it: the newest of its
	 * revisions and of its tracked changes, made by a user and other than its status. Entries without either are
	 * recorded with their `dateUpdated`, as Craft deletes the tracked changes of entries without drafts. Entries recorded
	 * already are kept.
	 */
	public function backfill(): void
	{
		$edits = [];
		$add = function (array $rows) use (&$edits) {
			foreach ($rows as $row) {
				$id = (int)$row['elementId'];

				if (!isset($edits[$id]) || $row['date'] > $edits[$id]['dateEdited']) {
					$edits[$id] = ['elementId' => $id, 'userId' => $row['userId'] !== null ? (int)$row['userId'] : null, 'dateEdited' => $row['date']];
				}
			}
		};

		$add((new Query())
			->select(['elementId' => 'revisions.canonicalId', 'userId' => 'revisions.creatorId', 'date' => 'MAX([[elements.dateCreated]])'])
			->from(['revisions' => Table::REVISIONS])
			->innerJoin(['elements' => Table::ELEMENTS], '[[elements.revisionId]] = [[revisions.id]]')
			->where(['not', ['revisions.creatorId' => null]])
			->groupBy(['revisions.canonicalId', 'revisions.creatorId'])
			->all());

		$add((new Query())
			->select(['elementId', 'userId', 'date' => 'MAX([[dateUpdated]])'])
			->from([Table::CHANGEDATTRIBUTES])
			->where(['not', ['userId' => null]])
			->andWhere(['not', ['attribute' => self::IGNORED_ATTRIBUTES]])
			->groupBy(['elementId', 'userId'])
			->all());

		$add((new Query())
			->select(['elementId', 'userId', 'date' => 'MAX([[dateUpdated]])'])
			->from([Table::CHANGEDFIELDS])
			->where(['not', ['userId' => null]])
			->groupBy(['elementId', 'userId'])
			->all());

		$entries = (new Query())
			->select(['elementId' => 'elements.id', 'date' => 'elements.dateUpdated'])
			->from(['elements' => Table::ELEMENTS])
			->innerJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[elements.id]]')
			->where(['elements.draftId' => null, 'elements.revisionId' => null])
			->andWhere(['not exists', (new Query())->from(['hubEdits' => self::TABLE])->where('[[hubEdits.elementId]] = [[elements.id]]')])
			->orderBy(['elements.id' => SORT_ASC])
			->all();

		$rows = array_map(fn (array $row) => array_values($edits[(int)$row['elementId']] ?? [
			'elementId' => (int)$row['elementId'],
			'userId' => null,
			'dateEdited' => $row['date'],
		]), $entries);

		foreach (array_chunk($rows, 500) as $chunk) {
			Craft::$app->getDb()->createCommand()->batchInsert(self::TABLE, ['elementId', 'userId', 'dateEdited'], $chunk)->execute();
		}
	}

	/**
	 * @param int[] $entryIds
	 * @return array<int,array{date:DateTime,userId:int|null}> keyed by entry ID, for entries with a record
	 */
	private function getRecorded(array $entryIds): array
	{
		if ($entryIds === []) {
			return [];
		}

		$rows = (new Query())
			->select(['elementId', 'userId', 'dateEdited'])
			->from([self::TABLE])
			->where(['elementId' => $entryIds])
			->all();

		$recorded = [];

		foreach ($rows as $row) {
			$date = DateTimeHelper::toDateTime($row['dateEdited']);

			if ($date !== false) {
				$recorded[(int)$row['elementId']] = ['date' => $date, 'userId' => $row['userId'] !== null ? (int)$row['userId'] : null];
			}
		}

		return $recorded;
	}

	/**
	 * Whether a person is saving: a signed-in user on the web, other than while the queue runs, which runs imports and
	 * scripts as whoever happens to trigger it.
	 */
	private function isPerson(): bool
	{
		$request = Craft::$app->getRequest();

		if ($request->getIsConsoleRequest() || ($request->getIsActionRequest() && $request->getActionSegments() === ['queue', 'run'])) {
			return false;
		}

		return Craft::$app->getUser()->getId() !== null;
	}

	/**
	 * Whether the save changes the entry's fields or any attribute other than its status.
	 */
	private function isEdit(Entry $entry): bool
	{
		return $entry->getDirtyFields() !== [] || array_diff($entry->getDirtyAttributes(), self::IGNORED_ATTRIBUTES) !== [];
	}
}
