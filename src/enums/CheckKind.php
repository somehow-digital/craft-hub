<?php

namespace SomehowDigital\Craft\Hub\enums;

use Craft;
use craft\base\conditions\ConditionInterface;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\conditions\EntryTypeCondition;
use SomehowDigital\Craft\Hub\conditions\FieldCondition;
use SomehowDigital\Craft\Hub\conditions\SectionCondition;
use SomehowDigital\Craft\Hub\conditions\VolumeCondition;
use SomehowDigital\Craft\Hub\services\ColumnService;

/**
 * What the checks of a diagnostics tab are of: the elements of a data page, or the items of a structure page. Saved
 * filters are of the lists of these pages too.
 */
enum CheckKind: string
{
	case Entries = 'entries';
	case Assets = 'assets';
	case Users = 'users';
	case EntryTypes = 'entryTypes';
	case Sections = 'sections';
	case Fields = 'fields';
	case Volumes = 'volumes';

	public function label(): string
	{
		return match ($this) {
			self::Entries => Craft::t('hub', 'Entries'),
			self::Assets => Craft::t('hub', 'Assets'),
			self::Users => Craft::t('hub', 'Users'),
			self::EntryTypes => Craft::t('hub', 'Entry Types'),
			self::Sections => Craft::t('hub', 'Sections'),
			self::Fields => Craft::t('hub', 'Fields'),
			self::Volumes => Craft::t('hub', 'Volumes'),
		};
	}

	public function icon(): string
	{
		return match ($this) {
			self::Entries => 'newspaper',
			self::Assets => 'image',
			self::Users => 'user-group',
			self::EntryTypes => 'shapes',
			self::Sections => 'layer-group',
			self::Fields => 'pen-ruler',
			self::Volumes => 'hard-drive',
		};
	}

	/**
	 * Path of the index page, whose diagnostics tab lists the checks.
	 */
	public function path(): string
	{
		return match ($this) {
			self::Entries => 'hub/data/entries',
			self::Assets => 'hub/data/assets',
			self::Users => 'hub/data/users',
			self::EntryTypes => 'hub/structure/entry-types',
			self::Sections => 'hub/structure/sections',
			self::Fields => 'hub/structure/fields',
			self::Volumes => 'hub/structure/volumes',
		};
	}

	/**
	 * Page listing what is checked, viewed by a permission of its own.
	 */
	public function page(): Page
	{
		return match ($this) {
			self::Entries => Page::Entries,
			self::Assets => Page::Assets,
			self::Users => Page::Users,
			self::EntryTypes => Page::EntryTypes,
			self::Sections => Page::Sections,
			self::Fields => Page::Fields,
			self::Volumes => Page::Volumes,
		};
	}

	/**
	 * List of what is checked, see ColumnService::LIST_*.
	 */
	public function list(): string
	{
		return match ($this) {
			self::Entries => ColumnService::LIST_ENTRIES,
			self::Assets => ColumnService::LIST_FILES,
			self::Users => ColumnService::LIST_USERS,
			self::EntryTypes => ColumnService::LIST_ENTRY_TYPES,
			self::Sections => ColumnService::LIST_SECTIONS,
			self::Fields => ColumnService::LIST_FIELDS,
			self::Volumes => ColumnService::LIST_VOLUMES,
		};
	}

	/**
	 * @return class-string<ElementInterface>|null the element type checked, or null for the items of a structure page
	 */
	public function elementType(): ?string
	{
		return match ($this) {
			self::Entries => Entry::class,
			self::Assets => Asset::class,
			self::Users => User::class,
			default => null,
		};
	}

	/**
	 * A condition on what is checked: Craft's condition of the element type, or Hub's own of the items of a structure page.
	 * Only the rules of the config are taken, so the condition is always one of the kind, and rules that aren't one of
	 * its rules are left out.
	 *
	 * @param array<string,mixed> $config the condition's config, as saved
	 */
	public function createCondition(array $config = []): ConditionInterface
	{
		$elementType = $this->elementType();
		$condition = $elementType !== null ? $elementType::createCondition() : Craft::createObject(match ($this) {
			self::EntryTypes => EntryTypeCondition::class,
			self::Sections => SectionCondition::class,
			self::Fields => FieldCondition::class,
			default => VolumeCondition::class,
		});

		$rules = $config['conditionRules'] ?? [];
		$condition->setConditionRules(is_array($rules) ? array_values(array_filter($rules, 'is_array')) : []);

		return $condition;
	}
}
