<?php

use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\OptionsController;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\ActivityFilter;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function () {
	$this->db()->respond('FROM {{%assets}} {{assets}}', fn (string $sql) => match (true) {
		str_contains($sql, 'GROUP BY `assets`.`kind`') => [['kind' => 'image'], ['kind' => 'pdf']],
		str_contains($sql, 'AS `extension`') => [['extension' => 'jpg', 'count' => 4]],
		str_contains($sql, '`assets`.`uploaderId`') => [['uploaderId' => 4, 'count' => 2]],
		default => [],
	});
	$this->db()->respond('{{%entries_authors}}', [['authorId' => 4, 'count' => 2]]);
	$this->db()->respond('MAX([[id]])', [['userId' => 4, 'userName' => 'Jane'], ['userId' => 9, 'userName' => 'Gone']]);

	ElementQueries::respond(User::class, [new User(['id' => 4, 'siteId' => 1, 'username' => 'jane', 'fullName' => 'Jane Doe'])]);
});

it('renders the options of a filter on their own', function () {
	$response = $this->runAction(OptionsController::class, 'index', ['filter' => 'extension']);

	expect($response->template)->toBe('hub/_components/filter-options')
		->and($response->variables['name'])->toBe('extension')
		->and($response->variables['options'])->toBe(['jpg' => 'jpg']);
});

it('offers the user viewing the list first in filters by users, then the users of the list', function (string $filter) {
	$options = $this->runAction(OptionsController::class, 'index', ['filter' => $filter])->variables['options'];

	expect(array_keys($options))->toBe(['me', 4]);
})->with(['author', 'uploader']);

it('limits the options to what the list is limited to', function () {
	$this->runAction(OptionsController::class, 'index', ['filter' => 'author'], query: ['section' => '3', 'type' => '7', 'field' => 'x']);

	expect($this->db()->queriesMatching('{{%entries_authors}}')[0])->toContain('`entries`.`sectionId`', '`entries`.`typeId`')
		->not->toContain('`entries`.`fieldId`');
});

it('offers the kinds of the files there are', function () {
	$options = $this->runAction(OptionsController::class, 'index', ['filter' => 'kind'])->variables['options'];

	expect(array_keys($options))->toBe(['image', 'pdf'])
		->and($options['image'])->toHaveKeys(['label', 'icon', 'color']);
});

it('offers the users of the activity log in the order of their names, and the activities without a user', function () {
	$options = $this->runAction(OptionsController::class, 'index', ['filter' => 'user'])->variables['options'];

	expect(array_keys($options))->toBe([9, 4, ActivityFilter::USER_NONE])
		->and($options[4]['user'])->toBeInstanceOf(User::class)
		->and($options[9]['icon'])->toBe('user-slash');
});

it('knows no other filters', function () {
	$this->runAction(OptionsController::class, 'index', ['filter' => 'bogus']);
})->throws(NotFoundHttpException::class);

it('requires access to the list a filter is of', function (string $filter, array $permissions) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', 'accessPlugin-hub', ...$permissions]);

	$this->runAction(OptionsController::class, 'index', ['filter' => $filter]);
})->with([
	'authors without the entries' => ['author', [Hub::PERMISSION_ACCESS_DATA, Page::Assets->viewPermission()]],
	'extensions without the assets' => ['extension', [Hub::PERMISSION_ACCESS_DATA, Page::Entries->viewPermission()]],
	'users without the activity log' => ['user', [Hub::PERMISSION_ACCESS_DATA]],
])->throws(ForbiddenHttpException::class);

it('offers no users while the activity log is disabled', function () {
	$this->hub()->settings->enableActivity = false;

	$this->runAction(OptionsController::class, 'index', ['filter' => 'user']);
})->throws(ForbiddenHttpException::class);
