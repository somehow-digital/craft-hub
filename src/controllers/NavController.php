<?php

namespace SomehowDigital\Craft\Hub\controllers;

use SomehowDigital\Craft\Hub\Hub;
use yii\web\Response;

/**
 * The pages of the navigation items themselves, which have no content of their own.
 */
class NavController extends BaseController
{
	public function actionData(): Response
	{
		return $this->redirectToFirst(Hub::getInstance()->getCpNavItem());
	}

	public function actionStructure(): Response
	{
		return $this->redirectToFirst(Hub::getInstance()->getStructureNavItem());
	}

	public function actionManage(): Response
	{
		$this->requirePermission(Hub::PERMISSION_MANAGE_LABELS);

		return $this->redirectToFirst(Hub::getInstance()->getManageNavItem());
	}

	/**
	 * Redirects to the first page of a navigation item, keeping the selected sites.
	 *
	 * @param array{subnav:array<string,array{url:string}>}|null $item
	 */
	private function redirectToFirst(?array $item): Response
	{
		return $this->redirect($this->hubUrl(reset($item['subnav'])['url']));
	}
}
