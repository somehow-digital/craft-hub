<?php

namespace SomehowDigital\Craft\Hub\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\queue\BaseBatchedJob;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Indexes the reference tags in the content of all elements, like after installing Hub, or after content was changed
 * without saving elements, like by Craft's find and replace utility.
 *
 * Elements are indexed a batch at a time, after their IDs are collected.
 */
class IndexReferences extends BaseBatchedJob
{
	public int $batchSize = 500;

	/** @var int[] IDs of the elements of the current batch */
	private array $elementIds = [];

	protected function loadData(): Batchable
	{
		return new QueryBatcher(Hub::getInstance()->references->indexableQuery());
	}

	protected function beforeBatch(): void
	{
		$this->elementIds = [];
	}

	protected function processItem(mixed $item): void
	{
		$this->elementIds[] = (int)$item['id'];
	}

	protected function afterBatch(): void
	{
		Hub::getInstance()->references->index($this->elementIds);
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('hub', 'Indexing references in content');
	}
}
