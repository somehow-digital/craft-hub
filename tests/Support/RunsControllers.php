<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use craft\web\TemplateResponseBehavior;
use Mockery;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\services\EntryService;
use SomehowDigital\Craft\Hub\services\FileService;

/**
 * Runs controller actions as a signed-in user, without rendering their templates.
 */
trait RunsControllers
{
	/**
	 * Runs an action on a CP request with the given query params.
	 *
	 * @param class-string<Controller> $class
	 * @param array<string,mixed> $params action params
	 * @param array<string,mixed> $query query params
	 */
	protected function runAction(string $class, string $action, array $params = [], array $query = [], string $path = ''): TemplateResponseBehavior
	{
		$this->request($query, path: $path);
		Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => $this->isAdmin]));

		$controller = Craft::createObject($class, ['test', $this->hub()]);
		$controller->runAction($action, $params);

		/** @var TemplateResponseBehavior $behavior */
		$behavior = Craft::$app->getResponse()->getBehavior(TemplateResponseBehavior::NAME);

		return $behavior;
	}

	/**
	 * Makes the queries of the entries and files lists countable, so they can be paginated.
	 */
	protected function countableQueries(): void
	{
		$entries = Mockery::mock(EntryService::class)->makePartial();
		$entries->shouldReceive('getQuery')->andReturnUsing(fn (EntryFilter $filter, string $sort = EntryService::SORT_UPDATED, int $direction = SORT_DESC) => CountableEntryQuery::of((new EntryService())->getQuery($filter, $sort, $direction)));
		$this->hub()->set('entries', $entries);

		$files = Mockery::mock(FileService::class)->makePartial();
		$files->shouldReceive('getQuery')->andReturnUsing(fn (FileFilter $filter, string $sort = FileService::SORT_UPLOADED, int $direction = SORT_DESC) => CountableAssetQuery::of((new FileService())->getQuery($filter, $sort, $direction)));
		$this->hub()->set('files', $files);
	}
}
