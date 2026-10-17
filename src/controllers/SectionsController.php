<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\models\EntryType;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SectionsController extends BaseController
{
	public function actionIndex(): Response
	{
		$sections = Hub::getInstance()->structure->getSections();

		return $this->renderTemplate('hub/pages/sections/index', [
			'sections' => $sections,
			'activities' => array_map(fn (SectionInfo $info) => $this->activity($info->stats), $sections),
			'reviews' => array_map(fn (SectionInfo $info) => Hub::getInstance()->checks->forSection($info), $sections),
			'summary' => $this->sectionsSummary($sections),
		]);
	}

	public function actionView(int $id): Response
	{
		$structure = Hub::getInstance()->structure;
		$info = $structure->getSection($id) ?? throw new NotFoundHttpException('Section not found.');
		$statistics = Hub::getInstance()->statistics;
		$siteIds = Hub::getInstance()->getSiteIds();

		$typesWithEntries = count(array_filter(
			$info->entryTypes,
			fn (EntryType $entryType) => $statistics->sumEntries($siteIds, $id, $entryType->id)->total > 0,
		));

		$relations = [];
		foreach ([...$info->referenceFields, ...$info->linkFields] as $field) {
			$relations[$field->name] = $statistics->sumRelations($siteIds, $field->id, $id)->relations;
		}

		$references = $this->topCard(Craft::t('hub', 'Relations'), 'link', $relations);
		$references['note'] = Craft::t('hub', '{entries, plural, =0{No Entries field} =1{One Entries field} other{# Entries fields}} and {links, plural, =0{no Link field} =1{one Link field} other{# Link fields}} can relate to entries from this section', [
			'entries' => count($info->referenceFields),
			'links' => count($info->linkFields),
		]);

		return $this->renderTemplate('hub/pages/sections/view', [
			'info' => $info,
			'summary' => [
				$this->entriesCard($info->stats),
				[
					'label' => Craft::t('hub', 'Entry Types'),
					'icon' => 'shapes',
					'url' => null,
					'total' => count($info->entryTypes),
					'segments' => [
						['label' => Craft::t('hub', 'With entries'), 'value' => $typesWithEntries, 'color' => 'green'],
						['label' => Craft::t('hub', 'No entries here'), 'value' => count($info->entryTypes) - $typesWithEntries, 'color' => 'orange'],
					],
				],
				$references,
			],
			'entryTypes' => $structure->getEntryTypes(),
			'statistics' => Hub::getInstance()->statistics,
			'checks' => Hub::getInstance()->checks->forSection($info),
			...$this->reviewListVariables(sectionId: $id),
		]);
	}
}
