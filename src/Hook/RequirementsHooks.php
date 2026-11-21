<?php

declare(strict_types=1);

namespace Drupal\decoupled_router\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\decoupled_router\RedirectCompatibility;

/**
 * Requirements hook implementations.
 *
 * Drupal 11.3 replaced hook_requirements() with hook_runtime_requirements(),
 * and deprecates a procedural requirements hook that is not marked with
 * #[LegacyRequirementsHook]. This class is the implementation for Drupal 11.3
 * and later. The procedural one stays for Drupal 10 and 11.2, which never call
 * hook_runtime_requirements() and do not recognise either attribute.
 *
 * @see decoupled_router_requirements()
 */
class RequirementsHooks {

  public function __construct(
    protected RedirectCompatibility $redirectCompatibility,
  ) {
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $requirement = $this->redirectCompatibility->getRequirement();
    if ($requirement === NULL) {
      return [];
    }
    $requirement['severity'] = RequirementSeverity::Error;

    return ['decoupled_router_redirect' => $requirement];
  }

}
