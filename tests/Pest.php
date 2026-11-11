<?php

declare(strict_types=1);

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\ArrayHelper;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use DateTimeInterface;
use LogicException;
use Mockery\MockInterface;
use Pest\TestSuite;
use ReflectionProperty;
use SomehowDigital\Craft\Hub\db\Tables;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\Tests\TestCase;
use Stringable;
use yii\db\ExpressionInterface;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

/** The condition by which the queries of the filters select the predefined ones, which belong to no user. */
const GLOBAL_FILTERS = '`userId` IS NULL';

/**
 * What is at the path of keys and properties in nested arrays and objects, to expect on, as the shape of what the actions return isn't typed.
 */
function at(mixed $value, string|int ...$path): mixed
{
	foreach ($path as $key) {
		if (is_object($value)) {
			$value = ArrayHelper::getValue($value, (string) $key);

			continue;
		}

		if (!is_array($value) || !array_key_exists($key, $value)) {
			throw new LogicException("No {$key} in the value.");
		}

		$value = $value[$key];
	}

	return $value;
}

/**
 * Like {@see at()}, for a list or map.
 *
 * @return array<mixed>
 */
function arrayAt(mixed $value, string|int ...$path): array
{
	$value = at($value, ...$path);
	if (!is_array($value)) {
		throw new LogicException('Not a list.');
	}

	return $value;
}

/**
 * Like {@see at()}, for a string.
 */
function stringAt(mixed $value, string|int ...$path): string
{
	$value = at($value, ...$path);
	if (!is_string($value)) {
		throw new LogicException('Not a string.');
	}

	return $value;
}

/**
 * Like {@see at()}, for an object of a class.
 *
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
function objectAt(string $class, mixed $value, string|int ...$path): object
{
	$value = at($value, ...$path);
	if (!$value instanceof $class) {
		throw new LogicException("Not a {$class}.");
	}

	return $value;
}

/**
 * Like {@see objectAt()}, for a list of objects of a class.
 *
 * @template T of object
 * @param class-string<T> $class
 * @return list<T>
 */
function objectsAt(string $class, mixed $value, string|int ...$path): array
{
	$objects = [];

	foreach (arrayAt($value, ...$path) as $object) {
		if (!$object instanceof $class) {
			throw new LogicException("Not a {$class}.");
		}

		$objects[] = $object;
	}

	return $objects;
}

/**
 * What a query takes as one value or a list of them, as a list.
 *
 * @return array<mixed>
 */
function listOf(mixed $value): array
{
	return is_array($value) ? $value : ($value === null ? [] : [$value]);
}

/**
 * Like {@see listOf()}, for the IDs a query selects by.
 *
 * @return list<int>
 */
function idsOf(mixed $value): array
{
	return array_values(array_filter(listOf($value), is_int(...)));
}

/**
 * The request of the app, which the tests run as a web one.
 */
function webRequest(): WebRequest
{
	$request = Craft::$app->getRequest();
	if (!$request instanceof WebRequest) {
		throw new LogicException('Not a web request.');
	}

	return $request;
}

/**
 * Sets the page of the request, as its URL would, which Craft keeps privately.
 */
function setPageNumber(int $page): void
{
	(new ReflectionProperty(WebRequest::class, '_pageNum'))->setValue(webRequest(), $page);
}

/**
 * The response of the app, which the tests run as a web one.
 */
function webResponse(): WebResponse
{
	$response = Craft::$app->getResponse();
	if (!$response instanceof WebResponse) {
		throw new LogicException('Not a web response.');
	}

	return $response;
}

/**
 * The strings of a list of values, as dataset values aren't typed.
 *
 * @param array<mixed> $values
 * @return list<string>
 */
function strings(array $values): array
{
	return array_values(array_filter($values, is_string(...)));
}

/**
 * The properties of an object, or the items of an array.
 *
 * @return array<mixed>
 */
function propertiesOf(mixed $value): array
{
	return is_object($value) ? get_object_vars($value) : (is_array($value) ? $value : throw new LogicException('Not an object.'));
}

/**
 * A value that is a date.
 */
function asDateTime(mixed $value): DateTimeInterface
{
	return $value instanceof DateTimeInterface ? $value : throw new LogicException('Not a date.');
}

/**
 * The last item of a list.
 */
function lastOf(mixed $value): mixed
{
	$items = listOf($value);

	return $items[array_key_last($items) ?? throw new LogicException('Empty list.')];
}

/**
 * The items of an array under string keys, as datasets give them.
 *
 * @param array<mixed> $values
 * @return array<string,mixed>
 */
function keyed(array $values): array
{
	return array_combine(array_map(strval(...), array_keys($values)), $values);
}

/**
 * The test being run, for the helpers that aren't methods of it.
 */
function currentTest(): TestCase
{
	$test = TestSuite::getInstance()->test;

	return $test instanceof TestCase ? $test : throw new LogicException('No test is run.');
}

/**
 * A value that is expected to exist.
 *
 * @template T
 * @param T|null $value
 * @return T
 */
function present(mixed $value): mixed
{
	return $value ?? throw new LogicException('Not found.');
}

/**
 * The parts of a value that is a list, to destructure.
 *
 * @return list<mixed>
 */
function parts(mixed $value): array
{
	return is_array($value) ? array_values($value) : throw new LogicException('Not a list.');
}

/**
 * Where a text is first found in another one.
 */
function positionOf(string $haystack, string $needle): int
{
	$position = strpos($haystack, $needle);

	return $position === false ? throw new LogicException("{$needle} not found.") : $position;
}

/**
 * A value that is an integer.
 */
function asInt(mixed $value): int
{
	return is_int($value) ? $value : throw new LogicException('Not an integer.');
}

/**
 * A value that is a string.
 */
function asString(mixed $value): string
{
	return is_string($value) || $value instanceof Stringable ? (string) $value : throw new LogicException('Not a string.');
}

/**
 * A service of the app that the tests replaced with a mock, to set what it answers.
 *
 * @template T of object
 * @param T $service
 * @return T&MockInterface
 */
function mocked(object $service): MockInterface
{
	if (!$service instanceof MockInterface) {
		throw new LogicException('Not a mock.');
	}

	return $service;
}

/**
 * The start of the SQL of the queries that select from a table, to answer or find them by.
 */
function sqlFrom(string $table): string
{
	return 'FROM ' . $table;
}

/**
 * The start of the SQL of the queries that delete from a table.
 */
function sqlDeleteFrom(string $table): string
{
	return 'DELETE ' . sqlFrom($table);
}

/**
 * The start of the SQL of the queries that insert into a table.
 */
function sqlInsertInto(string $table): string
{
	return 'INSERT INTO ' . $table;
}

/**
 * The start of the SQL of the queries that update a table.
 */
function sqlUpdate(string $table): string
{
	return 'UPDATE ' . $table;
}

/**
 * The SQL of a query, with its params filled in.
 */
function rawSql(mixed $query): string
{
	if (!$query instanceof Query) {
		throw new LogicException('Not a query.');
	}

	return $query->createCommand()->getRawSql();
}

/**
 * Raw SQL of the `WHERE` clause a condition builds.
 *
 * @param array<int|string,mixed>|string|ExpressionInterface|null $condition
 */
function conditionSql(array|string|ExpressionInterface|null $condition): string
{
	return rawSql((new Query())->from(['elements' => 'elements'])->where($condition ?? []));
}

/**
 * The conditions a query was narrowed by, apart from the ones of the element query itself.
 *
 * @param ElementQuery<array-key,covariant ElementInterface>|null $query
 * @return list<mixed>
 */
function conditions(?ElementQuery $query): array
{
	$query ?? throw new LogicException('No query.');

	return is_array($query->where) && ($query->where[0] ?? null) === 'and' ? array_values(array_slice($query->where, 1)) : [$query->where];
}

/**
 * A row of a predefined filter of a kind, configured with a threshold.
 *
 * @return array<string,mixed>
 */
function thresholdRow(string $kind, string $handle, int $threshold): array
{
	return [
		'id' => crc32("{$kind}.{$handle}"),
		'kind' => $kind,
		'handle' => $handle,
		'userId' => null,
		'name' => null,
		'description' => null,
		'icon' => null,
		'params' => null,
		'matchCondition' => null,
		'threshold' => $threshold,
		'enabled' => 1,
		'sortOrder' => 1,
		'uid' => "{$kind}.{$handle}",
	];
}

/**
 * Cards of a statistics tab with their data loaded, as their panels load them once opened.
 *
 * @param mixed $cards see BaseSummary::lazyCard()
 * @return list<array<mixed>>
 */
function loadCards(mixed $cards): array
{
	if (!is_array($cards)) {
		throw new LogicException('No cards.');
	}

	return array_values(array_map(function (mixed $card): array {
		if (!is_array($card) || !is_callable($card['load'] ?? null)) {
			throw new LogicException('No lazy card.');
		}

		$loaded = ($card['load'])();
		if (!is_array($loaded)) {
			throw new LogicException('No card data.');
		}

		return [...$card, ...$loaded];
	}, $cards));
}

/**
 * Snapshots with as many sections as their ID, and the earliest of them, unless another one is given.
 *
 * @param array<mixed> $snapshots pairs of the ID and date of a snapshot
 * @param array<string,mixed>|null $metrics the metrics of every snapshot, instead of the sections they have
 */
function respondSnapshots(TestCase $test, array $snapshots, ?string $earliest = null, ?array $metrics = null): void
{
	$rows = array_values(array_map(fn(mixed $snapshot): array => [asInt(at($snapshot, 0)), asString(at($snapshot, 1))], $snapshots));

	$test->db()->respond('FROM {{%hub_snapshots}}', array_map(fn(array $row): array => ['id' => $row[0], 'date' => $row[1]], $rows));
	$test->db()->respond('SELECT `id`, `metrics`', array_map(fn(array $row): array => [
		'id' => $row[0],
		'metrics' => json_encode($metrics ?? ['sections' => array_fill(1, $row[0], ['type' => 'channel']), 'users' => ['total' => $row[0] * 2]]),
	], $rows));
	$test->db()->respond('MIN(`date`)', $earliest ?? ($rows[0][1] ?? null));
}

/**
 * A condition rule, created with its dependencies like Craft creates them.
 *
 * @template T of object
 * @param class-string<T> $class
 * @param array<string,mixed> $config
 * @return T
 */
function conditionRule(string $class, array $config = []): object
{
	$rule = Craft::$container->get($class, [], $config);
	if (!$rule instanceof $class) {
		throw new LogicException("Not a {$class}.");
	}

	return $rule;
}

/**
 * The activities recorded, as [action, subject, ID, title, data, site, user ID], with their batch last if they are part of one.
 *
 * @return list<array<mixed>>
 */
function activities(): array
{
	$recorded = [];

	foreach (currentTest()->db()->queriesMatching(sqlInsertInto(Tables::ACTIVITY)) as $sql) {
		if (preg_match('/\(([^)]*)\)\s+VALUES\s+\((.*)\)\s*$/s', $sql, $parts) !== 1) {
			throw new LogicException("Not an insert: {$sql}");
		}

		preg_match_all('/`(\w+)`/', $parts[1], $columns);
		preg_match_all("/'(?:[^'\\\\]|\\\\.|'')*'|NULL|-?\\d+/", $parts[2], $values);
		$row = array_combine($columns[1], array_map(fn(string $value): int|string|null => match (true) {
			$value === 'NULL' => null,
			str_starts_with($value, "'") => stripcslashes(substr($value, 1, -1)),
			default => (int) $value,
		}, $values[0]));
		$data = isset($row['data']) ? json_decode((string) $row['data'], true) : [];
		if (in_array($row['action'], ['bulk', 'applied'], true)) {
			continue;
		}

		$recorded[] = [ActivityAction::from((string) $row['action']), ActivitySubject::from((string) $row['subject']), $row['subjectId'], $row['subjectTitle'], $data, $row['siteId'], $row['userId']];
	}

	return $recorded;
}

/**
 * Whether an activity recorded now is part of a batch that was begun and not ended.
 */
function isBatching(): bool
{
	Hub::getInstance()->activity->record(ActivityAction::Created, ActivitySubject::Entry, 99);
	$last = lastOf(currentTest()->db()->queriesMatching(sqlInsertInto(Tables::ACTIVITY)));

	return str_contains(asString($last), '`batchId`');
}

/**
 * Configures the checks of the diagnostics so only the given ones are enabled, each with its severity, by its key.
 *
 * @param array<string,Severity|null> $enabled
 */
function onlyChecks(array $enabled): void
{
	$defaults = [
		'entries' => ['unreferenced', 'stale', 'drafts', 'disabled'],
		'assets' => ['unused', 'missingAlt', 'large', 'oversized'],
		'users' => ['locked', 'adminsWithoutTwoFactor', 'idle', 'pending', 'suspended', 'unverifiedEmail'],
		'entryTypes' => ['unused', 'empty'],
		'sections' => ['empty'],
		'fields' => ['unused', 'empty'],
		'volumes' => ['empty'],
	];
	$rows = [];

	foreach ($defaults as $kind => $handles) {
		foreach ($handles as $handle) {
			$id = count($rows) + 1;
			$rows[] = [
				'id' => $id,
				'kind' => $kind,
				'handle' => $handle,
				'filter' => null,
				'name' => null,
				'description' => null,
				'passedDescription' => null,
				'icon' => null,
				'severity' => ($enabled["{$kind}.{$handle}"] ?? null)?->value,
				'enabled' => (int) array_key_exists("{$kind}.{$handle}", $enabled),
				'sortOrder' => $id,
				'uid' => "{$kind}-{$handle}",
			];
		}
	}

	currentTest()->db()->respond('FROM {{%hub_checks}}', $rows);
}

/**
 * A row of the activity log, with the given columns in place of the defaults.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function activityRow(array $row): array
{
	return [
		'id' => 1,
		'action' => 'updated',
		'subject' => 'entry',
		'subjectId' => null,
		'subjectTitle' => null,
		'siteId' => null,
		'userId' => null,
		'userName' => null,
		'ipAddress' => null,
		'data' => null,
		'dateCreated' => '2026-10-20 12:00:00',
		...$row,
	];
}
