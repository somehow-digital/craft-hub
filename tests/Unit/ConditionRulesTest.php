<?php

use craft\base\Field;
use craft\fields\Entries as EntriesField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Volume;
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
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\models\AssetStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\FileKindInfo;
use SomehowDigital\Craft\Hub\models\LayoutUsage;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;
use SomehowDigital\Craft\Hub\services\StructureService;
use SomehowDigital\Craft\Hub\services\VolumeService;

function ruleSection(int $references = 0, ?string $lastUpdated = null, int $entries = 0): SectionInfo
{
	$info = new SectionInfo(new Section(['id' => 1]));
	$info->references->references = $references;
	$info->stats->total = $entries;
	$info->stats->lastUpdated = $lastUpdated !== null ? new DateTime($lastUpdated) : null;

	return $info;
}

function ruleVolume(int $size = 0, int $missingAlt = 0, ?string $lastUploaded = null, array $kinds = []): VolumeInfo
{
	$info = new VolumeInfo(new Volume(['id' => 1]));
	$info->stats->size = $size;
	$info->stats->missingAlt = $missingAlt;
	$info->stats->lastUploaded = $lastUploaded !== null ? new DateTime($lastUploaded) : null;
	$info->kinds = array_fill_keys($kinds, new AssetStatistics());

	return $info;
}

/**
 * The options a rule offers, by their value.
 *
 * @return array<string,string>
 */
function ruleOptions(object $rule): array
{
	return array_column((fn () => $this->options())->call($rule), 'label', 'value');
}

it('matches the number of references', function () {
	$rule = new ReferenceCountConditionRule(['operator' => '>=', 'value' => 2]);

	expect($rule->matchInfo(ruleSection(references: 2)))->toBeTrue()
		->and($rule->matchInfo(ruleSection(references: 1)))->toBeFalse()
		->and($rule->matchInfo(new EntryTypeInfo(new EntryType(['id' => 1]))))->toBeFalse();
});

it('matches the number of relations of an entry type', function () {
	$info = new EntryTypeInfo(new EntryType(['id' => 1]));
	$info->sections = [new Section(['id' => 1])];
	$info->referenceFields = [new EntriesField(['id' => 1])];
	$rule = new RelationCountConditionRule(['operator' => '=', 'value' => 2]);

	expect($rule->matchInfo($info))->toBeTrue()
		->and($rule->matchInfo(ruleSection()))->toBeFalse();
});

it('matches how recently the entries were edited', function () {
	$rule = new ActivityConditionRule(['operator' => 'in', 'values' => ['stale', 'none']]);

	expect($rule->matchInfo(ruleSection(lastUpdated: '-10 years', entries: 1)))->toBeTrue()
		->and($rule->matchInfo(ruleSection()))->toBeTrue()
		->and($rule->matchInfo(ruleSection(lastUpdated: 'now', entries: 1)))->toBeFalse()
		->and($rule->matchInfo(ruleVolume()))->toBeTrue()
		->and(array_keys(ruleOptions($rule)))->toBe(['recent', 'stale', 'none']);
});

it('matches the size of a volume in megabytes', function () {
	$rule = new SizeConditionRule(['operator' => '>', 'value' => '1.5']);

	expect($rule->matchInfo(ruleVolume(size: 2 * 1024 * 1024)))->toBeTrue()
		->and($rule->matchInfo(ruleVolume(size: 1024 * 1024)))->toBeFalse()
		->and($rule->matchInfo(ruleSection()))->toBeFalse();
});

it('matches the number of images without alternative text', function () {
	$rule = new MissingAltConditionRule(['operator' => '>=', 'value' => 1]);

	expect($rule->matchInfo(ruleVolume(missingAlt: 3)))->toBeTrue()
		->and($rule->matchInfo(ruleVolume()))->toBeFalse()
		->and($rule->matchInfo(ruleSection()))->toBeFalse();
});

it('matches the date of the last upload', function () {
	$rule = new LastUploadConditionRule(['rangeType' => 'range', 'startDate' => '2024-01-01', 'endDate' => '2024-01-31']);

	expect($rule->matchInfo(ruleVolume(lastUploaded: '2024-01-31 18:00:00')))->toBeTrue()
		->and($rule->matchInfo(ruleVolume(lastUploaded: '2024-02-01')))->toBeFalse()
		->and($rule->matchInfo(ruleVolume()))->toBeFalse()
		->and($rule->matchInfo(ruleSection()))->toBeFalse();
});

it('matches the types of the layouts a field is placed in', function () {
	$info = new FieldInfo(new PlainText(['id' => 1]));
	$info->layouts = [new LayoutUsage('Entry Types', 'Article'), new LayoutUsage('Users', 'Users')];
	$other = new FieldInfo(new PlainText(['id' => 2]));
	$other->layouts = [new LayoutUsage('Addresses', 'Addresses')];

	$this->mockComponent('hub.structure', StructureService::class)->allows('getFields')->andReturn([$info, $other]);
	$rule = new LayoutOwnerConditionRule(['operator' => 'in', 'values' => ['Users']]);

	expect($rule->matchInfo($info))->toBeTrue()
		->and($rule->matchInfo($other))->toBeFalse()
		->and($rule->matchInfo(ruleSection()))->toBeFalse()
		->and(ruleOptions($rule))->toBe(['Addresses' => 'Addresses', 'Entry Types' => 'Entry Types', 'Users' => 'Users']);
});

it('matches the kinds of files a volume holds', function () {
	$this->mockComponent('hub.volumes', VolumeService::class)->allows('getKinds')->andReturn(['image' => new FileKindInfo('image'), 'pdf' => new FileKindInfo('pdf')]);
	$rule = new FileKindConditionRule(['operator' => 'in', 'values' => ['pdf']]);

	expect($rule->matchInfo(ruleVolume(kinds: ['image', 'pdf'])))->toBeTrue()
		->and($rule->matchInfo(ruleVolume(kinds: ['image'])))->toBeFalse()
		->and($rule->matchInfo(ruleSection()))->toBeFalse()
		->and(array_keys(ruleOptions($rule)))->toBe(['image', 'pdf']);
});

it('matches the name and handle of any item', function () {
	$section = new SectionInfo($this->section(1, 'news', name: 'News'));
	$entryType = new EntryTypeInfo($this->entryType(1, 'article', name: 'Article'));
	$field = new FieldInfo($this->field(PlainText::class, 1, 'summary', ['name' => 'Summary']));
	$volume = new VolumeInfo($this->volume(1, 'images', name: 'Images'));
	$name = new NameConditionRule(['operator' => 'ew', 'value' => 's']);
	$handle = new HandleConditionRule(['operator' => '=', 'value' => 'article']);

	expect(array_map(fn ($info) => $name->matchInfo($info), [$section, $entryType, $field, $volume]))->toBe([true, false, false, true])
		->and(array_map(fn ($info) => $handle->matchInfo($info), [$section, $entryType, $field, $volume]))->toBe([false, true, false, false]);
});

it('matches whether an item has URLs', function () {
	$rule = new HasUrlsConditionRule(['value' => true]);
	$info = ruleVolume();
	$info->hasUrls = true;

	expect($rule->matchInfo($info))->toBeTrue()
		->and($rule->matchInfo(ruleVolume()))->toBeFalse();
});

it('matches the number of entries and assets', function () {
	$entries = new EntryCountConditionRule(['operator' => '>=', 'value' => 1]);
	$assets = new AssetCountConditionRule(['operator' => '>=', 'value' => 1]);
	$volume = ruleVolume();
	$volume->stats->total = 2;

	expect($entries->matchInfo(ruleSection(entries: 1)))->toBeTrue()
		->and($entries->matchInfo(ruleSection()))->toBeFalse()
		->and($entries->matchInfo($volume))->toBeFalse()
		->and($assets->matchInfo($volume))->toBeTrue()
		->and($assets->matchInfo(ruleVolume()))->toBeFalse()
		->and($assets->matchInfo(ruleSection(entries: 1)))->toBeFalse();
});

it('matches the number of entry types of a section or nested entry field', function () {
	$rule = new EntryTypeCountConditionRule(['operator' => '=', 'value' => 2]);
	$section = ruleSection();
	$section->entryTypes = [new EntryType(['id' => 1]), new EntryType(['id' => 2])];
	$field = new FieldInfo(new Matrix(['id' => 1]));
	$field->entryTypes = $section->entryTypes;

	expect($rule->matchInfo($section))->toBeTrue()
		->and($rule->matchInfo($field))->toBeTrue()
		->and($rule->matchInfo(ruleSection()))->toBeFalse()
		->and($rule->matchInfo(new EntryTypeInfo(new EntryType(['id' => 1]))))->toBeFalse();
});

it('matches the type of a section', function () {
	$rule = new SectionTypeConditionRule(['operator' => 'in', 'values' => [Section::TYPE_SINGLE]]);

	expect($rule->matchInfo(new SectionInfo($this->section(1, 'home', Section::TYPE_SINGLE))))->toBeTrue()
		->and($rule->matchInfo(new SectionInfo($this->section(2, 'news', Section::TYPE_CHANNEL))))->toBeFalse()
		->and($rule->matchInfo(ruleVolume()))->toBeFalse()
		->and(array_keys(ruleOptions($rule)))->toBe([Section::TYPE_SINGLE, Section::TYPE_CHANNEL, Section::TYPE_STRUCTURE]);
});

it('matches how an entry type is used', function () {
	$rule = new UsageConditionRule(['operator' => 'in', 'values' => [UsageKind::Unused->value]]);
	$unused = new EntryTypeInfo(new EntryType(['id' => 1]));
	$unused->kinds = [UsageKind::Unused];
	$used = new EntryTypeInfo(new EntryType(['id' => 2]));
	$used->kinds = [UsageKind::Section, UsageKind::HasUrls];

	expect($rule->matchInfo($unused))->toBeTrue()
		->and($rule->matchInfo($used))->toBeFalse()
		->and($rule->matchInfo(ruleSection()))->toBeFalse()
		->and(array_keys(ruleOptions($rule)))->toBe(array_map(fn (UsageKind $kind) => $kind->value, UsageKind::listed()));
});

it('matches whether entries of an entry type have a title field', function () {
	$rule = new TitleFieldConditionRule(['value' => false]);

	expect($rule->matchInfo(new EntryTypeInfo(new EntryType(['id' => 1, 'hasTitleField' => false]))))->toBeTrue()
		->and($rule->matchInfo(new EntryTypeInfo(new EntryType(['id' => 2, 'hasTitleField' => true]))))->toBeFalse()
		->and($rule->matchInfo(ruleSection()))->toBeTrue();
});

it('matches the fields in the layout of an entry type or volume', function () {
	$summary = $this->field(PlainText::class, 1, 'summary', ['name' => 'Summary']);
	$body = $this->field(PlainText::class, 2, 'body', ['name' => 'Body']);
	$article = new EntryTypeInfo($this->entryType(1, 'article', [$summary, $body]));
	$images = new VolumeInfo($this->volume(1, 'images', fields: [$summary]));
	$this->mockComponent('hub.structure', StructureService::class)->allows('getFields')->andReturn([new FieldInfo($summary), new FieldInfo($body)]);

	$fields = new LayoutFieldsConditionRule(['operator' => 'in', 'values' => ['field-body']]);
	$count = new FieldCountConditionRule(['operator' => '>=', 'value' => 1]);

	expect($fields->matchInfo($article))->toBeTrue()
		->and($fields->matchInfo($images))->toBeFalse()
		->and($fields->matchInfo(ruleSection()))->toBeFalse()
		->and(ruleOptions($fields))->toBe(['field-body' => 'Body', 'field-summary' => 'Summary'])
		->and($count->matchInfo($article))->toBeTrue()
		->and($count->matchInfo($images))->toBeTrue()
		->and($count->matchInfo(new EntryTypeInfo($this->entryType(2, 'empty'))))->toBeFalse()
		->and($count->matchInfo(ruleSection()))->toBeFalse();
});

it('matches the type and data of a field', function () {
	$plain = new FieldInfo(new PlainText(['id' => 1]));
	$nested = new FieldInfo(new Matrix(['id' => 2]));
	$nested->isNested = true;
	$relational = new FieldInfo(new EntriesField(['id' => 3]));
	$relational->isRelational = true;
	$this->mockComponent('hub.structure', StructureService::class)->allows('getFields')->andReturn([$plain, $nested, $relational, new FieldInfo(new PlainText(['id' => 4]))]);

	$type = new FieldTypeConditionRule(['operator' => 'in', 'values' => [Matrix::class]]);
	$data = new DataKindConditionRule(['operator' => 'in', 'values' => ['relational', 'plain']]);

	expect($type->matchInfo($nested))->toBeTrue()
		->and($type->matchInfo($plain))->toBeFalse()
		->and($type->matchInfo(ruleSection()))->toBeFalse()
		->and(ruleOptions($type))->toBe([EntriesField::class => 'Entries', Matrix::class => 'Matrix', PlainText::class => 'Plain Text'])
		->and(array_map(fn ($info) => $data->matchInfo($info), [$plain, $nested, $relational]))->toBe([true, false, true])
		->and($data->matchInfo(ruleSection()))->toBeFalse()
		->and(array_keys(ruleOptions($data)))->toBe(['nested', 'relational', 'plain']);
});

it('matches the settings of a field', function () {
	$described = new FieldInfo(new PlainText(['id' => 1, 'instructions' => 'A summary.', 'searchable' => true, 'translationMethod' => Field::TRANSLATION_METHOD_SITE]));
	$bare = new FieldInfo(new PlainText(['id' => 2, 'instructions' => ' ', 'searchable' => false, 'translationMethod' => Field::TRANSLATION_METHOD_NONE]));
	$instructions = new InstructionsConditionRule(['value' => true]);
	$searchable = new SearchableConditionRule(['value' => true]);
	$translation = new TranslationMethodConditionRule(['operator' => 'in', 'values' => [Field::TRANSLATION_METHOD_SITE]]);

	expect([$instructions->matchInfo($described), $instructions->matchInfo($bare), $instructions->matchInfo(ruleSection())])->toBe([true, false, false])
		->and([$searchable->matchInfo($described), $searchable->matchInfo($bare), $searchable->matchInfo(ruleSection())])->toBe([true, false, false])
		->and([$translation->matchInfo($described), $translation->matchInfo($bare), $translation->matchInfo(ruleSection())])->toBe([true, false, false])
		->and(array_keys(ruleOptions($translation)))->toBe([
			Field::TRANSLATION_METHOD_NONE,
			Field::TRANSLATION_METHOD_SITE,
			Field::TRANSLATION_METHOD_SITE_GROUP,
			Field::TRANSLATION_METHOD_LANGUAGE,
			Field::TRANSLATION_METHOD_CUSTOM,
		]);
});

it('matches the number of layouts a field is placed in', function () {
	$info = new FieldInfo(new PlainText(['id' => 1]));
	$info->layouts = [new LayoutUsage('Entry Types', 'Article'), new LayoutUsage('Entry Types', 'Page')];
	$rule = new LayoutCountConditionRule(['operator' => '>', 'value' => 1]);

	expect($rule->matchInfo($info))->toBeTrue()
		->and($rule->matchInfo(new FieldInfo(new PlainText(['id' => 2]))))->toBeFalse()
		->and($rule->matchInfo(ruleSection()))->toBeFalse();
});
