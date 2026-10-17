<?php

use craft\base\conditions\ConditionRuleInterface;
use craft\models\Section;
use SomehowDigital\Craft\Hub\conditions\EntryTypeCondition;
use SomehowDigital\Craft\Hub\conditions\FieldCondition;
use SomehowDigital\Craft\Hub\conditions\SectionCondition;
use SomehowDigital\Craft\Hub\conditions\VolumeCondition;
use SomehowDigital\Craft\Hub\conditions\rules\ActivityConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\AssetCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\DataKindConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\EntryCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\EntryTypeCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\FieldCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\FieldTypeConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\FileKindConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\HandleConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\HasUrlsConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\InstructionsConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\LastUploadConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\LayoutCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\LayoutFieldsConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\LayoutOwnerConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\MissingAltConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\NameConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\ReferenceCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\RelationCountConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\SearchableConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\SectionTypeConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\SizeConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\TitleFieldConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\TranslationMethodConditionRule;
use SomehowDigital\Craft\Hub\conditions\rules\UsageConditionRule;
use SomehowDigital\Craft\Hub\models\SectionInfo;

it('offers the rules that apply to each kind of item', function (string $class, array $rules) {
	$selectable = (new $class())->getSelectableConditionRules();

	expect(array_map(fn (ConditionRuleInterface $rule) => $rule::class, array_values($selectable)))->toBe($rules)
		->and(array_map(fn (ConditionRuleInterface $rule) => $rule->getLabel(), $selectable))->each->not->toBeEmpty();
})->with([
	'sections' => [SectionCondition::class, [
		NameConditionRule::class,
		HandleConditionRule::class,
		SectionTypeConditionRule::class,
		HasUrlsConditionRule::class,
		EntryCountConditionRule::class,
		EntryTypeCountConditionRule::class,
		ReferenceCountConditionRule::class,
		ActivityConditionRule::class,
	]],
	'entry types' => [EntryTypeCondition::class, [
		NameConditionRule::class,
		HandleConditionRule::class,
		UsageConditionRule::class,
		HasUrlsConditionRule::class,
		EntryCountConditionRule::class,
		RelationCountConditionRule::class,
		ActivityConditionRule::class,
		TitleFieldConditionRule::class,
		LayoutFieldsConditionRule::class,
		FieldCountConditionRule::class,
	]],
	'fields' => [FieldCondition::class, [
		NameConditionRule::class,
		HandleConditionRule::class,
		FieldTypeConditionRule::class,
		DataKindConditionRule::class,
		InstructionsConditionRule::class,
		SearchableConditionRule::class,
		TranslationMethodConditionRule::class,
		LayoutCountConditionRule::class,
		LayoutOwnerConditionRule::class,
		EntryCountConditionRule::class,
		EntryTypeCountConditionRule::class,
	]],
	'volumes' => [VolumeCondition::class, [
		NameConditionRule::class,
		HandleConditionRule::class,
		HasUrlsConditionRule::class,
		AssetCountConditionRule::class,
		FileKindConditionRule::class,
		SizeConditionRule::class,
		MissingAltConditionRule::class,
		ReferenceCountConditionRule::class,
		LastUploadConditionRule::class,
		LayoutFieldsConditionRule::class,
		FieldCountConditionRule::class,
	]],
]);

it('matches an item matching each rule', function () {
	$condition = new SectionCondition();
	$condition->setConditionRules([
		['class' => HandleConditionRule::class, 'operator' => '=', 'value' => 'news'],
		['class' => HasUrlsConditionRule::class, 'value' => true],
	]);

	$info = new SectionInfo($this->section(1, 'news'));
	$info->hasUrls = true;
	$other = new SectionInfo($this->section(2, 'news-archive', Section::TYPE_CHANNEL));

	expect($condition->matchInfo($info))->toBeTrue()
		->and($condition->matchInfo($other))->toBeFalse()
		->and((new SectionCondition())->matchInfo($other))->toBeTrue();
});
