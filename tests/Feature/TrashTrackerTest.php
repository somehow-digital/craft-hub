<?php

use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\DeleteElementEvent;
use craft\events\ElementEvent;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\handlers\TrashTracker;
use SomehowDigital\Craft\Hub\services\TrashService;
use yii\base\Event;

beforeEach(function () {
	$this->trash = $this->mockComponent('hub.trash', TrashService::class);

	// Only the tracker under test handles the events, not the activity log.
	Event::off(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT);
	Event::off(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT);
	Event::off(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT);
	(new TrashTracker())->register();

	$this->deleting = fn (object $element, bool $hardDelete = false) => Event::trigger(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT, new DeleteElementEvent(['element' => $element, 'hardDelete' => $hardDelete]));
	$this->deleted = fn (object $element) => Event::trigger(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, new ElementEvent(['element' => $element]));
});

it('keeps the files of assets moved to the trash', function () {
	$asset = new Asset(['id' => 5]);

	($this->deleting)($asset);

	expect($asset->keepFileOnDelete)->toBeTrue();
});

it('leaves the files of assets deleted permanently or along with their volume as they are', function (array $config, bool $hardDelete) {
	$asset = new Asset(['id' => 5, ...$config]);

	($this->deleting)($asset, $hardDelete);

	expect($asset->keepFileOnDelete)->toBeFalse();
})->with([
	'deleted permanently' => [[], true],
	'deleted with its volume' => [['deletedWithVolume' => true], false],
]);

it('records the files kept of trashed assets', function () {
	$asset = new Asset(['id' => 5, 'keepFileOnDelete' => true]);
	$this->trash->shouldReceive('recordKeptFile')->once()->with($asset);

	($this->deleted)($asset);
	($this->deleted)(new Asset(['id' => 6]));
	($this->deleted)(new Asset(['id' => 7, 'keepFileOnDelete' => true, 'deletedWithVolume' => true]));
	($this->deleted)(new Entry(['id' => 8]));
});

it('forgets the files kept of assets deleted permanently or restored', function () {
	$this->trash->shouldReceive('forgetKeptFile')->once()->with(5);
	$this->trash->shouldReceive('forgetKeptFile')->once()->with(6);

	($this->deleted)(new Asset(['id' => 5, 'hardDelete' => true]));
	Event::trigger(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, new ElementEvent(['element' => new Asset(['id' => 6])]));
	Event::trigger(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, new ElementEvent(['element' => new Entry(['id' => 7])]));
});
