<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
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
