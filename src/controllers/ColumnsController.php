<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\services\ColumnService;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Keeps the columns users choose for the lists, as they change them in the list's column picker.
 */
class ColumnsController extends BaseController
{
	/**
	 * Keeps the order and the hidden ones of the columns of a list, or forgets them with `reset`.
	 */
	public function actionSave(): Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		/** @var ColumnService $columns */
		$columns = Hub::getInstance()->columns;
		$list = (string)$request->getRequiredBodyParam('list');

		if ($columns->getDefinitions($list) === []) {
			throw new BadRequestHttpException('Unknown list.');
		}

		if ($request->getBodyParam('reset')) {
			$columns->resetColumns($list);
		} else {
			$columns->saveColumns($list, $this->strings($request->getBodyParam('order')), $this->strings($request->getBodyParam('hidden')));
		}

		return $this->asSuccess(data: [
			'columns' => array_map(fn (array $column) => ['key' => $column['key'], 'visible' => $column['visible']], $columns->getColumns($list)),
		]);
	}

	/**
	 * @return string[]
	 */
	private function strings(mixed $values): array
	{
		return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
	}
}
