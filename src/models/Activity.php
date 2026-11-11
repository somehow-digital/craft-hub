<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\Inflector;
use craft\models\Site;
use DateTime;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\helpers\Fill;

/**
 * An activity of the activity log. The names of its user and subject are kept as they were, so it reads the same once
 * they're renamed or deleted.
 *
 * @internal
 */
final class Activity extends Model
{
	/** attributes of elements named differently than Craft labels their columns */
	private const ATTRIBUTE_LABELS = [
		'enabled' => 'Status',
		'enabledForSite' => 'Status',
		'authorIds' => 'Authors',
		'typeId' => 'Entry Type',
		'parentId' => 'Parent',
		'postDate' => 'Post Date',
		'expiryDate' => 'Expiry Date',
		'newFolderId' => 'Folder',
		'folderId' => 'Folder',
	];

	public int $id = 0;

	/** the batch's own activity, for activities of a batch, see ActivityService::beginBatch() */
	public ?int $batchId = null;

	public ActivityAction $action = ActivityAction::Created;

	public ActivitySubject $subject = ActivitySubject::Entry;

	public ?int $subjectId = null;

	/** name of the subject as it was */
	public ?string $subjectTitle = null;

	/** site of an element saved, deleted or restored */
	public ?int $siteId = null;

	public ?Site $site = null;

	/** @var array<string,string> names of the fields an update changed, by their handles */
	public array $fieldNames = [];

	/** user who did it; none for things done on the command line, by the system or by guests */
	public ?int $userId = null;

	/** name of the user as it was */
	public ?string $userName = null;

	public ?string $ipAddress = null;

	/**
	 * What else is known about it, like the changed fields of an element, see ActivityService::record().
	 *
	 * @var array<string,mixed>
	 */
	public array $data = [];

	public DateTime $dateCreated;

	public function __construct(array $config = [])
	{
		$this->dateCreated = new DateTime();

		parent::__construct($config);
	}

	/** the user, unless they were deleted since */
	public ?User $user = null;

	/** URL of the subject's Hub page or edit page, unless it was deleted since */
	public ?string $url = null;

	/**
	 * The first activities of a batch, see ActivityFeedService::populate().
	 *
	 * @var Activity[]
	 */
	public array $batched = [];

	/** number of the activities of a batch */
	public int $batchCount = 0;

	/** whether the activities of a batch are those matching the filters it was listed by, rather than all of them */
	public bool $batchFiltered = false;

	/**
	 * Name of the subject as it was; activities of several items, like an export, tell how many there were.
	 */
	public function getTitle(): string
	{
		if ($this->subjectTitle !== null && $this->subjectTitle !== '') {
			return $this->subjectTitle;
		}

		$count = $this->data['count'] ?? null;

		return is_int($count) ? Craft::t('hub', '{count, plural, =1{One item} other{# items}}', ['count' => $count]) : $this->subject->label();
	}

	/**
	 * The user who did it while impersonating this one, if any.
	 */
	public function getImpersonatorName(): ?string
	{
		$name = $this->data['impersonator'] ?? null;

		return is_string($name) ? $name : null;
	}

	/**
	 * Names of the attributes and fields an update changed, attributes first.
	 *
	 * @return list<string>
	 */
	public function getChanges(): array
	{
		$names = [];

		foreach (Fill::list($this->data['attributes'] ?? []) as $attribute) {
			$names[] = Craft::t('app', self::ATTRIBUTE_LABELS[Fill::text($attribute) ?? ''] ?? Inflector::camel2words(Fill::text($attribute) ?? ''));
		}

		foreach (Fill::list($this->data['fields'] ?? []) as $handle) {
			$names[] = $this->fieldNames[Fill::text($handle) ?? ''] ?? (Fill::text($handle) ?? '');
		}

		return array_values(array_unique($names));
	}
}
