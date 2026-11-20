<?php

namespace Drupal\decoupled_router\Hook;

use Drupal\path_alias\PathAliasInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for decoupled_router.
 */
class DecoupledRouterHooks {

  /**
   * Implements hook_ENTITY_TYPE_presave().
   *
   * Stashes the currently-stored version of an existing path alias before
   * save overwrites it. decoupled_router_path_alias_update() needs the
   * unchanged version to detect a repointed alias's old source path; loading
   * it again after save would just return the new values.
   */
  #[Hook('path_alias_presave')]
  public function pathAliasPresave(PathAliasInterface $path_alias): void {
    if ($path_alias->isNew()) {
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('path_alias');
    $unchanged = $storage->loadUnchanged($path_alias->id());
    if ($unchanged instanceof PathAliasInterface) {
      _decoupled_router_stash_path_alias($path_alias->id(), $unchanged);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_insert().
   *
   * Invalidates cached decoupled router responses when a new path alias is
   * created. The internal source path is used so that entity-specific cache
   * tags can be resolved.
   */
  #[Hook('path_alias_insert')]
  public function pathAliasInsert(PathAliasInterface $path_alias): void {
    \Drupal::service('decoupled_router.cache_invalidation')->invalidateByPath([
      'source' => $path_alias->getPath(),
    ]);
  }

  /**
   * Implements hook_ENTITY_TYPE_update().
   *
   * Invalidates cached decoupled router responses when a path alias is
   * updated. A path alias can be repointed to a different source path (the
   * "Path" field on the alias edit form, not just the "Alias" field), so the
   * original source path is invalidated too when it differs from the new
   * one. Otherwise a repointed alias leaves the old source path's decoupled
   * router responses stale.
   */
  #[Hook('path_alias_update')]
  public function pathAliasUpdate(PathAliasInterface $path_alias): void {
    $cache_invalidator = \Drupal::service('decoupled_router.cache_invalidation');
    $cache_invalidator->invalidateByPath([
      'source' => $path_alias->getPath(),
    ]);
    $original = _decoupled_router_stash_path_alias($path_alias->id());
    if ($original instanceof PathAliasInterface && $original->getPath() !== $path_alias->getPath()) {
      $cache_invalidator->invalidateByPath([
        'source' => $original->getPath(),
      ]);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete().
   *
   * Invalidates cached decoupled router responses when a path alias is deleted.
   */
  #[Hook('path_alias_delete')]
  public function pathAliasDelete(PathAliasInterface $path_alias): void {
    \Drupal::service('decoupled_router.cache_invalidation')->invalidateByPath([
      'source' => $path_alias->getPath(),
    ]);
  }

}
