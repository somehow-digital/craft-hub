<?php

use craft\elements\conditions\SlugConditionRule;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use craft\helpers\Json;
use SomehowDigital\Craft\Hub\conditions\rules\HasUrlsConditionRule;
use SomehowDigital\Craft\Hub\conditions\SectionCondition;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\SavedFilter;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\services\FilterService;

beforeEach(function () {
	$this->createContentModel();

	// Filters offered to everyone have no user; the others are those of user 1.
	$this->db()->respond('FROM {{%hub_filters}}', fn (string $sql) => str_contains($sql, '`userId` IS NULL') ? [
		['id' => 1, 'kind' => 'sections', 'handle' => 'withoutUrls', 'userId' => null, 'name' => 'Hidden', 'description' => null, 'icon' => null, 'params' => null, 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 1, 'uid' => 'without-urls'],
		[
			'id' => 2, 'kind' => 'sections', 'handle' => null, 'userId' => null, 'name' => 'Public', 'description' => 'With URLs.', 'icon' => 'globe', 'params' => null,
			'matchCondition' => Json::encode(['class' => SectionCondition::class, 'conditionRules' => [['class' => HasUrlsConditionRule::class, 'value' => true]]]),
			'enabled' => 1, 'sortOrder' => 2, 'uid' => 'public',
		],
		[
			'id' => 3, 'kind' => 'entries', 'handle' => null, 'userId' => null, 'name' => 'Temporary', 'description' => null, 'icon' => null, 'params' => null,
			'matchCondition' => Json::encode(['conditionRules' => [['class' => SlugConditionRule::class, 'operator' => 'bw', 'value' => '__temp']]]),
			'enabled' => 0, 'sortOrder' => 1, 'uid' => 'temporary',
		],
		['id' => 4, 'kind' => 'sections', 'handle' => 'bogus', 'userId' => null, 'name' => null, 'description' => null, 'icon' => null, 'params' => null, 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 3, 'uid' => 'bogus'],
	] : [
		['id' => 5, 'kind' => 'sections', 'handle' => null, 'userId' => 1, 'name' => 'mine', 'description' => null, 'icon' => null, 'params' => Json::encode(['type' => 'channel', 'page' => '2']), 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 0, 'uid' => 'mine'],
		['id' => 6, 'kind' => 'sections', 'handle' => null, 'userId' => 1, 'name' => 'Empty ones', 'description' => null, 'icon' => null, 'params' => Json::encode(['empty' => '1']), 'matchCondition' => null, 'enabled' => 1, 'sortOrder' => 0, 'uid' => 'empty'],
	]);

	$this->filters = new FilterService();
});

it('lists the predefined filters of each kind in their own order, configured ones and custom ones in theirs', function () {
	$sections = array_values($this->filters->getFilters(CheckKind::Sections));

	expect(array_keys($this->filters->getFilters(CheckKind::Fields)))->toBe(['fields.unused', 'fields.empty'])
		->and(array_map(fn (SavedFilter $filter) => $filter->getKey(), $sections))->toBe(['sections.empty', 'sections.stale', 'sections.withoutUrls', 'sections.public'])
		->and($sections[0]->id)->toBeNull()
		->and($sections[0]->getName())->toBe('Empty sections')
		->and($sections[0]->getListParams())->toBe(['empty' => '1'])
		->and($sections[2]->id)->toBe(1)
		->and($sections[2]->getName())->toBe('Hidden')
		->and($sections[2]->defaultName)->toBe('Sections without URLs')
		->and($sections[3]->isCustom())->toBeTrue()
		->and($sections[3]->getListParams())->toBe(['filter' => 'public']);
});

it('offers the predefined filters with their configured thresholds, or their defaults', function () {
	$this->db()->respond('FROM {{%hub_filters}}', [thresholdRow('assets', 'large', 12)]);
	$large = $this->filters->getFilter(CheckKind::Assets, 'large');

	expect($large->params)->toBe(['sizeMin' => '12'])
		->and($large->getThreshold())->toBe(12)
		->and($large->thresholdField['unit'] ?? null)->not->toBeNull()
		->and($this->filters->getThreshold(CheckKind::Assets, 'large'))->toBe(12)
		->and($this->filters->getThreshold(CheckKind::Users, 'idle'))->toBe(12)
		->and($this->filters->getThreshold(CheckKind::Users, 'locked'))->toBe(0)
		->and($this->filters->getFilter(CheckKind::Users, 'locked')->thresholdField)->toBeNull();
});

it('finds filters by the handle of a predefined one or the UID of a custom one', function () {
	expect($this->filters->getFilter(CheckKind::Sections, 'withoutUrls')?->id)->toBe(1)
		->and($this->filters->getFilter(CheckKind::Sections, 'public')?->id)->toBe(2)
		->and($this->filters->getFilter(CheckKind::Sections, 'temporary'))->toBeNull();
});

it('builds the filter of a list from its params', function () {
	$filter = $this->filters->createListFilter(CheckKind::Sections, ['type' => 'channel,bogus', 'q' => ' news ', 'filter' => 'public', 'empty' => '1']);

	expect($filter)->toBeInstanceOf(SectionFilter::class)
		->and($filter->types)->toBe(['channel'])
		->and($filter->empty)->toBeTrue()
		->and($filter->search)->toBe('news')
		->and($filter->savedFilter)->toBe('public')
		->and($this->filters->createListFilter(CheckKind::Sections, ['filter' => 'bogus'])->savedFilter)->toBeNull()
		->and($this->filters->createListFilter(CheckKind::Entries, ['status' => 'disabled']))->toBeInstanceOf(EntryFilter::class);
});

it('finds the custom filters of a kind, whether offered in the filter bar or not', function () {
	expect($this->filters->getCustomFilter(CheckKind::Sections, 'public')?->name)->toBe('Public')
		->and($this->filters->getCustomFilter(CheckKind::Entries, 'public'))->toBeNull()
		->and($this->filters->getCustomFilter(CheckKind::Entries, 'temporary')?->enabled)->toBeFalse()
		->and($this->filters->getCustomFilter(CheckKind::Sections, 'without-urls'))->toBeNull();
});

it('lists the own filters of a user by their name, with their filters only', function () {
	$own = $this->filters->getOwnFilters(CheckKind::Sections, 1);

	expect(array_map(fn (SavedFilter $filter) => $filter->name, $own))->toBe(['Empty ones', 'mine'])
		->and($own[1]->params)->toBe(['type' => 'channel'])
		->and($own[1]->isOwn())->toBeTrue()
		->and($this->filters->getOwnFilterByName(CheckKind::Sections, 1, ' MINE ')?->id)->toBe(5)
		->and($this->filters->getOwnFilterByName(CheckKind::Sections, 1, 'other'))->toBeNull()
		->and($this->db()->queriesMatching('`userId`=1'))->not->toBeEmpty();
});

it('keeps only the params of a list that are its filters', function () {
	expect($this->filters->normalizeParams([
		'type' => 'channel',
		'q' => '  news ',
		'empty' => '',
		'hubSite' => 'en',
		'site' => 'en',
		'page' => '2',
		'sort' => 'name',
		'dir' => 'desc',
		'selection' => '1',
		'bogus-name' => '1',
		'list' => ['a'],
		'activity' => 'stale,none',
	]))->toBe(['activity' => 'stale,none', 'q' => 'news', 'type' => 'channel'])
		->and($this->filters->normalizeParams('type=channel'))->toBe([]);
});

it('saves a new custom filter last in the order of its kind, with its condition', function () {
	$this->db()->respond('MAX([[sortOrder]])', [['MAX(`sortOrder`)' => 4]]);

	$filter = new SavedFilter([
		'kind' => CheckKind::Sections,
		'name' => 'Public',
		'condition' => ['class' => SectionCondition::class, 'conditionRules' => [['class' => HasUrlsConditionRule::class, 'value' => true]]],
	]);

	expect($this->filters->saveFilter($filter))->toBeTrue()
		->and($filter->uid)->not->toBeNull()
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_filters}}')[0])->toContain("'Public'")->toContain('HasUrlsConditionRule')->toContain('`userId`');
});

it('saves the own filter of a user with its filters', function () {
	$filter = new SavedFilter(['kind' => CheckKind::Sections, 'userId' => 1, 'name' => 'Channels', 'params' => ['type' => 'channel']]);

	expect($this->filters->saveFilter($filter))->toBeTrue()
		->and($filter->sortOrder)->toBe(0)
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_filters}}')[0])->toContain('{\"type\":\"channel\"}')->toContain('`matchCondition`');
});

it('saves only the name and whether a predefined filter is enabled', function () {
	$filter = $this->filters->getFilterById(1);
	$filter->name = '';
	$filter->enabled = false;
	$filter->params = ['bogus' => '1'];

	expect($this->filters->saveFilter($filter))->toBeTrue();

	$update = $this->db()->queriesMatching('UPDATE {{%hub_filters}}')[0];

	expect($update)->toContain('`name`=NULL')->toContain('`enabled`=FALSE')->not->toContain('bogus');
});

it('fails to save custom filters without a rule and own filters without filters', function () {
	$custom = new SavedFilter(['kind' => CheckKind::Entries, 'name' => 'All']);
	$own = new SavedFilter(['kind' => CheckKind::Entries, 'userId' => 1, 'name' => 'All']);
	$unnamed = new SavedFilter(['kind' => CheckKind::Entries, 'userId' => 1, 'params' => ['q' => 'news']]);

	expect($this->filters->saveFilter($custom))->toBeFalse()
		->and($custom->getErrors())->toHaveKey('condition')
		->and($this->filters->saveFilter($own))->toBeFalse()
		->and($own->getErrors())->toHaveKey('params')
		->and($this->filters->saveFilter($unnamed))->toBeFalse()
		->and($unnamed->getErrors())->toHaveKey('name')
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_filters}}'))->toBe([]);
});

it('deletes custom filters, or the own ones of a user', function () {
	$this->filters->deleteFilterById(2);
	$this->filters->deleteFilterById(5, 1);
	$deletes = $this->db()->queriesMatching('DELETE FROM {{%hub_filters}}');

	expect($deletes[0])->toContain('`id`=2')->toContain('`handle` IS NULL')->toContain('`userId` IS NULL')
		->and($deletes[1])->toContain('`id`=5')->toContain('`userId`=1');
});

it('reorders the filters offered to everyone and gives the predefined ones a row', function () {
	$this->filters->reorderFilters([2, 1]);
	$this->filters->saveDefaults();

	expect($this->db()->queriesMatching('UPDATE {{%hub_filters}}')[0])->toContain('`sortOrder`=1')->toContain('`id`=2')->toContain('`userId` IS NULL')
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_filters}}'))->toHaveCount(28);
});

it('narrows the elements to those a custom filter matches, on the selected sites', function () {
	$this->db()->respond('FROM {{%hub_filters}}', [[
		'id' => 3, 'kind' => 'entries', 'handle' => null, 'userId' => null, 'name' => 'Temporary', 'description' => null, 'icon' => null, 'params' => null,
		'matchCondition' => Json::encode(['conditionRules' => [['class' => SlugConditionRule::class, 'operator' => 'bw', 'value' => '__temp']]]),
		'enabled' => 1, 'sortOrder' => 1, 'uid' => 'temporary',
	]]);
	Hub::getInstance()->set('filters', $this->filters);

	$filter = new EntryFilter();
	$filter->savedFilter = 'temporary';
	$conditions = conditions(Hub::getInstance()->entries->getQuery($filter));
	$matching = end($conditions)['elements.id'];

	expect($matching)->toBeInstanceOf(ElementQuery::class)
		->and($matching->slug)->toBe('__temp*')
		->and($matching->siteId)->toBe([1, 2])
		->and(array_values($matching->select))->toBe(['elements.id'])
		->and($this->filters->elementCondition(CheckKind::Entries, 'bogus'))->toBe(['elements.id' => 0])
		->and($this->filters->elementCondition(CheckKind::Sections, 'temporary'))->toBe(['elements.id' => 0]);
});

it('filters the structure lists by the condition of a custom filter', function () {
	Hub::getInstance()->set('filters', $this->filters);

	$public = new SectionFilter();
	$public->savedFilter = 'public';
	$gone = new SectionFilter();
	$gone->savedFilter = 'bogus';

	expect(array_values(array_map(fn ($info) => $info->section->handle, Hub::getInstance()->lists->getSections($public))))->toBe(['news', 'pages'])
		->and(Hub::getInstance()->lists->getSections($gone))->toBe([])
		->and($this->filters->infoCondition(CheckKind::Sections, null))->toBeNull()
		->and($this->filters->infoCondition(CheckKind::Sections, 'bogus'))->toBeFalse();
});

it('lists the saved filters of a list for its filter bar', function () {
	$this->request();
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));

	$menu = $this->filters->getMenu(CheckKind::Sections, 'hub/structure/sections', 'public');

	expect($menu['kind'])->toBe('sections')
		->and($menu['current'])->toBe('public')
		->and(array_column($menu['shared'], 'name'))->toBe(['Empty sections', 'Stale sections', 'Hidden', 'Public'])
		->and($menu['shared'][3]['params'])->toBe(['filter' => 'public'])
		->and($menu['shared'][3]['custom'])->toBe('public')
		->and(array_column($menu['own'], 'id'))->toBe([6, 5])
		->and($menu['canManage'])->toBeTrue()
		->and($menu['manageUrl'])->toContain('#hub-filters-sections');
});
