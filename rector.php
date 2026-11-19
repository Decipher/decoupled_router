<?php

/**
 * @file
 * Rector configuration.
 *
 * Rector automatically refactors PHP code to:
 * - Upgrade deprecated Drupal APIs.
 * - Modernize PHP syntax to leverage new language features.
 * - Improve code quality and maintainability.
 *
 * @see https://github.com/palantirnet/drupal-rector
 * @see https://getrector.com/documentation
 * @see https://getrector.com/documentation/set-lists
 */

declare(strict_types=1);

use DrupalFinder\DrupalFinderComposerRuntime;
use DrupalRector\Rector\Deprecation\ConstantToClassConstantRector;
use DrupalRector\Set\DrupalSetProvider;
use Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector;
use Rector\CodeQuality\Rector\ClassMethod\InlineArrayReturnAssignRector;
use Rector\CodeQuality\Rector\Empty_\SimplifyEmptyCheckOnEmptyArrayRector;
use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\CodingStyle\Rector\ClassMethod\NewlineBeforeNewAssignSetRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\Naming\Rector\Assign\RenameVariableToMatchMethodCallReturnTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameParamToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameVariableToMatchNewTypeRector;
use Rector\Naming\Rector\Foreach_\RenameForeachValueVariableToMatchExprVariableRector;
use Rector\Naming\Rector\Foreach_\RenameForeachValueVariableToMatchMethodCallReturnTypeRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Privatization\Rector\ClassMethod\PrivatizeFinalClassMethodRector;
use Rector\Privatization\Rector\MethodCall\PrivatizeLocalGetterToPropertyRector;
use Rector\Privatization\Rector\Property\PrivatizeFinalClassPropertyRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

// Rector and its embedded PHPStan cache reflection data that records
// absolute paths into the PHPStan PHAR. The default cache directories are
// shared by every project on the machine, so entries left by a build that no
// longer exists break the next run. A cache inside the build is scoped to a
// single codebase. PHPStan requires the directory to exist before it boots.
// Rector keeps its own file cache and the cache of the PHPStan container it
// boots. Both in one directory means `--clear-cache` removes the container
// with the cache, and Rector then fails to write the container file, so each
// gets its own directory and both exist before Rector boots.
$cache_dir = __DIR__ . '/.rector';
$container_cache_dir = __DIR__ . '/.rector-container';
foreach ([$cache_dir, $container_cache_dir] as $dir) {
  if (!is_dir($dir)) {
    mkdir($dir, 0755, TRUE);
  }
}

return RectorConfig::configure()
  ->withSkip([
    // Specific rules to skip based on project coding standards. Rector only
    // registers `AddOverrideAttributeToOverriddenMethodsRector` on the version
    // resolved for Drupal 10 builds and warns that the entry is unused on
    // newer ones, so it stays listed to cover both.
    AddOverrideAttributeToOverriddenMethodsRector::class,
    CatchExceptionNameMatchingTypeRector::class,
    ChangeSwitchToMatchRector::class,
    CompleteDynamicPropertiesRector::class,
    // Eddy dropped this entry because Rector 2.8 deprecates the rule, while
    // the build resolves a Rector below 2.6.5, where it still runs and
    // rewrites an empty() check into a longer list of comparisons.
    DisallowedEmptyRuleFixerRector::class,
    InlineArrayReturnAssignRector::class,
    NewlineAfterStatementRector::class,
    NewlineBeforeNewAssignSetRector::class,
    PrivatizeFinalClassMethodRector::class,
    PrivatizeFinalClassPropertyRector::class,
    PrivatizeLocalGetterToPropertyRector::class,
    RemoveAlwaysTrueIfConditionRector::class,
    RenameForeachValueVariableToMatchExprVariableRector::class,
    RenameForeachValueVariableToMatchMethodCallReturnTypeRector::class,
    RenameParamToMatchTypeRector::class,
    RenameVariableToMatchMethodCallReturnTypeRector::class,
    RenameVariableToMatchNewTypeRector::class,
    SimplifyEmptyCheckOnEmptyArrayRector::class,
    StringClassNameToClassConstantRector::class,
    // Directories to skip.
    '*/node_modules/*',
  ])
  // A rule skipped for one path only. Keyed entries live in their own call
  // because the Drupal standard refuses an array that mixes both forms.
  ->withSkip([
    // The Drupal 11.2 set maps REQUIREMENT_ERROR to the RequirementSeverity
    // enum. That is right for the modern arm of a
    // DeprecationHelper::backwardsCompatibleCall() and wrong for the legacy
    // arm beside it, which runs on Drupal 10, where the enum does not exist.
    // The extension is symlinked into the build, so Rector reports the path it
    // resolves to rather than one under 'web/modules/custom'.
    ConstantToClassConstantRector::class => [
      '*decoupled_router.install',
    ],
  ])
  // PHP version upgrade sets. Held at the minimum composer.json advertises,
  // not at the newest the matrix runs. Rector rewrites src/, so a higher set
  // lets `make lint-fix` emit syntax that cannot parse on a PHP version this
  // module still claims to support, and no CI leg runs below 8.3 to catch it.
  // Raise this with the composer constraint, in the same change.
  ->withPhpSets(php81: TRUE)
  // Code quality improvement sets.
  ->withPreparedSets(
    deadCode: TRUE,
    codeQuality: TRUE,
    codingStyle: TRUE,
    typeDeclarations: TRUE,
    privatization: TRUE,
    naming: TRUE,
  )
  // Drupal-specific deprecation fixes. The provider binds each set to a
  // `drupal/core` version and only the sets the installed core satisfies are
  // loaded, so the rules follow the version the extension is being built
  // against. Both calls are required: the provider supplies the sets,
  // `withComposerBased()` enables the group.
  ->withSetProviders(DrupalSetProvider::class)
  ->withComposerBased(drupal: TRUE)
  // Additional rules.
  ->withRules([
    DeclareStrictTypesRector::class,
  ])
  // Paths to the extension's source. Each top-level item of the extension is
  // symlinked individually into the build and Rector's finder does not follow
  // symlinked directories, so the extension's children are matched directly
  // (each is passed to the finder as its own root, which a symlinked root
  // resolves).
  ->withPaths([
    __DIR__ . '/web/modules/custom/*/*',
  ])
  // Configure Drupal autoloading.
  ->withAutoloadPaths((function (): array {
    $drupal_finder = new DrupalFinderComposerRuntime();
    $drupal_root = $drupal_finder->getDrupalRoot();

    return [
      $drupal_root . '/core',
      $drupal_root . '/modules',
      $drupal_root . '/themes',
      $drupal_root . '/profiles',
    ];
  })())
  // Drupal file extensions.
  ->withFileExtensions([
    'php',
    'module',
    'install',
    'profile',
    'theme',
    'inc',
    'engine',
  ])
  // Cache configuration.
  ->withCache(cacheDirectory: $cache_dir, containerCacheDirectory: $container_cache_dir)
  // Import configuration.
  ->withImportNames(importNames: TRUE, importDocBlockNames: FALSE, importShortClasses: FALSE);
