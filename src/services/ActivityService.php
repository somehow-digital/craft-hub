<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\db\Connection;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\services\Users;
use craft\web\Request as WebRequest;
use craft\web\Session as WebSession;
use craft\web\User as WebUser;
use DateTime;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\models\Settings;
use Throwable;
use yii\base\Component;

/**
 * Records the activity log: what users did, as Craft's own events tell it (see ActivityTracker), along with what they did
 * with Hub itself. Activities are kept with the names of their users and subjects as they were, so they outlive them,
 * until they expire (see Settings::$activityRetentionDays). The log is listed by the ActivityFeedService.
 *
 * Activities done together, like those of a bulk action or of the project config applied, are recorded as a batch (see
 * beginBatch()): the log lists the batch's own activity, with its activities within it.
 *
 * @internal
 */
final class ActivityService extends Component
{
	private ?bool $tableExists = null;

	/**
	 * The batch being recorded, with the ID of its activity once it's recorded, and how many batches were begun within it.
	 *
	 * @var array{action:ActivityAction,subject:ActivitySubject,title:string|null,data:array<string,mixed>,id:int|null,depth:int}|null
	 */
	private ?array $batch = null;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Connection $db,
		private readonly ?WebRequest $request,
		private readonly Users $craftUsers,
		private readonly ?WebUser $user = null,
		private readonly ?WebSession $session = null,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * Whether activities are recorded and the activity log is shown (see Settings::$enableActivity).
	 */
	public function isEnabled(): bool
	{
		return $this->settings->enableActivity;
	}

	/**
	 * Whether the current user may see the activity log.
	 */
	public function canView(): bool
	{
		return $this->settings->enableActivity && $this->user?->checkPermission(Permission::ViewActivity->value) === true;
	}

	/**
	 * Records an activity of the current user, or of the given one, while the activity log is enabled. Recording never
	 * fails what was done, so errors are only logged.
	 *
	 * @param array<string,mixed> $data what else is known about it, like the changed `fields` and `attributes` of an element
	 * @param User|null $user who did it, the current user by default
	 * @param bool $anonymous whether no user did it, like a failed sign-in
	 */
	public function record(
		ActivityAction $action,
		ActivitySubject $subject,
		?int $subjectId = null,
		?string $subjectTitle = null,
		array $data = [],
		?int $siteId = null,
		?User $user = null,
		bool $anonymous = false,
	): void {
		try {
			if (!$this->settings->enableActivity || !$this->tableExists()) {
				return;
			}

			$columns = $this->columns($action, $subject, $subjectId, $subjectTitle, $data, $siteId, $anonymous ? null : ($user ?? $this->currentUser()));

			if ($this->batch !== null) {
				$this->batch['id'] ??= $this->insert($this->columns($this->batch['action'], $this->batch['subject'], null, $this->batch['title'], $this->batch['data'], null, $this->currentUser()));
				$columns['batchId'] = $this->batch['id'];
			}

			$this->insert($columns);
		} catch (Throwable $throwable) {
			Craft::warning("Couldn’t record the {$action->value} activity of a {$subject->value}: {$throwable->getMessage()}", __METHOD__);
		}
	}

	/**
	 * Records the activities until endBatch() as a batch, like those of a bulk action, under an activity of its own, by
	 * the current user. That one's only recorded along with the first activity of the batch, so batches doing nothing
	 * leave nothing behind. Batches begun within a batch are part of it.
	 *
	 * @param string|null $title what was done, like the name of the action
	 * @param array<string,mixed> $data
	 */
	public function beginBatch(ActivityAction $action, ActivitySubject $subject, ?string $title = null, array $data = []): void
	{
		if ($this->batch !== null) {
			$this->batch['depth']++;

			return;
		}

		$this->batch = ['action' => $action, 'subject' => $subject, 'title' => $title, 'data' => $data, 'id' => null, 'depth' => 1];
	}

	/**
	 * Ends the batch begun last.
	 */
	public function endBatch(): void
	{
		if ($this->batch !== null && --$this->batch['depth'] === 0) {
			$this->batch = null;
		}
	}

	/**
	 * Deletes the activities older than they're kept for (see Settings::$activityRetentionDays), along with Craft's garbage.
	 */
	public function deleteExpired(): void
	{
		$days = $this->settings->activityRetentionDays;

		if ($days < 1 || !$this->tableExists()) {
			return;
		}

		$this->db->createCommand()
			->delete(Tables::ACTIVITY, ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-{$days} days"))])
			->execute();
	}

	/**
	 * Names of the permissions granted to a user directly, rather than by their groups.
	 *
	 * @return list<string>
	 */
	public function getUserPermissions(int $userId): array
	{
		return Fill::strings((new Query())
			->select(['permissions.name'])
			->from(['permissions' => Table::USERPERMISSIONS])
			->innerJoin(['userPermissions' => Table::USERPERMISSIONS_USERS], '[[userPermissions.permissionId]] = [[permissions.id]]')
			->where(['userPermissions.userId' => $userId])
			->column());
	}

	private function tableExists(): bool
	{
		return $this->tableExists ??= $this->db->tableExists(Tables::ACTIVITY);
	}

	/**
	 * Columns of an activity, by the user given; the user impersonating them is kept along, unless they did it themselves.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function columns(ActivityAction $action, ActivitySubject $subject, ?int $subjectId, ?string $subjectTitle, array $data, ?int $siteId, ?User $user): array
	{
		$request = $this->request;
		$web = $request instanceof WebRequest && !$request->getIsConsoleRequest();

		if ($web && ($impersonator = $this->impersonator()) instanceof User && $impersonator->id !== $user?->id) {
			$data['impersonator'] = $impersonator->getName();
		}

		return [
			'action' => $action->value,
			'subject' => $subject->value,
			'subjectId' => $subjectId,
			'subjectTitle' => $this->truncate($subjectTitle, 255),
			'siteId' => $siteId,
			'userId' => $user?->id,
			'userName' => $this->truncate($user?->getName(), 255),
			'ipAddress' => $web ? $this->truncate($request->getUserIP(), 45) : null,
			'data' => $data !== [] ? Json::encode($data) : null,
		];
	}

	/**
	 * Inserts an activity, returning its ID.
	 *
	 * @param array<string,mixed> $columns
	 */
	private function insert(array $columns): int
	{
		$db = $this->db;
		$db->createCommand()->insert(Tables::ACTIVITY, $columns)->execute();

		return (int) $db->getLastInsertID(Tables::ACTIVITY);
	}

	private function currentUser(): ?User
	{
		$user = $this->user?->getIdentity();

		return $user instanceof User ? $user : null;
	}

	/**
	 * The user impersonating the current one, if any; the session isn't started for it, as guests on the site have none.
	 */
	private function impersonator(): ?User
	{
		if ($this->session?->getIsActive() !== true) {
			return null;
		}

		$id = $this->user?->getImpersonatorId();

		return $id !== null ? $this->craftUsers->getUserById($id) : null;
	}

	private function truncate(?string $value, int $length): ?string
	{
		return $value !== null && $value !== '' ? mb_substr($value, 0, $length) : null;
	}
}
