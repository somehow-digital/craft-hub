<?php

declare(strict_types=1);

use craft\web\View;
use Mockery\MockInterface;
use SomehowDigital\Craft\Hub\assets\HubAsset;
use SomehowDigital\Craft\Hub\assets\SidebarAsset;

const QUOTED = "'((?:[^'\\\\]|\\\\.)*)'";

/**
 * Contents of the files below `src` with the extensions, by their path.
 *
 * @param string[] $extensions
 * @return array<string,string>
 */
function sources(array $extensions): array
{
	$sources = [];

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')) as $file) {
		if (!$file instanceof SplFileInfo) {
			continue;
		}

		$path = $file->getPathname();

		if (in_array($file->getExtension(), $extensions, true) && !str_contains($path, '/translations/')) {
			$sources[$path] = (string) file_get_contents($path);
		}
	}

	return $sources;
}

/**
 * Unique messages the patterns capture in the sources.
 *
 * @param string[] $patterns
 * @param array<string,string> $sources
 * @return list<string>
 */
function messages(array $patterns, array $sources): array
{
	$messages = [];

	foreach ($sources as $source) {
		foreach ($patterns as $pattern) {
			preg_match_all($pattern, $source, $matches);
			array_push($messages, ...$matches[1]);
		}
	}

	return array_values(array_unique(array_map(stripslashes(...), $messages)));
}

/**
 * The messages of Hub's category in the code, including those kept in variables, translated where they're shown.
 *
 * @return list<string>
 */
function hubMessages(): array
{
	return messages([
		'/Craft(?:::|\.)t\(\s*\'hub\'\s*,\s*' . QUOTED . '/',
		'/' . QUOTED . '\s*\|\s*t\(\s*\'hub\'/',
		'/"([^"\n]*)"\s*\|\s*t\(\s*\'hub\'/',
		'/\{%\s*set (?:noun|countLabel) = ' . QUOTED . '/',
		'/^\s*(?:prepend|append|placeBefore|placeAfter): ' . QUOTED . '/m',
		'/->setItems\(\$\w+, ' . QUOTED . '/',
	], sources(['php', 'twig', 'js']));
}

/**
 * The messages of Hub's category the given sources translate in the browser.
 *
 * @param array<string,string> $sources
 * @return list<string>
 */
function scriptMessages(array $sources): array
{
	return messages(['/Craft\.t\(\s*\'hub\'\s*,\s*' . QUOTED . '/'], $sources);
}

/**
 * The placeholders of a message, leaving out the forms of its plurals, like `=1{One entry}`, which are translated.
 *
 * @return list<string>
 */
function placeholders(string $message): array
{
	preg_match_all('/(?<!\w)\{(\w+)[,}]/', $message, $matches);
	$names = array_unique($matches[1]);
	sort($names);

	return $names;
}

/**
 * Messages an asset bundle registers for the browser.
 *
 * @return list<string>
 */
function registeredMessages(HubAsset|SidebarAsset $bundle): array
{
	$bundle->css = [];
	$bundle->js = [];

	$registered = [];
	/** @var View&MockInterface $view */
	$view = Mockery::mock(View::class)->shouldIgnoreMissing();
	$view->shouldReceive('registerTranslations')->once()->with('hub', Mockery::on(function (array $messages) use (&$registered): true {
		$registered = strings($messages);

		return true;
	}));

	$bundle->registerAssetFiles($view);

	return $registered;
}

$translations = fn(string $language): array => listOf(require dirname(__DIR__, 2) . "/src/translations/{$language}/hub.php");
$directories = glob(dirname(__DIR__, 2) . '/src/translations/*', GLOB_ONLYDIR);
$languages = array_map(basename(...), $directories === false ? [] : $directories);

it('lists every message of the code in the source language, and no others', function () use ($translations): void {
	$source = array_keys($translations('en'));

	expect(array_values(array_diff(hubMessages(), $source)))->toBe([])
		->and(array_values(array_diff($source, hubMessages())))->toBe([]);
});

it('registers the messages the pages translate in the browser', function (): void {
	$sources = sources(['js', 'twig']);
	unset($sources[(string) array_key_first(array_filter($sources, fn(string $path): bool => str_ends_with($path, 'sidebar.js'), ARRAY_FILTER_USE_KEY))]);

	expect(registeredMessages(new HubAsset()))->toEqualCanonicalizing(scriptMessages($sources));
});

it('registers the messages the sidebar translates in the browser', function (): void {
	$sources = array_filter(sources(['js']), fn(string $path): bool => str_ends_with($path, 'sidebar.js'), ARRAY_FILTER_USE_KEY);

	expect(registeredMessages(new SidebarAsset()))->toEqualCanonicalizing(scriptMessages($sources));
});

it('translates every message of the source language', function (string $language) use ($translations): void {
	expect(array_keys($translations($language)))->toEqualCanonicalizing(array_keys($translations('en')));
})->with($languages);

it('keeps the placeholders of the messages in their translations', function (string $language) use ($translations): void {
	foreach ($translations($language) as $message => $translation) {
		expect(placeholders(asString($translation)))->toBe(placeholders($message), $message);
	}
})->with($languages);
