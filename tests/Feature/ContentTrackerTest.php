<?php

declare(strict_types=1);

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\ElementEvent;
use craft\events\MultiElementActionEvent;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\handlers\ContentTracker;
use yii\base\Event;
use yii\caching\TagDependency;

beforeEach(function (): void {
	$this->request();
	$this->db()->tables = [];
	Craft::$app->getCache()?->flush();
	mocked(Craft::$app->getUser())->shouldReceive('getId')->andReturn(7)->byDefault();
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 7, 'username' => 'jane']))->byDefault();

	// Only the tracker under test handles the events, not the activity log.
	Event::off(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT);
	Event::off(Elements::class, Elements::EVENT_AFTER_PROPAGATE_ELEMENT);
	Event::off(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT);
	$this->handler('contentTracker', ContentTracker::class)->register();

	$this->saved = function (object $element, bool $isNew = false): void {
		Event::trigger(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, new ElementEvent(['element' => $element, 'isNew' => $isNew]));
	};
});

/**
 * Elements whose references were looked up, as the IDs of the SELECT.
 *
 * @return list<string>
 */
function indexed(): array
{
	return currentTest()->db()->queriesMatching('FROM `elements_sites`');
}

/**
 * The edits recorded.
 *
 * @return list<string>
 */
function edits(): array
{
	return currentTest()->db()->queriesMatching(sqlInsertInto(Tables::EDITS));
}

/**
 * Whether the work counts of the user were forgotten since this was called last.
 */
function forgotten(): bool
{
	$cache = Craft::$app->getCache() ?? throw new LogicException('No cache.');
	$forgotten = $cache->get('probe') === false;
	$cache->set('probe', 1, 0, new TagDependency(['tags' => 'hub:work:7']));

	return $forgotten;
}

it('indexes the reference tags of elements saved and records the edits of entries', function (): void {
	($this->saved)(new Entry(['id' => 5]), true);
	($this->saved)(new Asset(['id' => 6]));

	expect(indexed())->toHaveCount(2)
		->and(indexed()[0])->toContain('`elements_sites`.`elementId`=5')
		->and(indexed()[1])->toContain('`elements_sites`.`elementId`=6')
		->and(edits())->toHaveCount(1);
});

it('records entries saved for the first time as new', function (): void {
	($this->saved)(new Entry(['id' => 5, 'firstSave' => true]));

	expect(edits())->toHaveCount(1);
});

it('leaves out drafts, revisions and the copies saved while propagating', function (array $config): void {
	/** @var array<string,mixed> $config */
	($this->saved)(new Entry(['id' => 5, ...$config]));

	expect(indexed())->toBe([])
		->and(edits())->toBe([]);
})->with([
	'draft' => [['draftId' => 2]],
	'revision' => [['revisionId' => 2]],
	'propagating' => [['propagating' => true]],
]);

it('indexes elements propagated to other sites on their own', function (): void {
	Event::trigger(Elements::class, Elements::EVENT_AFTER_PROPAGATE_ELEMENT, new MultiElementActionEvent(['element' => new Entry(['id' => 5]), 'position' => 1]));
	Event::trigger(Elements::class, Elements::EVENT_AFTER_PROPAGATE_ELEMENT, new MultiElementActionEvent(['element' => new Entry(['id' => 6, 'draftId' => 2]), 'position' => 2]));

	expect(indexed())->toHaveCount(1)
		->and(indexed()[0])->toContain('`elements_sites`.`elementId`=5')
		->and(edits())->toBe([]);
});

it('forgets the work counts of the user changing entries and assets, drafts included, other than their unsaved changes', function (): void {
	forgotten();
	$changes = [
		'entry' => [fn() => ($this->saved)(new Entry(['id' => 5])), true],
		'asset' => [fn() => ($this->saved)(new Asset(['id' => 6])), true],
		'draft' => [fn() => ($this->saved)(new Entry(['id' => 7, 'draftId' => 2])), true],
		'unsaved changes' => [fn() => ($this->saved)(new Entry(['id' => 8, 'draftId' => 3, 'isProvisionalDraft' => true])), false],
		'revision' => [fn() => ($this->saved)(new Entry(['id' => 9, 'revisionId' => 2])), false],
		'propagation' => [fn() => ($this->saved)(new Entry(['id' => 10, 'propagating' => true])), false],
		'user' => [fn() => ($this->saved)(new User(['id' => 11])), false],
		'deletion' => [fn() => Event::trigger(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, new ElementEvent(['element' => new Entry(['id' => 12])])), true],
	];

	foreach ($changes as $name => [$change, $expected]) {
		// Forgotten once until counted again, so the work service is made anew for each.
		$this->resetServices();
		Event::off(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT);
		Event::off(Elements::class, Elements::EVENT_AFTER_PROPAGATE_ELEMENT);
		Event::off(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT);
		$this->handler('contentTracker', ContentTracker::class)->register();
		forgotten();
		$change();

		expect(forgotten())->toBe($expected, $name);
	}
});
