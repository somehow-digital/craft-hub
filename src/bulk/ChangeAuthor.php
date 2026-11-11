<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\Section;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;
use SomehowDigital\Craft\Hub\services\EntryService;

/**
 * Changes the authors of entries as far as the user may (see EntryService::canChangeAuthors()), to the user picked
 * (`authorId`), replacing the authors or being added to them (`authors`, see BulkActions::AUTHORS_*). They must be able
 * to view the entries of the section, as Craft requires; entries with those authors already are left as they are.
 *
 * @internal
 */
final class ChangeAuthor extends BaseBulkAction
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly EntryService $entries,
		private readonly Elements $craftElements,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return $this->picked(
			Craft::t('hub', 'Change author'),
			'user-pen',
			Craft::t('hub', 'Change the author of “{name}”?'),
			Craft::t('hub', 'Change the author of {count} selected entries?'),
			Craft::t('hub', 'Checking whose authors can be changed…'),
		);
	}

	public function isAvailable(): bool
	{
		return $this->access->canAct(Page::Entries, Permission::ChangeAuthors->value);
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$author = $this->activeUser($this->intParam($params, 'authorId'));
		$add = ($params['authors'] ?? null) === BulkActions::AUTHORS_ADD;

		return $author instanceof User ? $this->succeeded($this->entries($ids), fn(Entry $entry): bool => $this->change($entry, $author, $add)) : [];
	}

	public function preview(array $ids, int $page): BulkPreview
	{
		$entries = array_values(array_filter($this->entries($ids), $this->entries->canChangeAuthors(...)));
		$authors = $this->entries->getAuthorCandidates($entries);

		return new BulkPreview('author-preview', $authors !== [] ? count($entries) : 0, ['authors' => $authors]);
	}

	/**
	 * @param int[] $ids
	 * @return list<Entry>
	 */
	private function entries(array $ids): array
	{
		return $this->bulk->getElementsOf($this->list, $ids, Entry::class);
	}

	private function change(Entry $entry, User $author, bool $add): bool
	{
		$section = $entry->getSection();

		if (!$section instanceof Section || !$this->entries->canChangeAuthors($entry) || !$author->can('viewEntries:' . ($section->uid ?? ''))) {
			return false;
		}

		$authorIds = $entry->getAuthorIds();
		$changed = $add ? array_values(array_unique([...$authorIds, (int) $author->id])) : [(int) $author->id];

		if ($changed === $authorIds) {
			return true;
		}

		$entry->setAuthorIds($changed);

		return $this->craftElements->saveElement($entry);
	}
}
