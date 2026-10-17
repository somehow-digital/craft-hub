<?php

use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\models\Section;
use SomehowDigital\Craft\Hub\controllers\EntriesController;
use SomehowDigital\Craft\Hub\controllers\FilesController;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\models\Label;
use SomehowDigital\Craft\Hub\models\SavedFilter;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\FileService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\ForbiddenHttpException;

beforeEach(function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getId')->andReturn(7);
});

it('limits entries to those of sections the user may view, as Craft does', function () {
	$this->section(1, 'news');
	$this->section(2, 'blog');
	$this->section(3, 'home', Section::TYPE_SINGLE);
	$this->section(4, 'secret');
	$this->actingAs(permissions: ['viewEntries:section-news', 'viewPeerEntries:section-news', 'viewEntries:section-blog', 'viewEntries:section-home']);

	$condition = (new EntryService())->viewableCondition();

	expect($condition[1][1])->toBe(['entries.sectionId' => [1, 3]])
		->and($condition[1][2][1])->toBe(['entries.sectionId' => [2]])
		->and($condition[1][2][2][1]->where[2])->toBe(['hubAuthors.authorId' => 7])
		->and($condition[2][1]->from)->toHaveKey('hubOwners');
});

it('limits files to those of volumes the user may view, as Craft does', function () {
	$this->volume(1, 'images');
	$this->volume(2, 'documents');
	$this->volume(3, 'secret');
	$this->actingAs(permissions: ['viewAssets:volume-images', 'viewPeerAssets:volume-images', 'viewAssets:volume-documents']);

	expect((new FileService())->viewableCondition())->toBe([
		'or',
		['assets.volumeId' => [1]],
		['assets.volumeId' => [2], 'assets.uploaderId' => 7],
	]);
});

it('applies the limits to the lists', function () {
	$this->section(1, 'news');
	$this->volume(1, 'images');
	$this->actingAs(permissions: []);

	$entries = Json::encode((new EntryService())->getQuery(new EntryFilter())->where);
	$files = Json::encode((new FileService())->getQuery(new FileFilter())->where);

	expect($entries)->toContain('entries.sectionId')
		->and($files)->toContain('assets.volumeId');
});

it('leaves lists unlimited for admins', function () {
	$this->actingAs(admin: true);

	expect((new EntryService())->viewableCondition())->toBeNull()
		->and((new FileService())->viewableCondition())->toBeNull();
});

it('refuses the pages of entries and files the user may not view', function (string $controller, string $class, array $config) {
	$this->createContentModel();
	ElementQueries::respond($class, [new $class(['id' => 1, 'siteId' => 1, ...$config])]);
	$this->canView = false;

	$this->runAction($controller, 'view', ['id' => 1]);
})->with([
	[EntriesController::class, Entry::class, ['sectionId' => 1, 'typeId' => 10]],
	[FilesController::class, Asset::class, ['volumeId' => 1]],
])->throws(ForbiddenHttpException::class);

it('refuses Hub to users without access to the plugin, whatever else they may do', function () {
	$this->hasPluginAccess = false;

	expect($this->hub()->access->canView(Page::Entries))->toBeFalse();

	$this->runAction(EntriesController::class, 'index');
})->throws(ForbiddenHttpException::class);

it('accepts only names of Craft icons', function (string $icon, bool $valid) {
	$models = [new Label(['name' => 'Name', 'icon' => $icon]), new SavedFilter(['kind' => CheckKind::Entries, 'name' => 'Name', 'icon' => $icon]), new CheckDefinition(['kind' => CheckKind::Entries, 'name' => 'Name', 'icon' => $icon])];

	foreach ($models as $model) {
		$model->validate(['icon']);
		expect($model->hasErrors('icon'))->toBe(!$valid);
	}
})->with([
	['tag', true],
	['arrow-up-1', true],
	['/etc/icon.svg', false],
	['@webroot/icon.svg', false],
	['<svg></svg>', false],
]);

it('loads the items of bulk actions on the selected sites, in the first one selected', function () {
	$this->site(2, 'de');
	$this->site(3, 'fr');
	$this->request([Hub::SITE_PARAM => 'de']);
	ElementQueries::respond(Entry::class, []);

	$this->hub()->bulk->getElements(ColumnService::LIST_ENTRIES, [1]);
	$query = ElementQueries::executed(Entry::class)[0];

	expect($query->siteId)->toBe([2])
		->and($query->unique)->toBeTrue()
		->and($query->preferSites)->toBe([2, 1]);
});
