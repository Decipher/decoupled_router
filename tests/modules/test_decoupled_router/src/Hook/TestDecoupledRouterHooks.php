<?php

declare(strict_types=1);

namespace Drupal\test_decoupled_router\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\user\EntityOwnerInterface;

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
    if (!$entity instanceof EntityOwnerInterface) {
      return;
    }
    $output['entity']['owner'] = $entity->getOwner()->uuid();
  }

}
