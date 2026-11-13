<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\controllers;

use SomehowDigital\Craft\Hub\Hub;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Keeps the columns users choose for the lists, as they change them in the list's column picker.
 *
 * @internal
 */
final class ColumnsController extends BaseController
{
	/**
	 * Keeps the order and the hidden ones of the columns of a list, or forgets them with `reset`.
	 */
	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$request = $this->request;
		$columns = Hub::getInstance()->columns;
		$list = $this->requiredBodyString('list');

		if ($columns->getDefinitions($list) === []) {
			throw new BadRequestHttpException('Unknown list.');
		}

		if (!Hub::getInstance()->access->canViewList($list)) {
			throw new ForbiddenHttpException('User is not authorized to perform this action.');
		}

		if ($this->bodyBool('reset')) {
			$columns->resetColumns($list);
		} else {
			$columns->saveColumns($list, $this->strings($request->getBodyParam('order')), $this->strings($request->getBodyParam('hidden')));
		}

		return $this->asSuccess(data: [
			'columns' => array_map(fn(array $column): array => ['key' => $column['key'], 'visible' => $column['visible']], $columns->getColumns($list)),
		]);
	}

	/**
	 * @return list<string>
	 */
	private function strings(mixed $values): array
	{
		return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
	}
}
