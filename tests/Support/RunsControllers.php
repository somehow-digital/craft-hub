<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\Tests\Support;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use craft\web\Response as WebResponse;
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
	 * @param array<mixed> $params action params
	 * @param array<mixed> $query query params
	 */
	public function runAction(string $class, string $action, array $params = [], array $query = [], string $path = ''): TemplateResponseBehavior
	{
		$this->request($query, path: $path);
		mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => $this->isAdmin]));

		$controller = Craft::createObject($class, ['test', $this->hub()]);
		$controller->runAction($action, $params);

		/** @var TemplateResponseBehavior $behavior */
		$behavior = webResponse()->getBehavior(TemplateResponseBehavior::NAME);

		return $behavior;
	}

	/**
	 * Posts the body params to an action as a signed-in user, by default an admin, on a request accepting JSON.
	 *
	 * @param class-string<Controller> $class
	 * @param array<mixed> $body
	 */
	public function postTo(string $class, string $action, array $body = [], ?User $identity = null): WebResponse
	{
		$this->request();
		$request = webRequest();
		$request->enableCsrfValidation = false;
		$request->enableCookieValidation = false;
		$request->setBodyParams($body);
		$request->getHeaders()->set('Accept', 'application/json');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn($identity ?? new User(['id' => 1, 'username' => 'admin', 'admin' => $this->isAdmin]));

		try {
			$response = Craft::createObject($class, ['test', $this->hub()])->runAction($action);
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}

		return $response instanceof WebResponse ? $response : webResponse();
	}
}
