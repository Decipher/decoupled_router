<?php

/**
 * @file
 * Hooks provided by the Decoupled Router module.
 */

declare(strict_types=1);

/**
 * Perform alterations before to return the router info.
 *
 * @param array $output
 *   Nested matrix of basic elements that comprise the found entity.
 * @param array $context
 *   An array with the following keys:
 *   - entity: The type of the parent entity.
 */
function hook_decoupled_router_info_alter(array &$output, array $context): void {
  if ($output['entity'] && $context['entity']) {
    $output['entity']['description'] = t('New relevant description.');
  }
}
