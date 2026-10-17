<?php

namespace SomehowDigital\Craft\Hub\services;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use yii\base\Component;

/**
 * The checks of the diagnostics tab of a detail page, as configured (see DiagnosticsService): whether the item fails the
 * checks of its kind, as their filters match it, and, on the pages of sections, entry types and volumes, how many of
 * their entries or assets fail the checks of those, linking to the list of them. Failed checks come first, the most
 * urgent first.
 */
class CheckService extends Component
{
	/** @var array<string,Check[]> by the kind and ID of the item */
	private array $checks = [];

	/**
	 * @return Check[]
	 */
	public function forEntry(Entry $entry): array
	{
		return $this->forItem(CheckKind::Entries, (int)$entry->id);
	}

	/**
	 * @return Check[]
	 */
	public function forFile(Asset $asset): array
	{
		return $this->forItem(CheckKind::Assets, (int)$asset->id);
	}

	/**
	 * @return Check[]
	 */
	public function forUser(User $user): array
	{
		return $this->forItem(CheckKind::Users, (int)$user->id);
	}

	/**
	 * @return Check[]
	 */
	public function forSection(SectionInfo $info): array
	{
		$id = (int)$info->section->id;

		return $this->forItem(CheckKind::Sections, $id, [CheckKind::Entries, ['sectionId' => $id], "hub/structure/sections/$id/entries"]);
	}

	/**
	 * Entry types used by no section or field have no entries, whose checks are left out.
	 *
	 * @return Check[]
	 */
	public function forEntryType(EntryTypeInfo $info): array
	{
		$id = (int)$info->entryType->id;
		$entries = $info->sections !== [] || $info->nestedFields !== [] ? [CheckKind::Entries, ['typeId' => $id], "hub/structure/entry-types/$id/entries"] : null;

		return $this->forItem(CheckKind::EntryTypes, $id, $entries);
	}

	/**
	 * @return Check[]
	 */
	public function forField(FieldInfo $info): array
	{
		return $this->forItem(CheckKind::Fields, (int)$info->field->id);
	}

	/**
	 * @return Check[]
	 */
	public function forVolume(VolumeInfo $info): array
	{
		$id = (int)$info->volume->id;

		return $this->forItem(CheckKind::Volumes, $id, [CheckKind::Assets, ['volumeId' => $id], "hub/structure/volumes/$id/assets"]);
	}

	/**
	 * Computed once per request, as lists and the overview share them.
	 *
	 * @param array{0:CheckKind,1:array<string,int>,2:string}|null $held kind of the elements the item holds, the columns
	 *     narrowing them to its own (see DiagnosticsService::countFailing()) and the path of its tab listing them
	 * @return Check[]
	 */
	private function forItem(CheckKind $kind, int $id, ?array $held = null): array
	{
		return $this->checks["$kind->value:$id"] ??= $this->check($kind, $id, $held);
	}

	/**
	 * @param array{0:CheckKind,1:array<string,int>,2:string}|null $held
	 * @return Check[]
	 */
	private function check(CheckKind $kind, int $id, ?array $held): array
	{
		$diagnostics = Hub::getInstance()->diagnostics;
		$checks = [];

		foreach ($diagnostics->getEnabledChecks($kind) as $definition) {
			$checks[] = $this->create($definition, $definition->name, $definition->description, $definition->passedDescription ?: Craft::t('hub', 'Not flagged by this check.'), !$diagnostics->fails($definition, $id));
		}

		if ($held !== null) {
			[$heldKind, $criteria, $path] = $held;
			$canView = Hub::getInstance()->access->canView($heldKind->page());

			foreach ($diagnostics->getEnabledChecks($heldKind) as $definition) {
				$count = $diagnostics->countFailing($definition, $criteria);
				$check = $this->create($definition, $definition->getListName(), $definition->getListDescription(), Craft::t('hub', 'Nothing is flagged by this check.'), $count === 0);
				$check->count = $count ?: null;
				$checks[] = $check->link($canView ? Hub::getInstance()->siteScope->pageUrl($path, $definition->getFilter()?->getListParams() ?? []) : null, Craft::t('hub', 'Show list'));
			}
		}

		return $diagnostics->sort($checks, $kind, fn (Check $check) => [(string)$check->key, $check->passed, $check->severity]);
	}

	private function create(CheckDefinition $definition, string $heading, string $description, string $passedDescription, bool $passed): Check
	{
		$check = new Check($heading, $description, $passedDescription, $definition->getSeverity(), $definition->getIcon(), $passed);
		$check->key = $definition->getKey();

		return $check;
	}
}
