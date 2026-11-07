<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
	// Craft loads its `Craft` class itself, so Composer cannot autoload it.
	->ignoreUnknownClasses(['Craft'])
	// Yii is the framework of Craft, which requires the versions that it supports.
	->ignoreErrorsOnPackage('yiisoft/yii2', [ErrorType::SHADOW_DEPENDENCY])
	// The fake connection of the tests answers queries with the statements of PDO, which Craft requires.
	->ignoreErrorsOnExtensionAndPath('ext-pdo', __DIR__ . '/tests/Support', [ErrorType::SHADOW_DEPENDENCY])
	// The test case extends the one of PHPUnit, whose version Pest requires.
	->ignoreErrorsOnPackageAndPath('phpunit/phpunit', __DIR__ . '/tests/TestCase.php', [ErrorType::SHADOW_DEPENDENCY])
	// The PHPStan extensions of the tests use the parser that PHPStan comes with.
	->ignoreErrorsOnPackageAndPath('nikic/php-parser', __DIR__ . '/tests/PHPStan', [ErrorType::SHADOW_DEPENDENCY])
	// The architecture tests use the plugin of Pest for them, whose version Pest requires.
	->ignoreErrorsOnPackageAndPath('pestphp/pest-plugin-arch', __DIR__ . '/tests/Arch', [ErrorType::SHADOW_DEPENDENCY])
	// The smoke test runs within a Craft project, which loads its environment with the packages of that project.
	->ignoreErrorsOnPath(__DIR__ . '/tests/Integration', [ErrorType::UNKNOWN_CLASS]);
