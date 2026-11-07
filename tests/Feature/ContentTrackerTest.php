<?php

declare(strict_types=1);

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\ElementEvent;
use craft\events\MultiElementActionEvent;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\handlers\ContentTracker;
use SomehowDigital\Craft\Hub\services\EditService;
use SomehowDigital\Craft\Hub\services\ReferenceService;
use SomehowDigital\Craft\Hub\services\WorkService;
use yii\base\Event;

beforeEach(function (): void {
	$this->references = $this->mockComponent('hub.references', ReferenceService::class);
	$this->edits = $this->mockComponent('hub.edits', EditService::class);

	// Only the tracker under test handles the events, not the activity log.
	Event::off(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT);
	Event::off(Elements::class, Elements::EVENT_AFTER_PROPAGATE_ELEMENT);
	Event::off(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT);
	(new ContentTracker())->register();

	$this->saved = fn(object $element, bool $isNew = false) => Event::trigger(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, new ElementEvent(['element' => $element, 'isNew' => $isNew]));
});

it('indexes the reference tags of elements saved and records the edits of entries', function (): void {
	$entry = new Entry(['id' => 5]);
	$this->references->shouldReceive('index')->once()->with([5]);
	$this->references->shouldReceive('index')->once()->with([6]);
	$this->edits->shouldReceive('record')->once()->with(Mockery::on(fn($element): bool => $element === $entry), true);

	($this->saved)($entry, true);
	($this->saved)(new Asset(['id' => 6]));
});

it('records entries saved for the first time as new', function (): void {
	$entry = new Entry(['id' => 5, 'firstSave' => true]);
	$this->references->shouldReceive('index');
	$this->edits->shouldReceive('record')->once()->with(Mockery::on(fn($element): bool => $element === $entry), true);

	($this->saved)($entry);
});

it('leaves out drafts, revisions and the copies saved while propagating', function (array $config): void {
	$this->references->shouldNotReceive('index');
	$this->edits->shouldNotReceive('record');

	($this->saved)(new Entry(['id' => 5, ...$config]));
})->with([
	'draft' => [['draftId' => 2]],
	'revision' => [['revisionId' => 2]],
	'propagating' => [['propagating' => true]],
]);

it('indexes elements propagated to other sites on their own', function (): void {
	$this->references->shouldReceive('index')->once()->with([5]);
	$this->edits->shouldNotReceive('record');

	Event::trigger(Elements::class, Elements::EVENT_AFTER_PROPAGATE_ELEMENT, new MultiElementActionEvent(['element' => new Entry(['id' => 5]), 'position' => 1]));
	Event::trigger(Elements::class, Elements::EVENT_AFTER_PROPAGATE_ELEMENT, new MultiElementActionEvent(['element' => new Entry(['id' => 6, 'draftId' => 2]), 'position' => 2]));
});

it('forgets the work counts of the user changing entries and assets, drafts included, other than their unsaved changes', function (): void {
	$this->references->shouldReceive('index');
	$this->edits->shouldReceive('record');
	$work = $this->mockComponent('hub.work', WorkService::class);
	$work->shouldReceive('forgetCounts')->times(4);

	($this->saved)(new Entry(['id' => 5]));
	($this->saved)(new Asset(['id' => 6]));
	($this->saved)(new Entry(['id' => 7, 'draftId' => 2]));
	($this->saved)(new Entry(['id' => 8, 'draftId' => 3, 'isProvisionalDraft' => true]));
	($this->saved)(new Entry(['id' => 9, 'revisionId' => 2]));
	($this->saved)(new Entry(['id' => 10, 'propagating' => true]));
	($this->saved)(new User(['id' => 11]));
	Event::trigger(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, new ElementEvent(['element' => new Entry(['id' => 12])]));
});
