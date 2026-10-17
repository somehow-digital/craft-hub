<?php

use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\services\DiagnosticsService;
use SomehowDigital\Craft\Hub\Tests\Support\ElementQueries;

beforeEach(function () {
	$this->createContentModel();

	$this->diagnostics = new DiagnosticsService();
	$this->hub()->set('diagnostics', $this->diagnostics);
});

/**
 * A row of a custom check of a kind, flagging the items of the filter with the handle or UID.
 *
 * @return array<string,mixed>
 */
function customCheckRow(int $id, string $kind, string $filter, array $row = []): array
{
	return [
		'id' => $id,
		'kind' => $kind,
		'handle' => null,
		'filter' => $filter,
		'name' => 'Custom',
		'description' => 'Flagged.',
		'passedDescription' => null,
		'icon' => null,
		'severity' => 'warning',
		'enabled' => 1,
		'sortOrder' => 9,
		'uid' => "check-$id",
		...$row,
	];
}

it('lists the default checks of each kind in their order, each of the predefined filter of its handle', function () {
	$definitions = $this->diagnostics->getDefinitions(CheckKind::Users);

	expect(array_keys($definitions))->toBe(['users.locked', 'users.adminsWithoutTwoFactor', 'users.idle', 'users.pending', 'users.suspended', 'users.unverifiedEmail'])
		->and($definitions['users.locked']->isDefault())->toBeTrue()
		->and($definitions['users.locked']->enabled)->toBeTrue()
		->and($definitions['users.locked']->id)->toBeNull()
		->and($definitions['users.locked']->getSeverity())->toBe(Severity::Warning)
		->and($definitions['users.locked']->getFilter()?->getKey())->toBe('users.locked')
		->and($definitions['users.locked']->getIcon())->toBe('lock')
		->and(array_keys($this->diagnostics->getDefinitions()))->toHaveCount(20);
});

it('describes the default checks by the thresholds of their filters', function () {
	$this->db()->respond('FROM {{%hub_filters}}', [thresholdRow('entries', 'stale', 3)]);

	$check = $this->diagnostics->getDefinition('entries.stale');

	expect($check->description)->toContain('3 months')
		->and($check->getListName())->toBe('Stale entries')
		->and($check->getListDescription())->toBe('Entries no person has edited for 3 months.');
});

it('lists the checks as configured, default checks without a row first', function () {
	$this->db()->respond('FROM {{%hub_checks}}', [
		['id' => 1, 'kind' => 'sections', 'handle' => 'empty', 'filter' => null, 'severity' => 'error', 'enabled' => 0, 'sortOrder' => 3, 'uid' => 'empty'],
		customCheckRow(2, 'sections', 'withoutUrls', ['sortOrder' => 2]),
		customCheckRow(3, 'sections', 'deleted', ['sortOrder' => 1]),
		['id' => 4, 'kind' => 'sections', 'handle' => 'removed', 'filter' => null, 'severity' => null, 'enabled' => 1, 'sortOrder' => 4, 'uid' => 'removed'],
		customCheckRow(5, 'unknown', 'empty'),
	]);

	$definitions = $this->diagnostics->getDefinitions(CheckKind::Sections);
	$custom = $definitions['sections.check-2'];

	expect(array_keys($definitions))->toBe(['sections.check-3', 'sections.check-2', 'sections.empty'])
		->and($definitions['sections.empty']->getSeverity())->toBe(Severity::Error)
		->and($definitions['sections.empty']->name)->toBe('Entries')
		->and($custom->isDefault())->toBeFalse()
		->and($custom->getFilter()?->getName())->toBe('Sections without URLs')
		->and($custom->getIcon())->toBe('globe')
		->and($custom->getListName())->toBe('Custom')
		->and($this->diagnostics->getDefinitionById(2))->toBe($custom)
		// Disabled checks and checks of filters that no longer exist are left out.
		->and($this->diagnostics->getEnabledChecks(CheckKind::Sections))->toBe([$custom]);
});

it('finds the custom checks of a filter', function () {
	$this->db()->respond('FROM {{%hub_checks}}', [
		customCheckRow(2, 'sections', 'withoutUrls'),
		customCheckRow(3, 'volumes', 'withoutUrls'),
	]);
	$filters = $this->hub()->filters;

	expect(array_map(fn (CheckDefinition $check) => $check->id, $this->diagnostics->getChecksOfFilter($filters->getFilter(CheckKind::Sections, 'withoutUrls'))))->toBe([2])
		->and($this->diagnostics->getChecksOfFilter($filters->getFilter(CheckKind::Sections, 'empty')))->toBe([]);
});

it('sorts failed checks first, by severity, then those of the kind before those of the items it holds', function () {
	$item = fn (string $key, bool $passed, Severity $severity) => compact('key', 'passed', 'severity');

	$items = $this->diagnostics->sort([
		$item('entries.stale', false, Severity::Notice),
		$item('sections.empty', true, Severity::Warning),
		$item('entries.drafts', false, Severity::Warning),
		$item('sections.unknown', false, Severity::Notice),
		$item('entries.disabled', true, Severity::Notice),
	], CheckKind::Sections, fn (array $item) => [$item['key'], $item['passed'], $item['severity']]);

	expect(array_column($items, 'key'))->toBe(['entries.drafts', 'sections.unknown', 'entries.stale', 'sections.empty', 'entries.disabled']);
});

it('flags the items of a structure page its filter matches', function () {
	$check = $this->diagnostics->getDefinition('sections.empty');

	expect($this->diagnostics->fails($check, 4))->toBeTrue()
		->and($this->diagnostics->fails($check, 1))->toBeFalse()
		->and($this->diagnostics->countFailing($check))->toBe(1)
		->and($this->diagnostics->getFailingQuery($check))->toBeNull();
});

it('flags the elements its filter matches', function () {
	ElementQueries::respond(Entry::class, fn (ElementQuery $query) => $query->status === ['disabled'] ? [new Entry(['id' => 5, 'siteId' => 1])] : []);

	$check = $this->diagnostics->getDefinition('entries.disabled');

	expect($this->diagnostics->fails($check, 5))->toBeTrue()
		->and(ElementQueries::executed(Entry::class)[0]->id)->toBe([5])
		->and($this->diagnostics->fails($this->diagnostics->getDefinition('entries.drafts'), 5))->toBeFalse();
});

it('counts the elements a check flags for all sections and entry types at once', function () {
	$this->db()->respondToCounts(fn () => [
		['sectionId' => 1, 'typeId' => 10, 'total' => 3],
		['sectionId' => 2, 'typeId' => 11, 'total' => 2],
	]);
	$check = $this->diagnostics->getDefinition('entries.drafts');

	expect($this->diagnostics->countFailing($check))->toBe(5)
		->and($this->diagnostics->countFailing($check, ['sectionId' => 1]))->toBe(3)
		->and($this->diagnostics->countFailing($check, ['typeId' => 11]))->toBe(2)
		->and($this->db()->counts())->toHaveCount(1)
		->and(conditions($this->diagnostics->getFailingQuery($check)))->not->toBe([]);
});

it('saves a new custom check last in the order of its kind', function () {
	$this->db()->respond('MAX([[sortOrder]])', 4);

	$check = new CheckDefinition([
		'kind' => CheckKind::Sections,
		'filter' => 'withoutUrls',
		'name' => 'Public',
		'description' => 'This section has no URLs.',
		'severity' => Severity::Warning,
	]);

	expect($this->diagnostics->saveDefinition($check))->toBeTrue()
		->and($check->sortOrder)->toBe(5)
		->and($check->uid)->not->toBeNull()
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_checks}}')[0])->toContain("'Public'")->toContain("'warning'")->toContain("'sections'")->toContain("'withoutUrls'");
});

it('rejects custom checks without a name, description or filter of their kind', function () {
	$check = new CheckDefinition(['kind' => CheckKind::Entries, 'name' => ' ', 'filter' => 'withoutUrls']);

	expect($this->diagnostics->saveDefinition($check))->toBeFalse()
		->and(array_keys($check->getErrors()))->toBe(['name', 'description', 'filter'])
		->and($this->db()->queriesMatching('INSERT INTO'))->toBe([]);
});

it('only saves whether a default check is enabled and its severity', function () {
	$this->db()->respond('FROM {{%hub_checks}}', [
		['id' => 1, 'kind' => 'fields', 'handle' => 'unused', 'filter' => null, 'severity' => null, 'enabled' => 1, 'sortOrder' => 1, 'uid' => 'unused'],
	]);
	$check = $this->diagnostics->getDefinitionById(1);
	$check->enabled = false;
	$check->severity = Severity::Warning;

	expect($this->diagnostics->saveDefinition($check))->toBeTrue()
		->and($this->db()->queriesMatching('UPDATE {{%hub_checks}}')[0])
		->toContain("`enabled`=FALSE")->toContain("`severity`='warning'")->not->toContain('`name`')->not->toContain('`filter`');
});

it('deletes only custom checks', function () {
	$this->diagnostics->deleteDefinitionById(3);

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_checks}}')[0])->toContain('`id`=3')->toContain('`handle` IS NULL');
});

it('gives the default checks without a row one, numbering the checks of each kind', function () {
	$this->db()->respond('FROM {{%hub_checks}}', [
		['id' => 1, 'kind' => 'fields', 'handle' => 'empty', 'filter' => null, 'severity' => null, 'enabled' => 1, 'sortOrder' => 1, 'uid' => 'empty'],
	]);

	$this->diagnostics->saveDefaults();
	$inserts = $this->db()->queriesMatching('INSERT INTO {{%hub_checks}}');

	expect($inserts)->toHaveCount(19)
		->and(implode("\n", $inserts))->toContain("'fields', 'unused', TRUE, 1")
		->and($this->db()->queriesMatching('UPDATE {{%hub_checks}}')[0])->toContain('`sortOrder`=2')->toContain('`id`=1');
});
