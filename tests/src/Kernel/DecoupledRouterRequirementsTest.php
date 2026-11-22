<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests decoupled_router.install hook implementations.
 *
 * @group decoupled_router
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class DecoupledRouterRequirementsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'path',
    'path_alias',
    'link',
    'views',
    'decoupled_router',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // hook_requirements() and update hooks live in the .install file, which
    // Drupal only loads on demand (unlike .module files).
    $this->container->get('module_handler')->loadInclude('decoupled_router', 'install');
  }

  /**
   * Makes the extension.list.module service report a fixed Redirect version.
   */
  protected function mockRedirectVersion(?string $version): void {
    $root = \Drupal::root();
    $extension = new Extension($root, 'module', 'modules/contrib/redirect/redirect.info.yml', 'redirect.info.yml');
    $extension->info = ['version' => $version];

    $module_list = $this->getMockBuilder(ModuleExtensionList::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['get'])
      ->getMock();
    $module_list->method('get')->with('redirect')->willReturn($extension);

    $this->container->set('extension.list.module', $module_list);
  }

  /**
   * Tests that no requirement is reported when Redirect is not installed.
   */
  public function testRequirementsWithoutRedirectModule(): void {
    self::assertFalse($this->container->get('module_handler')->moduleExists('redirect'));
    self::assertSame([], decoupled_router_requirements('runtime'));
  }

  /**
   * Tests dev checkouts of Redirect, which report no info.yml version.
   */
  public function testRequirementsWithUnversionedRedirectModule(): void {
    $this->enableModules(['redirect']);
    self::assertSame([], decoupled_router_requirements('runtime'));
  }

  /**
   * Tests that an old Redirect release is reported as a requirement error.
   */
  public function testRequirementsWithIncompatibleRedirectVersion(): void {
    $this->enableModules(['redirect']);
    $this->mockRedirectVersion('8.x-1.10');

    $requirements = decoupled_router_requirements('runtime');

    self::assertArrayHasKey('decoupled_router_redirect', $requirements);
    self::assertSame(
      'The Redirect module must be version 1.12 or greater.',
      (string) $requirements['decoupled_router_redirect']['description']
    );
  }

  /**
   * Tests that a compatible Redirect release reports no requirement.
   */
  public function testRequirementsWithCompatibleRedirectVersion(): void {
    $this->enableModules(['redirect']);
    $this->mockRedirectVersion('8.x-1.12');

    self::assertSame([], decoupled_router_requirements('runtime'));
  }

  /**
   * Tests that the install phase reports nothing and touches no module code.
   *
   * Drupal validates hook_requirements('install') before the module is
   * installed, so its namespace is not registered and its services are not in
   * the container. Anything this function reaches for from the module itself
   * is a fatal error at install time, on the one code path Drupal 11.3 and
   * later never run.
   */
  public function testRequirementsInstallPhaseReportsNothing(): void {
    $this->enableModules(['redirect']);
    $this->mockRedirectVersion('8.x-1.10');

    self::assertSame([], decoupled_router_requirements('install'));
    self::assertSame([], decoupled_router_requirements('update'));
  }

  /**
   * Runs the implementation core dispatches from Drupal 11.3 onwards.
   *
   * The procedural hook above it carries #[LegacyRequirementsHook], so on
   * current core the attribute class is the only implementation that runs.
   * Going through the module handler rather than calling the service keeps
   * the #[Hook] attribute itself under test: a hook name that core does not
   * recognise fails the assertion below instead of passing unnoticed.
   *
   * @return array
   *   The requirements the hook reports.
   */
  protected function runtimeRequirements(): array {
    $module_handler = $this->container->get('module_handler');
    // Asking whether anything implements the hook is no use here, because
    // this module always does. The attribute that switches the procedural
    // hook off landed in the same release that added the runtime hook, so
    // its absence is what says core predates both. Named as a string so a
    // Drupal 10 build does not report an unknown class.
    if (!class_exists('Drupal\\Core\\Hook\\Attribute\\LegacyRequirementsHook')) {
      self::markTestSkipped('hook_runtime_requirements() arrived in Drupal 11.3.');
    }
    self::assertTrue(
      $module_handler->hasImplementations('runtime_requirements', ['decoupled_router']),
      "Core discovers the module's runtime requirements hook."
    );

    return (array) $module_handler->invoke('decoupled_router', 'runtime_requirements');
  }

  /**
   * Tests that the attribute hook reports nothing without Redirect.
   */
  public function testRuntimeRequirementsWithoutRedirectModule(): void {
    self::assertSame([], $this->runtimeRequirements());
  }

  /**
   * Tests that the attribute hook reports an error for an old Redirect.
   */
  public function testRuntimeRequirementsWithIncompatibleRedirectVersion(): void {
    $this->enableModules(['redirect']);
    $this->mockRedirectVersion('8.x-1.10');

    $requirements = $this->runtimeRequirements();

    self::assertArrayHasKey('decoupled_router_redirect', $requirements);
    self::assertSame(
      'The Redirect module must be version 1.12 or greater.',
      (string) $requirements['decoupled_router_redirect']['description']
    );
    self::assertSame(
      RequirementSeverity::Error,
      $requirements['decoupled_router_redirect']['severity']
    );
  }

  /**
   * Tests that the attribute hook reports nothing for a current Redirect.
   */
  public function testRuntimeRequirementsWithCompatibleRedirectVersion(): void {
    $this->enableModules(['redirect']);
    $this->mockRedirectVersion('8.x-1.12');

    self::assertSame([], $this->runtimeRequirements());
  }

  /**
   * Tests that the update hook enables absolute resolved URLs.
   */
  public function testUpdate20001SetsAbsoluteResolvedUrls(): void {
    $this->installConfig(['decoupled_router']);

    decoupled_router_update_20001();

    self::assertTrue(
      (bool) $this->config('decoupled_router.settings')->get('absolute_resolved_urls')
    );
  }

}
