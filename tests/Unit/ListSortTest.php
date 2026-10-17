<?php

use SomehowDigital\Craft\Hub\helpers\ListSort;

$columns = ['name' => SORT_ASC, 'entries' => SORT_DESC];

it('sorts by the column asked for, in its natural direction unless told otherwise', function (array $params, string $column, int $direction) use ($columns) {
	$sort = new ListSort($columns, 'name', $params);

	expect($sort->column)->toBe($column)
		->and($sort->direction)->toBe($direction);
})->with([
	'default' => [[], 'name', SORT_ASC],
	'natural direction' => [['sort' => 'entries'], 'entries', SORT_DESC],
	'reversed' => [['sort' => 'entries', 'dir' => 'asc'], 'entries', SORT_ASC],
	'unknown column and direction' => [['sort' => 'bogus', 'dir' => 'sideways'], 'name', SORT_ASC],
	'malformed params' => [['sort' => ['entries'], 'dir' => ['asc']], 'name', SORT_ASC],
]);

it('keeps only the sorting differing from the default as hidden fields', function (array $params, ?string $sort, ?string $dir) use ($columns) {
	$variables = (new ListSort($columns, 'name', $params))->variables('hub/structure/sections');

	expect($variables['sort'])->toBe($sort)
		->and($variables['dir'])->toBe($dir);
})->with([
	'default' => [[], null, null],
	'default reversed' => [['dir' => 'desc'], 'name', 'desc'],
	'other column' => [['sort' => 'entries'], 'entries', null],
	'other column reversed' => [['sort' => 'entries', 'dir' => 'asc'], 'entries', 'asc'],
]);

it('links each header to its next sorting, keeping the filters but not the page', function () use ($columns) {
	$headers = (new ListSort($columns, 'name', ['q' => 'news', 'page' => '3']))->variables('hub/structure/sections')['sortHeaders'];

	expect($headers['name']['direction'])->toBe('asc')
		->and($headers['name']['url'])->toContain('dir=desc')
		->and($headers['entries']['direction'])->toBeNull()
		->and($headers['entries']['url'])->toContain('sort=entries')->toContain('q=news')->not->toContain('page=');
});

it('resets a reversed column, keeping the default sort in URLs left without any other params', function () use ($columns) {
	$reversed = fn (array $params) => (new ListSort($columns, 'name', $params))->variables('hub/structure/sections')['sortHeaders']['entries']['url'];

	expect($reversed(['sort' => 'entries', 'dir' => 'asc']))->toContain('sort=name')->not->toContain('dir=')
		->and($reversed(['sort' => 'entries', 'dir' => 'asc', 'q' => 'news']))->toContain('q=news')->not->toContain('sort=');
});
