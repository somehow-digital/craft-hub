<?php

declare(strict_types=1);

use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\DeleteElementEvent;
use craft\events\ElementEvent;
use craft\services\Elements;
use Mockery\MockInterface;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\handlers\TrashTracker;
use yii\base\Event;

beforeEach(function (): void {
	$this->db()->tables = [Tables::KEPT_FILES];

	// Only the tracker under test handles the events, not the activity log.
	Event::off(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT);
	Event::off(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT);
	Event::off(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT);
	$this->handler('trashTracker', TrashTracker::class)->register();

	$this->deleting = function (object $element, bool $hardDelete = false): void {
		Event::trigger(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT, new DeleteElementEvent(['element' => $element, 'hardDelete' => $hardDelete]));
	};
	$this->deleted = function (object $element): void {
		Event::trigger(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, new ElementEvent(['element' => $element]));
	};
});

it('keeps the files of assets moved to the trash', function (): void {
	$asset = new Asset(['id' => 5]);

	($this->deleting)($asset);

	expect($asset->keepFileOnDelete)->toBeTrue();
});

it('leaves the files of assets deleted permanently or along with their volume as they are', function (array $config, bool $hardDelete): void {
	/** @var array<string,mixed> $config */
	$asset = new Asset(['id' => 5, ...$config]);

	($this->deleting)($asset, $hardDelete);

	expect($asset->keepFileOnDelete)->toBeFalse();
})->with([
	'deleted permanently' => [[], true],
	'deleted with its volume' => [['deletedWithVolume' => true], false],
]);

it('records the files kept of trashed assets', function (): void {
	/** @var Asset&MockInterface $asset */
	$asset = Mockery::mock(Asset::class)->makePartial();
	$asset->id = 5;
	$asset->volumeId = 1;
	$asset->keepFileOnDelete = true;
	$asset->shouldReceive('getPath')->andReturn('photos/a.jpg');

	($this->deleted)($asset);
	($this->deleted)(new Asset(['id' => 6]));
	($this->deleted)(new Asset(['id' => 7, 'keepFileOnDelete' => true, 'deletedWithVolume' => true]));
	($this->deleted)(new Entry(['id' => 8]));

	expect($this->db()->queriesMatching('{{%hub_kept_files}}'))->toHaveCount(1)
		->and($this->db()->queriesMatching('{{%hub_kept_files}}')[0])->toContain("(5, 1, 'photos/a.jpg'");
});

it('forgets the files kept of assets deleted permanently or restored', function (): void {
	($this->deleted)(new Asset(['id' => 5, 'hardDelete' => true]));
	Event::trigger(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, new ElementEvent(['element' => new Asset(['id' => 6])]));
	Event::trigger(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, new ElementEvent(['element' => new Entry(['id' => 7])]));

	$deletes = $this->db()->queriesMatching('DELETE FROM {{%hub_kept_files}}');

	expect($deletes)->toHaveCount(2)
		->and($deletes[0])->toContain('`assetId`=5')
		->and($deletes[1])->toContain('`assetId`=6');
});
