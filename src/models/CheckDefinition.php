<?php

namespace SomehowDigital\Craft\Hub\models;

use Craft;
use craft\base\Model;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;

/**
 * A check listed on the diagnostics tabs of a kind of pages, as configured on the diagnostics tab of the settings page,
 * flagging the items a filter of the kind offered to everyone matches: one of Hub's default checks, of one of Hub's
 * predefined filters, which can be disabled and given another severity, or a custom one, of any filter of the kind.
 */
class CheckDefinition extends Model
{
	public ?int $id = null;

	public CheckKind $kind = CheckKind::Entries;

	/** handle of the default check, unique within its kind; null for custom checks */
	public ?string $handle = null;

	/** handle of the predefined filter, or UID of the custom filter, whose items the check flags */
	public string $filter = '';

	/** name of the check, shown as its heading; set by Hub for default checks */
	public string $name = '';

	/** what is wrong and what to do about it, shown when the check fails; set by Hub for default checks */
	public string $description = '';

	/** why the check passed, shown when it passes; custom checks fall back to a generic text */
	public string $passedDescription = '';

	/** name of a Craft icon */
	public ?string $icon = null;

	/** severity of a failed custom check, or the one a default check is given instead of its own; null keeps its own */
	public ?Severity $severity = null;

	/** severity a default check fails with unless given another one */
	public Severity $defaultSeverity = Severity::Notice;

	public bool $enabled = true;

	public int $sortOrder = 0;

	public ?string $uid = null;

	public function isDefault(): bool
	{
		return $this->handle !== null;
	}

	/**
	 * Key of the check on the diagnostics tabs: the kind and handle of a default check, or the kind and UID of a custom one.
	 */
	public function getKey(): string
	{
		return $this->kind->value . '.' . ($this->handle ?? $this->uid);
	}

	/**
	 * The filter whose items the check flags; null if it no longer exists.
	 */
	public function getFilter(): ?SavedFilter
	{
		return $this->filter !== '' ? Hub::getInstance()->filters->getFilter($this->kind, $this->filter) : null;
	}

	/**
	 * Name of the check where it counts the items it flags, like on the index pages: that of the filter of a default
	 * check, as its own name is that of the one item checked.
	 */
	public function getListName(): string
	{
		return $this->isDefault() ? $this->getFilter()?->getName() ?? $this->name : $this->name;
	}

	/**
	 * Description of the check where it counts the items it flags, see {@see getListName()}.
	 */
	public function getListDescription(): string
	{
		return $this->isDefault() ? $this->getFilter()->description ?? $this->description : $this->description;
	}

	public function getIcon(): string
	{
		return $this->icon ?: $this->getFilter()?->getIcon() ?? 'list-check';
	}

	public function getSeverity(): Severity
	{
		return $this->severity ?? $this->defaultSeverity;
	}

	/**
	 * @return array<int,mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['name', 'description', 'passedDescription'], 'trim'],
			[['name', 'description'], 'required', 'when' => fn () => !$this->isDefault()],
			[['name', 'icon'], 'string', 'max' => 255],
			[['icon'], 'match', 'pattern' => Label::ICON_PATTERN],
			[['filter'], 'validateFilter', 'skipOnEmpty' => false],
		];
	}

	/**
	 * A custom check flags the items of a filter of its kind offered to everyone.
	 */
	public function validateFilter(): void
	{
		if (!$this->isDefault() && $this->getFilter() === null) {
			$this->addError('filter', Craft::t('hub', 'Choose the filter whose items are flagged.'));
		}
	}
}
