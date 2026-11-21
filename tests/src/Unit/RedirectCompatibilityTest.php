<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Unit;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\decoupled_router\RedirectCompatibility;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests the Redirect compatibility requirement.
 */
#[Group('decoupled_router')]
class RedirectCompatibilityTest extends UnitTestCase {

  /**
   * Builds the service with a given Redirect module state.
   *
   * @param bool $installed
   *   Whether the Redirect module is installed.
   * @param string|null $version
   *   The version in the Redirect module's info, or NULL for none.
   */
  protected function buildService(bool $installed, ?string $version): RedirectCompatibility {
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('redirect')->willReturn($installed);

    $extension = $this->createMock(Extension::class);
    $extension->info = $version === NULL ? [] : ['version' => $version];
    $extension_list = $this->createMock(ModuleExtensionList::class);
    $extension_list->method('get')->willReturn($extension);

    return new RedirectCompatibility(
      $module_handler,
      $extension_list,
      $this->createMock(TranslationInterface::class),
    );
  }

  /**
   * A Redirect release older than the minimum reports a requirement.
   *
   * PHPUnit 9.6 on Drupal 10 ignores the attributes below and reads this
   * annotation, so both forms have to list the same cases.
   *
   * @testWith ["1.11"]
   *           ["1.0"]
   *           ["8.x-1.9"]
   */
  #[TestWith(['1.11'])]
  #[TestWith(['1.0'])]
  #[TestWith(['8.x-1.9'])]
  public function testOldRedirectReportsRequirement(string $version): void {
    $requirement = $this->buildService(TRUE, $version)->getRequirement();

    self::assertIsArray($requirement);
    self::assertArrayHasKey('title', $requirement);
    self::assertArrayHasKey('description', $requirement);
    // Severity belongs to the caller, which is the only part that differs
    // between the procedural hook and the attribute one.
    self::assertArrayNotHasKey('severity', $requirement);
  }

  /**
   * A Redirect release at or above the minimum reports nothing.
   *
   * @testWith ["1.12"]
   *           ["1.13"]
   *           ["8.x-1.12"]
   *           ["2.0"]
   */
  #[TestWith(['1.12'])]
  #[TestWith(['1.13'])]
  #[TestWith(['8.x-1.12'])]
  #[TestWith(['2.0'])]
  public function testCurrentRedirectReportsNothing(string $version): void {
    self::assertNull($this->buildService(TRUE, $version)->getRequirement());
  }

  /**
   * Redirect being absent reports nothing, since it is an optional module.
   */
  public function testAbsentRedirectReportsNothing(): void {
    self::assertNull($this->buildService(FALSE, '1.0')->getRequirement());
  }

  /**
   * A checkout with no version in its info reports nothing.
   */
  public function testRedirectWithNoVersionReportsNothing(): void {
    self::assertNull($this->buildService(TRUE, NULL)->getRequirement());
  }

}
