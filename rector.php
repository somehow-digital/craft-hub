<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\CodingStyle\Rector\ClassLike\NewlineBetweenClassLikeStmtsRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\FunctionLike\NarrowWideUnionReturnTypeRector;

return RectorConfig::configure()
	->withPaths([
		__DIR__ . '/src',
		__DIR__ . '/tests',
	])
	->withRootFiles()
	// Upgrades to the lowest version of PHP that the plugin supports, as Composer requires it.
	->withPhpSets()
	->withPreparedSets(
		deadCode: true,
		codeQuality: true,
		codingStyle: true,
		typeDeclarations: true,
		typeDeclarationDocblocks: true,
		privatization: true,
		instanceOf: true,
		earlyReturn: true,
		rectorPreset: true,
		phpunitCodeQuality: true,
	)
	->withComposerBased(phpunit: true)
	->withAttributesSets(phpunit: true)
	->withSkip([
		// Composer keys its registered loaders by the resolved path, which a path with `..` doesn't match.
		DirnameDirConcatStringToDirectStringPathRector::class,
		// The coding standard separates the members of classes, and keeps related constants grouped.
		NewlineBetweenClassLikeStmtsRector::class,
		// Tests sign the user out by setting the identity that the mock returns to null, after the closure is created.
		NarrowWideUnionReturnTypeRector::class => [__DIR__ . '/tests/Feature/Services/ColumnServiceTest.php'],
	])
	->withImportNames(removeUnusedImports: true)
	->withParallel();
