<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use craft\web\TemplateResponseBehavior;

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
}
