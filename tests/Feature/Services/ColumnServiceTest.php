<?php

use craft\elements\User;
use SomehowDigital\Craft\Hub\services\ColumnService;

beforeEach(function () {
	$this->identity = new User(['id' => 1]);
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturnUsing(fn () => $this->identity);
	$this->columns = new ColumnService();
});

/**
 * @return array<string,bool> visibility of the columns, in their order
 */
function columnsOf(ColumnService $columns, string $list, array $omit = []): array
{
	return array_column($columns->getColumns($list, $omit), 'visible', 'key');
}

it('defines the columns of each list', function (string $list) {
	expect($this->columns->getDefinitions($list))->not->toBeEmpty();
})->with([
	ColumnService::LIST_SECTIONS,
	ColumnService::LIST_ENTRY_TYPES,
	ColumnService::LIST_FIELDS,
	ColumnService::LIST_VOLUMES,
	ColumnService::LIST_ENTRIES,
	ColumnService::LIST_FILES,
]);

it('has no columns for unknown lists', function () {
	expect($this->columns->getDefinitions('bogus'))->toBe([])
		->and($this->columns->getColumns('bogus'))->toBe([]);
});

it('shows all columns in their default order', function () {
	expect(columnsOf($this->columns, ColumnService::LIST_FIELDS))->toBe([
		'type' => true,
		'layouts' => true,
		'data' => true,
		'status' => true,
		'diagnostics' => true,
	])->and($this->columns->getColumns(ColumnService::LIST_ENTRIES)[9])->toMatchArray([
		'key' => 'url',
		'class' => 'hub-visit-cell',
		'labelHidden' => true,
	]);
});

it('shows the columns in the order the user chose, hiding theirs', function () {
	$this->columns->saveColumns(ColumnService::LIST_FIELDS, ['diagnostics', 'type', 'bogus', 'type'], ['type', 'bogus']);

	expect(columnsOf($this->columns, ColumnService::LIST_FIELDS))->toBe([
		'diagnostics' => true,
		'layouts' => true,
		'data' => true,
		'status' => true,
		'type' => false,
	]);
});

it('shows columns added since the user chose theirs after them', function () {
	$this->preferences[1] = [ColumnService::PREFERENCE => [
		ColumnService::LIST_FIELDS => ['order' => ['status', 'removed', 'type'], 'hidden' => ['type', 'removed']],
	]];

	expect(columnsOf($this->columns, ColumnService::LIST_FIELDS))->toBe([
		'status' => true,
		'type' => false,
		'layouts' => true,
		'data' => true,
		'diagnostics' => true,
	]);
});

it('ignores malformed preferences', function (mixed $value) {
	$this->preferences[1] = [ColumnService::PREFERENCE => $value];

	expect(array_keys(columnsOf($this->columns, ColumnService::LIST_FIELDS)))->toBe(['type', 'layouts', 'data', 'status', 'diagnostics']);
})->with([
	'not a map' => 'columns',
	'not lists' => [[ColumnService::LIST_FIELDS => ['order' => 'status', 'hidden' => 1]]],
	'not strings' => [[ColumnService::LIST_FIELDS => ['order' => [1, ['status']], 'hidden' => [null]]]],
]);

it('leaves out the columns a page omits', function () {
	expect(array_keys(columnsOf($this->columns, ColumnService::LIST_ENTRIES, ['section', 'field', 'owner', 'type'])))
		->toBe(['status', 'references', 'posted', 'updated', 'diagnostics', 'url']);
});

it('keeps the place of omitted columns when saving the others', function () {
	$this->columns->saveColumns(ColumnService::LIST_FILES, ['volume', 'size', 'kind', 'dimensions', 'references', 'uploaded', 'diagnostics', 'url'], ['volume']);

	// On a volume's page, its column is omitted.
	$this->columns->saveColumns(ColumnService::LIST_FILES, ['url', 'size', 'kind', 'dimensions', 'references', 'uploaded', 'diagnostics'], ['url']);

	expect(columnsOf($this->columns, ColumnService::LIST_FILES))->toBe([
		'volume' => false,
		'url' => false,
		'size' => true,
		'kind' => true,
		'dimensions' => true,
		'references' => true,
		'uploaded' => true,
		'diagnostics' => true,
	]);
});

it('keeps the columns of each list in the user preferences, next to the other preferences', function () {
	$this->preferences[1] = ['language' => 'de'];

	$this->columns->saveColumns(ColumnService::LIST_SECTIONS, ['uri'], ['uri']);
	$this->columns->saveColumns(ColumnService::LIST_VOLUMES, ['alt'], []);

	expect($this->preferences[1]['language'])->toBe('de')
		->and($this->preferences[1][ColumnService::PREFERENCE][ColumnService::LIST_SECTIONS])->toBe([
			'order' => ['type', 'entryTypes', 'uri', 'entries', 'references', 'updated', 'diagnostics'],
			'hidden' => ['uri'],
		])
		->and($this->preferences[1][ColumnService::PREFERENCE])->toHaveKey(ColumnService::LIST_VOLUMES);
});

it('resets the columns of a list', function () {
	$this->columns->saveColumns(ColumnService::LIST_SECTIONS, ['diagnostics', 'type'], ['type']);
	$this->columns->saveColumns(ColumnService::LIST_FIELDS, ['diagnostics', 'type'], []);
	$this->columns->resetColumns(ColumnService::LIST_SECTIONS);

	expect(array_keys($this->preferences[1][ColumnService::PREFERENCE]))->toBe([ColumnService::LIST_FIELDS])
		->and(columnsOf($this->columns, ColumnService::LIST_SECTIONS))->each->toBeTrue();
});

it('shows the default columns and keeps none without a signed-in user', function () {
	$this->identity = null;
	$this->columns->saveColumns(ColumnService::LIST_FIELDS, ['diagnostics'], ['diagnostics']);

	expect($this->preferences)->toBe([])
		->and(columnsOf($this->columns, ColumnService::LIST_FIELDS))->each->toBeTrue();
});

it('names the columns the user shows', function () {
	$this->columns->saveColumns(ColumnService::LIST_FIELDS, ['diagnostics', 'type'], ['type']);

	expect($this->columns->getShownKeys($this->columns->getColumns(ColumnService::LIST_FIELDS)))->toBe(['diagnostics', 'layouts', 'data', 'status']);
});
