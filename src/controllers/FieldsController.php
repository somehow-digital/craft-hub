<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\models\EntryType;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FieldsController extends BaseController
{
	public function actionIndex(): Response
	{
		$fields = Hub::getInstance()->structure->getFields();

		$types = array_unique(array_map(fn (FieldInfo $info) => $info->getTypeName(), $fields));
		natcasesort($types);

		return $this->renderTemplate('hub/pages/fields/index', [
			'fields' => $fields,
			'types' => $types,
			'reviews' => array_map(fn (FieldInfo $info) => Hub::getInstance()->checks->forField($info), $fields),
			'summary' => $this->fieldsSummary($fields),
		]);
	}

	public function actionView(int $id): Response
	{
		$structure = Hub::getInstance()->structure;
		$info = $structure->getField($id) ?? throw new NotFoundHttpException('Field not found.');
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->getSiteIds();

		$owners = [];
		foreach ($info->layouts as $usage) {
			$owners[$usage->ownerType] = ($owners[$usage->ownerType] ?? 0) + 1;
		}

		$layouts = $this->topCard(Craft::t('hub', 'Relations'), 'link', $owners);
		$layouts['note'] = Craft::t('hub', '{count, plural, =0{No instances} =1{One instance} other{# instances}}', ['count' => $info->getInstanceCount()]);
		$summary = [$layouts];

		if ($info->isNested) {
			$typesWithEntries = count(array_filter(
				$info->entryTypes,
				fn (EntryType $entryType) => $statistics->sumEntries($siteIds, null, $entryType->id, $info->field->id)->total > 0,
			));

			$summary[] = $this->entriesCard($info->stats);
			$summary[] = [
				'label' => Craft::t('hub', 'Entry Types'),
				'icon' => 'shapes',
				'url' => null,
				'total' => count($info->entryTypes),
				'segments' => [
					['label' => Craft::t('hub', 'With entries'), 'value' => $typesWithEntries, 'color' => 'green'],
					['label' => Craft::t('hub', 'No entries here'), 'value' => count($info->entryTypes) - $typesWithEntries, 'color' => 'orange'],
				],
			];
		}

		if ($info->isRelational) {
			$summary[] = [
				'label' => Craft::t('hub', 'Relations'),
				'icon' => DataKind::Relational->icon(),
				'url' => null,
				'total' => $info->relations->relations,
				'segments' => [],
				'note' => Craft::t('hub', '{sources, number} source elements · {targets, number} referenced elements', [
					'sources' => $info->relations->sources,
					'targets' => $info->relations->targets,
				]),
			];
		}

		return $this->renderTemplate('hub/pages/fields/view', [
			'info' => $info,
			'summary' => $summary,
			'entryTypes' => $structure->getEntryTypes(),
			'fields' => $structure->getFields(),
			'statistics' => Hub::getInstance()->statistics,
			'checks' => Hub::getInstance()->checks->forField($info),
		]);
	}
}
