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
	ColumnService::LIST_USERS,
	ColumnService::LIST_USER_GROUPS,
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
		'settings' => true,
	])->and($this->columns->getColumns(ColumnService::LIST_ENTRIES)[9])->toMatchArray([
		'key' => 'url',
		'class' => 'hub-visit-cell',
		'labelHidden' => true,
	]);
});

it('shows the columns in the order the user chose, hiding theirs', function () {
	$this->columns->saveColumns(ColumnService::LIST_FIELDS, ['status', 'type', 'bogus', 'type'], ['type', 'bogus']);

	expect(columnsOf($this->columns, ColumnService::LIST_FIELDS))->toBe([
		'status' => true,
		'layouts' => true,
		'data' => true,
		'type' => false,
		'settings' => true,
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
		'settings' => true,
	]);
});

it('ignores malformed preferences', function (mixed $value) {
	$this->preferences[1] = [ColumnService::PREFERENCE => $value];

	expect(array_keys(columnsOf($this->columns, ColumnService::LIST_FIELDS)))->toBe(['type', 'layouts', 'data', 'status', 'settings']);
})->with([
	'not a map' => 'columns',
	'not lists' => [[ColumnService::LIST_FIELDS => ['order' => 'status', 'hidden' => 1]]],
	'not strings' => [[ColumnService::LIST_FIELDS => ['order' => [1, ['status']], 'hidden' => [null]]]],
]);

it('leaves out the columns a page omits', function () {
	expect(array_keys(columnsOf($this->columns, ColumnService::LIST_ENTRIES, ['section', 'field', 'owner', 'type'])))
		->toBe(['status', 'creator', 'references', 'posted', 'updated', 'url', 'edit']);
});

it('keeps the place of omitted columns when saving the others', function () {
	$this->columns->saveColumns(ColumnService::LIST_FILES, ['volume', 'size', 'kind', 'dimensions', 'references', 'uploaded', 'uploader'], ['volume']);

	// On a volume's page, its column is omitted.
	$this->columns->saveColumns(ColumnService::LIST_FILES, ['uploader', 'size', 'kind', 'dimensions', 'references', 'uploaded'], ['uploader']);

	expect(columnsOf($this->columns, ColumnService::LIST_FILES))->toBe([
		'volume' => false,
		'uploader' => false,
		'size' => true,
		'kind' => true,
		'dimensions' => true,
		'references' => true,
		'uploaded' => true,
		'url' => true,
		'edit' => true,
	]);
});

it('always shows the fixed columns last, whatever the user chose', function () {
	$this->preferences[1] = [ColumnService::PREFERENCE => [
		ColumnService::LIST_ENTRIES => ['order' => ['edit', 'url', 'status'], 'hidden' => ['edit', 'url']],
	]];

	$columns = columnsOf($this->columns, ColumnService::LIST_ENTRIES);

	expect(array_slice($columns, -2))->toBe(['url' => true, 'edit' => true])
		->and(array_key_first($columns))->toBe('status')
		->and(array_column($this->columns->getColumns(ColumnService::LIST_ENTRIES), 'fixed', 'key'))->toMatchArray(['status' => false, 'url' => true, 'edit' => true]);
});

it('keeps no order or hidden state of the fixed columns', function () {
	$this->columns->saveColumns(ColumnService::LIST_USERS, ['edit', 'email', 'status'], ['edit']);

	expect($this->preferences[1][ColumnService::PREFERENCE][ColumnService::LIST_USERS])->toBe([
		'order' => ['email', 'status', 'groups', 'entries', 'twoFactor', 'login', 'created'],
		'hidden' => [],
	]);
});

it('keeps the columns of each list in the user preferences, next to the other preferences', function () {
	$this->preferences[1] = ['language' => 'de'];

	$this->columns->saveColumns(ColumnService::LIST_SECTIONS, ['uri'], ['uri']);
	$this->columns->saveColumns(ColumnService::LIST_VOLUMES, ['alt'], []);

	expect($this->preferences[1]['language'])->toBe('de')
		->and($this->preferences[1][ColumnService::PREFERENCE][ColumnService::LIST_SECTIONS])->toBe([
			'order' => ['type', 'entryTypes', 'uri', 'entries', 'references', 'updated'],
			'hidden' => ['uri'],
		])
		->and($this->preferences[1][ColumnService::PREFERENCE])->toHaveKey(ColumnService::LIST_VOLUMES);
});

it('resets the columns of a list', function () {
	$this->columns->saveColumns(ColumnService::LIST_SECTIONS, ['updated', 'type'], ['type']);
	$this->columns->saveColumns(ColumnService::LIST_FIELDS, ['status', 'type'], []);
	$this->columns->resetColumns(ColumnService::LIST_SECTIONS);

	expect(array_keys($this->preferences[1][ColumnService::PREFERENCE]))->toBe([ColumnService::LIST_FIELDS])
		->and(columnsOf($this->columns, ColumnService::LIST_SECTIONS))->each->toBeTrue();
});

it('shows the default columns and keeps none without a signed-in user', function () {
	$this->identity = null;
	$this->columns->saveColumns(ColumnService::LIST_FIELDS, ['status'], ['status']);

	expect($this->preferences)->toBe([])
		->and(columnsOf($this->columns, ColumnService::LIST_FIELDS))->each->toBeTrue();
});

it('names the columns the user shows', function () {
	$this->columns->saveColumns(ColumnService::LIST_FIELDS, ['status', 'type'], ['type']);

	expect($this->columns->getShownKeys($this->columns->getColumns(ColumnService::LIST_FIELDS)))->toBe(['status', 'layouts', 'data', 'settings']);
});

it('leaves out the settings column for users who can\'t open the settings', function () {
	$this->isAdmin = false;

	expect(array_keys(columnsOf($this->columns, ColumnService::LIST_SECTIONS)))->not->toContain('settings')
		->and(array_keys(columnsOf($this->columns, ColumnService::LIST_USER_GROUPS)))->not->toContain('settings');
});
