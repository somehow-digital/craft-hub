<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\helpers\Ids;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\MaintainerService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Sets who maintains a section, entry type, field or volume, on its page, while maintainers are enabled.
 */
class MaintainersController extends BaseController
{
	/**
	 * Makes the users and groups sent the maintainers of an item of a list, and only them, and goes back to its page.
	 */
	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		$maintainers = Hub::getInstance()->maintainers;
		$list = (string)$request->getRequiredBodyParam('list');
		$id = (int)$request->getRequiredBodyParam('id');

		if (!$maintainers->isEnabled()) {
			throw new ForbiddenHttpException('Maintainers are disabled.');
		}

		if (!isset(MaintainerService::LISTS[$list])) {
			throw new BadRequestHttpException('Unknown list.');
		}

		if (!$maintainers->canManage($list)) {
			throw new ForbiddenHttpException('User is not authorized to manage the maintainers of these items.');
		}

		if ($maintainers->getItem($list, $id) === null) {
			throw new NotFoundHttpException('Item not found.');
		}

		$maintainers->setMaintainers($list, $id, Ids::positive($request->getBodyParam('users')), Ids::positive($request->getBodyParam('groups')));

		return $this->asSuccess(Craft::t('hub', 'Maintainers saved.'), redirect: $this->getPostedRedirectUrl());
	}
}
