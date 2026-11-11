<?php

declare(strict_types=1);

use craft\elements\db\ElementQuery;
use craft\elements\User;
use craft\models\Section;
use craft\models\UserGroup;
use craft\services\Entries;
use craft\services\UserGroups;
use SomehowDigital\Craft\Hub\controllers\MaintainersController;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function (): void {
	$this->createContentModel();
	$this->hub()->settings->enableMaintainers = true;

	$this->mockComponent('entries', Entries::class)
		->shouldReceive('getSectionById')->andReturnUsing(fn(int $id): ?Section => $id === 1 ? new Section(['id' => 1, 'name' => 'News']) : null);

	$users = [new User(['id' => 3, 'username' => 'jane']), new User(['id' => 4, 'username' => 'john'])];
	ElementQueries::respond(User::class, fn(ElementQuery $query): array => array_filter($users, fn(User $user): bool => in_array($user->id, listOf($query->id), true)));
	$this->mockComponent('userGroups', UserGroups::class)
		->shouldReceive('getGroupById')->andReturnUsing(fn(int $id): ?UserGroup => $id === 7 ? new UserGroup(['id' => 7, 'name' => 'Editors']) : null);
});

afterEach(function (): void {
	$this->hub()->settings->enableMaintainers = false;
});

it('sets the maintainers of an item, ignoring invalid IDs', function (): void {
	$response = $this->postTo(MaintainersController::class, 'save', ['list' => ColumnService::LIST_SECTIONS, 'id' => 1, 'users' => ['3', '', '4'], 'groups' => ['7']]);
	$insert = $this->db()->queriesMatching(sqlInsertInto(Tables::MAINTAINERS))[0];

	expect($insert)->toContain("('sections', 1, 3, NULL)")
		->and($insert)->toContain("('sections', 1, 4, NULL)")
		->and($insert)->toContain("('sections', 1, NULL, 7)")
		->and(at($response->data, 'message'))->toBe('Maintainers saved.');
});

it('clears the maintainers without any sent', function (): void {
	$this->postTo(MaintainersController::class, 'save', ['list' => ColumnService::LIST_SECTIONS, 'id' => 1, 'users' => '']);

	expect($this->db()->queriesMatching(sqlDeleteFrom(Tables::MAINTAINERS)))->toHaveCount(1)
		->and($this->db()->queriesMatching(sqlInsertInto(Tables::MAINTAINERS)))->toBe([]);
});

it('rejects maintainers while disabled', function (): void {
	$this->hub()->settings->enableMaintainers = false;

	$this->postTo(MaintainersController::class, 'save', ['list' => ColumnService::LIST_SECTIONS, 'id' => 1]);
})->throws(ForbiddenHttpException::class);

it('rejects lists without maintainers', function (): void {
	$this->postTo(MaintainersController::class, 'save', ['list' => ColumnService::LIST_ENTRIES, 'id' => 1]);
})->throws(BadRequestHttpException::class);

it('rejects users who may not manage maintainers', function (): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessStructure->value, Page::Sections->viewPermission()]);

	$this->postTo(MaintainersController::class, 'save', ['list' => ColumnService::LIST_SECTIONS, 'id' => 1]);
})->throws(ForbiddenHttpException::class);

it('rejects items that do not exist', function (): void {
	$this->postTo(MaintainersController::class, 'save', ['list' => ColumnService::LIST_SECTIONS, 'id' => 2]);
})->throws(NotFoundHttpException::class);
