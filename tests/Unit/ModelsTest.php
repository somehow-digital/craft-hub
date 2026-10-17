<?php

use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Entries as EntriesField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Volume;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\models\Activity;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\Check;
use SomehowDigital\Craft\Hub\models\EntryReference;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\LayoutUsage;
use SomehowDigital\Craft\Hub\models\ReferenceStatistics;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\Settings;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\models\UserStatistics;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

function assetStats(int $total, int $used = 0, int $images = 0, int $missingAlt = 0, int $size = 0, ?string $lastUploaded = null): AssetStatistics
{
	$stats = new AssetStatistics();
	$stats->total = $total;
	$stats->used = $used;
	$stats->images = $images;
	$stats->missingAlt = $missingAlt;
	$stats->size = $size;
	$stats->lastUploaded = $lastUploaded !== null ? new DateTime($lastUploaded) : null;

	return $stats;
}

function entryStats(int $total, int $enabled = 0, int $drafts = 0, ?string $lastUpdated = null): EntryStatistics
{
	$stats = new EntryStatistics();
	$stats->total = $total;
	$stats->enabled = $enabled;
	$stats->drafts = $drafts;
	$stats->lastUpdated = $lastUpdated !== null ? new DateTime($lastUpdated) : null;

	return $stats;
}

it('sums asset statistics, keeping the last upload', function () {
	$stats = assetStats(4, 3, 2, 1, 1000, '2024-01-01')
		->add(assetStats(2, 0, 2, 2, 500, '2024-03-01'))
		->add(assetStats(1, 1, 0, 0, 10, '2024-02-01'))
		->add(assetStats(0));

	expect([$stats->total, $stats->used, $stats->images, $stats->missingAlt, $stats->size])->toBe([7, 4, 4, 3, 1510])
		->and($stats->lastUploaded->format('Y-m-d'))->toBe('2024-03-01')
		->and($stats->getUnused())->toBe(3)
		->and($stats->getAverageSize())->toBe(215);
});

it('averages no assets to zero', function () {
	expect((new AssetStatistics())->getAverageSize())->toBe(0)
		->and((new AssetStatistics())->add(assetStats(0, lastUploaded: '2024-01-01'))->lastUploaded)->not->toBeNull();
});

it('sums entry statistics, keeping the last update', function () {
	$stats = entryStats(3, 2, 1, '2024-01-01')
		->add(entryStats(2, 2, 0))
		->add(entryStats(1, 0, 4, '2023-01-01'));

	expect([$stats->total, $stats->enabled, $stats->drafts, $stats->getDisabled()])->toBe([6, 4, 5, 2])
		->and($stats->lastUpdated->format('Y-m-d'))->toBe('2024-01-01');
});

it('tells how recently entries were updated', function () {
	expect(entryStats(0)->getActivity(12))->toBe(EntryStatistics::ACTIVITY_NONE)
		->and(entryStats(1, lastUpdated: '-13 months')->getActivity(12))->toBe(EntryStatistics::ACTIVITY_STALE)
		->and(entryStats(1, lastUpdated: '-11 months')->getActivity(12))->toBe(EntryStatistics::ACTIVITY_RECENT)
		->and(entryStats(1, lastUpdated: '-2 months')->getActivity(1))->toBe(EntryStatistics::ACTIVITY_STALE)
		->and(EntryStatistics::getActivities())->toBe(['recent', 'stale', 'none']);
});

it('sums reference statistics', function () {
	$a = new ReferenceStatistics();
	$a->references = 3;
	$a->sources = 2;
	$a->targets = 1;

	$b = new ReferenceStatistics();
	$b->references = 1;
	$b->sources = 1;
	$b->targets = 1;

	expect($a->add($b))->toBe($a)
		->and([$a->references, $a->sources, $a->targets])->toBe([4, 3, 2]);
});

it('links a check to the next step, unless there is none', function () {
	$check = new Check('Heading', 'Failed', 'Passed', Severity::Notice, 'icon', false);

	expect($check->link(null, 'Open')->url)->toBeNull()
		->and($check->action)->toBeNull()
		->and($check->link('https://example.test', 'Open'))->toBe($check)
		->and([$check->url, $check->action])->toBe(['https://example.test', 'Open']);
});

it('collects the paths of a reference once', function () {
	$field = new PlainText(['id' => 1]);
	$reference = new EntryReference(new Entry(['id' => 1]));
	$reference->addPath('Content → Image', $field);
	$reference->addPath('Content → Image', null);
	$reference->addPath('Teaser', null);

	expect($reference->paths)->toBe([
		'Content → Image' => ['label' => 'Content → Image', 'field' => $field],
		'Teaser' => ['label' => 'Teaser', 'field' => null],
	]);
});

it('describes the usage of an entry type', function () {
	$info = new EntryTypeInfo(new EntryType(['id' => 1]));
	$info->sections = [new Section(['id' => 1])];
	$info->nestedFields = [new Matrix(['id' => 1]), new Matrix(['id' => 2])];
	$info->referenceFields = [new EntriesField(['id' => 3])];
	$info->kinds = [UsageKind::Section, UsageKind::Nested];

	expect($info->stats)->toBeInstanceOf(EntryStatistics::class)
		->and($info->hasKind(UsageKind::Nested))->toBeTrue()
		->and($info->hasKind(UsageKind::Unused))->toBeFalse()
		->and($info->getRelationCount())->toBe(3)
		->and($info->getKindValues())->toBe(['section', 'nested']);
});

it('describes a field holding plain values', function () {
	$info = new FieldInfo(new PlainText(['id' => 1]));

	expect($info->getTypeName())->toBe(PlainText::displayName())
		->and($info->getDataKind())->toBe(DataKind::Plain)
		->and($info->isUnused())->toBeTrue()
		->and($info->isEmpty())->toBeFalse()
		->and($info->getInstanceCount())->toBe(0);

	$info->layouts = [new LayoutUsage('Entry Type', 'Article', instances: 2), new LayoutUsage('Volume', 'Images'), new LayoutUsage('Entry Type', 'Page')];

	expect($info->isUnused())->toBeFalse()
		->and($info->isEmpty())->toBeFalse()
		->and($info->getInstanceCount())->toBe(4)
		->and($info->getOwnerTypes())->toBe(['Entry Type', 'Volume']);
});

it('tells whether a placed nested field is empty', function () {
	$info = new FieldInfo(new Matrix(['id' => 1]));
	$info->isNested = true;
	$info->isRelational = true;

	expect($info->getDataKind())->toBe(DataKind::Nested)
		->and($info->isEmpty())->toBeFalse();

	$info->layouts = [new LayoutUsage('Entry Type', 'Article')];
	expect($info->isEmpty())->toBeTrue();

	$info->stats->total = 1;
	expect($info->isEmpty())->toBeFalse();
});

it('tells whether a placed relational field is empty', function () {
	$info = new FieldInfo(new EntriesField(['id' => 1]));
	$info->isRelational = true;
	$info->layouts = [new LayoutUsage('Entry Type', 'Article')];

	expect($info->getDataKind())->toBe(DataKind::Relational)
		->and($info->isEmpty())->toBeTrue();

	$info->references->references = 2;
	expect($info->isEmpty())->toBeFalse();
});

it('describes file kinds', function (string $kind, string $icon, string $color) {
	$info = new FileKindInfo($kind);

	expect($info->getIcon())->toBe($icon)
		->and($info->getColor())->toBe($color)
		->and($info->getLabel())->toBeString()->not->toBeEmpty()
		->and($info->stats)->toBeInstanceOf(AssetStatistics::class);
})->with([
	[Asset::KIND_IMAGE, 'image', 'sky'],
	[Asset::KIND_PHOTOSHOP, 'image', 'sky'],
	[Asset::KIND_VIDEO, 'film', 'violet'],
	[Asset::KIND_AUDIO, 'music', 'pink'],
	[Asset::KIND_PDF, 'file-pdf', 'red'],
	[Asset::KIND_WORD, 'file-lines', 'blue'],
	[Asset::KIND_EXCEL, 'table', 'green'],
	[Asset::KIND_POWERPOINT, 'presentation-screen', 'orange'],
	[Asset::KIND_COMPRESSED, 'file-zipper', 'amber'],
	[Asset::KIND_JSON, 'code', 'teal'],
	[Asset::KIND_CAPTIONS_SUBTITLES, 'closed-captioning', 'gray'],
	[Asset::KIND_UNKNOWN, 'file', 'gray'],
]);

it('tells whether a section is empty', function () {
	$info = new SectionInfo(new Section(['id' => 1]));

	expect($info->isEmpty())->toBeTrue()
		->and($info->references)->toBeInstanceOf(ReferenceStatistics::class);

	$info->stats->total = 1;
	expect($info->isEmpty())->toBeFalse();
});

it('tells whether a volume is empty and which file kinds it holds', function () {
	$info = new VolumeInfo(new Volume(['id' => 1]));

	expect($info->isEmpty())->toBeTrue()
		->and($info->getKindValues())->toBe([]);

	$info->stats->total = 3;
	$info->kinds = ['image' => assetStats(2), 'pdf' => assetStats(1)];

	expect($info->isEmpty())->toBeFalse()
		->and($info->getKindValues())->toBe(['image', 'pdf']);
});

it('lists how recently users signed in', function () {
	expect(UserFilter::getLogins())->toBe(['recent', 'idle', 'never']);
});

it('counts the active users who have not signed in yet', function () {
	$stats = new UserStatistics();
	$stats->active = 5;
	$stats->recentLogin = 2;
	$stats->idle = 1;

	expect($stats->getNew())->toBe(2);

	$stats->idle = 4;

	expect($stats->getNew())->toBe(0);
});

it('lists the image orientations', function () {
	expect(FileFilter::getOrientations())->toBe(['landscape', 'portrait', 'square']);
});

it('validates the settings', function () {
	$settings = new Settings();

	expect($settings->validate())->toBeTrue();

	$settings->activityRetentionDays = -1;

	expect($settings->validate())->toBeFalse()
		->and(array_keys($settings->getErrors()))->toBe(['activityRetentionDays']);
});

it('names the subject of an activity as it was, or by how many items it was done to', function () {
	$activity = new Activity();
	$activity->action = ActivityAction::Exported;
	$activity->subject = ActivitySubject::Entry;

	expect($activity->getTitle())->toBe('Entry');

	$activity->data = ['count' => 3];
	expect($activity->getTitle())->toBe('3 items');

	$activity->data = ['count' => 1];
	expect($activity->getTitle())->toBe('One item');

	$activity->subjectTitle = 'Hello';
	expect($activity->getTitle())->toBe('Hello');
});

it('tells the site of an activity and who impersonated its user', function () {
	$activity = new Activity();

	expect($activity->getSite())->toBeNull()
		->and($activity->getImpersonatorName())->toBeNull();

	$activity->siteId = 1;
	$activity->data = ['impersonator' => 'admin'];

	expect($activity->getSite()?->handle)->toBe('en')
		->and($activity->getImpersonatorName())->toBe('admin');
});

it('names the attributes and fields an update changed', function () {
	$this->field(PlainText::class, 1, 'summary', ['name' => 'Short Summary']);
	$activity = new Activity();
	$activity->data = ['attributes' => ['typeId', 'slug', 'enabled', 'enabledForSite'], 'fields' => ['summary', 'deleted']];

	expect($activity->getChanges())->toBe(['Entry Type', 'Slug', 'Status', 'Short Summary', 'deleted']);
});
