<?php

use craft\web\View;
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
		if (in_array($file->getExtension(), $extensions, true) && !str_contains($file->getPathname(), '/translations/')) {
			$sources[$file->getPathname()] = file_get_contents($file->getPathname());
		}
	}

	return $sources;
}

/**
 * Unique messages the patterns capture in the sources.
 *
 * @param string[] $patterns
 * @param array<string,string> $sources
 * @return string[]
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
 * @return string[]
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
 * @return string[]
 */
function scriptMessages(array $sources): array
{
	return messages(['/Craft\.t\(\s*\'hub\'\s*,\s*' . QUOTED . '/'], $sources);
}

/**
 * The placeholders of a message, leaving out the forms of its plurals, like `=1{One entry}`, which are translated.
 *
 * @return string[]
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
 * @return string[]
 */
function registeredMessages(HubAsset|SidebarAsset $bundle): array
{
	$bundle->css = $bundle->js = [];
	$registered = [];
	$view = Mockery::mock(View::class)->shouldIgnoreMissing();
	$view->shouldReceive('registerTranslations')->once()->with('hub', Mockery::on(function (array $messages) use (&$registered) {
		$registered = $messages;

		return true;
	}));

	$bundle->registerAssetFiles($view);

	return $registered;
}

$translations = fn (string $language): array => require dirname(__DIR__, 2) . "/src/translations/$language/hub.php";
$languages = array_map('basename', glob(dirname(__DIR__, 2) . '/src/translations/*', GLOB_ONLYDIR));

it('lists every message of the code in the source language, and no others', function () use ($translations) {
	$source = array_keys($translations('en'));

	expect(array_values(array_diff(hubMessages(), $source)))->toBe([])
		->and(array_values(array_diff($source, hubMessages())))->toBe([]);
});

it('registers the messages the pages translate in the browser', function () {
	$sources = sources(['js', 'twig']);
	unset($sources[array_key_first(array_filter($sources, fn (string $path) => str_ends_with($path, 'sidebar.js'), ARRAY_FILTER_USE_KEY))]);

	expect(registeredMessages(new HubAsset()))->toEqualCanonicalizing(scriptMessages($sources));
});

it('registers the messages the sidebar translates in the browser', function () {
	$sources = array_filter(sources(['js']), fn (string $path) => str_ends_with($path, 'sidebar.js'), ARRAY_FILTER_USE_KEY);

	expect(registeredMessages(new SidebarAsset()))->toEqualCanonicalizing(scriptMessages($sources));
});

it('translates every message of the source language', function (string $language) use ($translations) {
	expect(array_keys($translations($language)))->toEqualCanonicalizing(array_keys($translations('en')));
})->with($languages);

it('keeps the placeholders of the messages in their translations', function (string $language) use ($translations) {
	foreach ($translations($language) as $message => $translation) {
		expect(placeholders($translation))->toBe(placeholders($message), $message);
	}
})->with($languages);
