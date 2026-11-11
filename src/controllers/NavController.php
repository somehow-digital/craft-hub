<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\controllers;

use SomehowDigital\Craft\Hub\helpers\Fill;
use SomehowDigital\Craft\Hub\Hub;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The pages of the navigation items themselves, which have no content of their own.
 *
 * @internal
 */
final class NavController extends BaseController
{
	public function actionData(): Response
	{
		return $this->redirectToFirst(Hub::getInstance()->navigation->getDataNavItem());
	}

	public function actionStructure(): Response
	{
		return $this->redirectToFirst(Hub::getInstance()->navigation->getStructureNavItem());
	}

	public function actionManage(): Response
	{
		return $this->redirectToFirst(Hub::getInstance()->navigation->getManageNavItem());
	}

	/**
	 * Redirects to the first page of a navigation item, keeping the selected sites, unless the user may open none.
	 *
	 * @param array<string,mixed>|null $item
	 */
	private function redirectToFirst(?array $item): Response
	{
		if ($item === null) {
			throw new ForbiddenHttpException('User is not authorized to perform this action.');
		}

		$subnav = $item['subnav'] ?? null;
		$first = is_array($subnav) ? reset($subnav) : null;
		$url = is_array($first) ? Fill::text($first['url'] ?? null) : null;

		return $this->redirect($this->hubUrl($url ?? throw new ForbiddenHttpException('User is not authorized to perform this action.')));
	}
}
