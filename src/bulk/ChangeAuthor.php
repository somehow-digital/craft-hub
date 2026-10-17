<?php

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\BulkService;

/**
 * Changes the authors of entries as far as the user may (see EntryService::canChangeAuthors()), to the user picked (`authorId`),
 * replacing the authors or being added to them (`authors`, see BulkService::AUTHORS_*). They must be able to view the entries of
 * the section, as Craft requires; entries with those authors already are left as they are.
 */
class ChangeAuthor extends BulkAction
{
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
		return $this->hub()->access->canAct(Page::Entries, Hub::PERMISSION_CHANGE_AUTHORS);
	}

	public function apply(array $ids, array $params): array
	{
		$authorId = $this->intParam($params, 'authorId');
		$author = $authorId > 0 ? User::find()->id($authorId)->status(User::STATUS_ACTIVE)->one() : null;
		$add = ($params['authors'] ?? null) === BulkService::AUTHORS_ADD;

		return $author === null ? [] : $this->succeeded($this->entries($ids), fn (Entry $entry) => $this->change($entry, $author, $add));
	}

	public function preview(array $ids, int $page): ?BulkPreview
	{
		$entries = array_values(array_filter($this->entries($ids), fn (Entry $entry) => $this->hub()->entries->canChangeAuthors($entry)));
		$authors = $this->hub()->entries->getAuthorCandidates($entries);

		return new BulkPreview('author-preview', $authors !== [] ? count($entries) : 0, ['authors' => $authors]);
	}

	/**
	 * @param int[] $ids
	 * @return Entry[]
	 */
	private function entries(array $ids): array
	{
		return $this->hub()->bulk->getElementsOf($this->list, $ids, Entry::class);
	}

	private function change(Entry $entry, User $author, bool $add): bool
	{
		$section = $entry->getSection();

		if ($section === null || !$this->hub()->entries->canChangeAuthors($entry) || !$author->can("viewEntries:$section->uid")) {
			return false;
		}

		$authorIds = $entry->getAuthorIds();
		$changed = $add ? array_values(array_unique([...$authorIds, (int)$author->id])) : [(int)$author->id];

		if ($changed === $authorIds) {
			return true;
		}

		$entry->setAuthorIds($changed);

		return Craft::$app->getElements()->saveElement($entry);
	}
}
