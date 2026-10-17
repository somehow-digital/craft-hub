<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class TypesController extends BaseController
{
	public function actionIndex(): Response
	{
		$entryTypes = Hub::getInstance()->structure->getEntryTypes();

		return $this->renderTemplate('hub/pages/types/index', [
			'entryTypes' => $entryTypes,
			'kinds' => UsageKind::cases(),
			'activities' => array_map(fn (EntryTypeInfo $info) => $this->activity($info->stats), $entryTypes),
			'reviews' => array_map(fn (EntryTypeInfo $info) => Hub::getInstance()->checks->forEntryType($info), $entryTypes),
			'summary' => $this->entryTypesSummary($entryTypes),
		]);
	}

	public function actionView(int $id): Response
	{
		$structure = Hub::getInstance()->structure;
		$info = $structure->getEntryType($id) ?? throw new NotFoundHttpException('Entry type not found.');
		$fields = $structure->getFields();

		$kinds = [];
		foreach ($info->entryType->getFieldLayout()->getCustomFields() as $field) {
			$kind = isset($fields[$field->id]) ? $fields[$field->id]->getDataKind() : DataKind::Plain;
			$kinds[$kind->value] = ($kinds[$kind->value] ?? 0) + 1;
		}

		return $this->renderTemplate('hub/pages/types/view', [
			'info' => $info,
			'summary' => [
				$this->entriesCard($info->stats),
				[
					'label' => Craft::t('hub', 'Relations'),
					'icon' => 'link',
					'url' => null,
					'total' => count($info->sections) + $info->getRelationCount(),
					'segments' => [
						['label' => Craft::t('hub', 'Sections'), 'value' => count($info->sections), 'color' => UsageKind::Section->color()],
						['label' => Craft::t('hub', 'Nested entry fields'), 'value' => count($info->nestedFields), 'color' => UsageKind::Nested->color()],
						['label' => Craft::t('hub', 'Reference fields'), 'value' => count($info->referenceFields), 'color' => UsageKind::Reference->color()],
						['label' => Craft::t('hub', 'Link fields'), 'value' => count($info->linkFields), 'color' => UsageKind::Link->color()],
					],
				],
				[
					'label' => Craft::t('hub', 'Fields'),
					'icon' => 'pen-ruler',
					'url' => null,
					'total' => array_sum($kinds),
					'segments' => array_map(fn (DataKind $kind) => [
						'label' => $kind->label(),
						'value' => $kinds[$kind->value] ?? 0,
						'color' => $kind->color(),
						'icon' => $kind->icon(),
					], DataKind::cases()),
				],
			],
			'fields' => $fields,
			'statistics' => Hub::getInstance()->statistics,
			'checks' => Hub::getInstance()->checks->forEntryType($info),
			...$this->reviewListVariables(typeId: $id),
		]);
	}
}
