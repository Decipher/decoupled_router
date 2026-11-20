<?php

namespace Drupal\test_decoupled_router\Hook;

use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for test_decoupled_router.
 */
class TestDecoupledRouterHooks {

  /**
   * Implements hook_decoupled_router_info_alter().
   */
  #[Hook('decoupled_router_info_alter')]
  public function decoupledRouterInfoAlter(array &$output, array $context): void {
    $entity = $context['entity'];
    if (!is_subclass_of($entity, EntityOwnerInterface::class)) {
      return;
    }
    $output['entity']['owner'] = $entity->getOwner()->uuid();
  }

}
