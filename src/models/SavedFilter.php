<?php

namespace SomehowDigital\Craft\Hub\models;

use Craft;
use craft\base\conditions\BaseCondition;
use craft\base\conditions\ConditionInterface;
use craft\base\Model;
use SomehowDigital\Craft\Hub\enums\CheckKind;

/**
 * A filter of a list, saved by its name: one of Hub's predefined filters, which can be disabled, reordered and renamed on
 * the filters tab of the settings page, some given another threshold there too, a custom one created there, whose
 * condition the items match, offered to everyone who may view the list, or a user's own, which keeps the filters of the
 * list the user saved it with.
 */
class SavedFilter extends Model
{
	public ?int $id = null;

	public CheckKind $kind = CheckKind::Entries;

	/** handle of the predefined filter, unique within its kind; null for the others */
	public ?string $handle = null;

	/** ID of the user whose own filter it is; null for those offered to everyone */
	public ?int $userId = null;

	/** name of the filter; predefined filters given none keep Hub's own */
	public string $name = '';

	/** Hub's own name of a predefined filter */
	public string $defaultName = '';

	/** what the filter lists, shown as its tooltip; set by Hub for predefined filters */
	public string $description = '';

	/** name of a Craft icon */
	public ?string $icon = null;

	/** @var array<string,string> query params of the list's filters, as they appear in its URL */
	public array $params = [];

	/** @var array<string,mixed>|null config of the condition the items of a custom filter match */
	public ?array $condition = null;

	/** threshold a predefined filter is given instead of Hub's own, like the months after which entries are stale */
	public ?int $threshold = null;

	/** @var array{label:string,instructions:string,unit:string,default:int}|null field of a predefined filter's threshold, set by Hub; null for filters without one */
	public ?array $thresholdField = null;

	public bool $enabled = true;

	public int $sortOrder = 0;

	public ?string $uid = null;

	public function isPredefined(): bool
	{
		return $this->handle !== null;
	}

	public function isOwn(): bool
	{
		return $this->userId !== null;
	}

	/**
	 * Whether it's a custom filter, created on the settings page with a condition of its own.
	 */
	public function isCustom(): bool
	{
		return !$this->isPredefined() && !$this->isOwn();
	}

	/**
	 * Key of a filter offered to everyone: the kind and handle of a predefined filter, or the kind and UID of a custom one.
	 */
	public function getKey(): string
	{
		return $this->kind->value . '.' . ($this->handle ?? $this->uid);
	}

	public function getName(): string
	{
		return $this->name !== '' ? $this->name : $this->defaultName;
	}

	/**
	 * Threshold of a predefined filter, as configured or Hub's own; null for filters without one.
	 */
	public function getThreshold(): ?int
	{
		return $this->thresholdField !== null ? $this->threshold ?? $this->thresholdField['default'] : null;
	}

	public function getIcon(): string
	{
		return $this->icon ?: 'filter';
	}

	/**
	 * Query params of the list showing the items the filter matches: those of a custom filter apply its condition by its
	 * UID, see ListFilterService::createListFilter().
	 *
	 * @return array<string,string>
	 */
	public function getListParams(): array
	{
		return $this->isCustom() ? [...$this->params, 'filter' => (string)$this->uid] : $this->params;
	}

	public function getCondition(): ConditionInterface
	{
		$condition = $this->kind->createCondition($this->condition ?? []);

		// Rendered as a builder within the filter's form, which posts its rules by the name.
		if ($condition instanceof BaseCondition) {
			$condition->name = 'condition';
			$condition->id = 'condition';
			$condition->mainTag = 'div';
		}

		return $condition;
	}

	/**
	 * @return array<int,mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['name', 'description'], 'trim'],
			[['name'], 'required', 'when' => fn () => !$this->isPredefined()],
			[['name', 'icon'], 'string', 'max' => 255],
			[['icon'], 'match', 'pattern' => Label::ICON_PATTERN],
			[['threshold'], 'integer', 'min' => 1],
			[['condition'], 'validateCondition', 'skipOnEmpty' => false],
			[['params'], 'validateParams', 'skipOnEmpty' => false],
		];
	}

	/**
	 * A custom filter without a rule would match every item, so it needs at least one.
	 */
	public function validateCondition(): void
	{
		if ($this->isCustom() && ($this->condition['conditionRules'] ?? []) === []) {
			$this->addError('condition', Craft::t('hub', 'Add at least one rule the items are matched by.'));
		}
	}

	/**
	 * A user's own filter without filters would list everything, so it needs at least one.
	 */
	public function validateParams(): void
	{
		if ($this->isOwn() && $this->params === []) {
			$this->addError('params', Craft::t('hub', 'Set at least one filter to save.'));
		}
	}
}
