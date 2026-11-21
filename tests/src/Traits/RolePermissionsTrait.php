<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Traits;

use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Grants and revokes role permissions without the deprecated helpers.
 *
 * The procedural user_role_grant_permissions() is deprecated in drupal:11.5.0
 * and removed
 * in drupal:13.0.0. Its replacement, RoleInterface::grantPermissions(),
 * arrived in 11.5 and so cannot be called on the older core versions this
 * module still supports. RoleInterface::grantPermission() exists on all of
 * them and is not deprecated.
 */
trait RolePermissionsTrait {

  /**
   * Grants permissions to a role.
   *
   * @param string $role_id
   *   The role to grant them to.
   * @param string[] $permissions
   *   The permissions to grant.
   */
  protected function grantPermissionsToRole(string $role_id, array $permissions): void {
    $role = Role::load($role_id);
    self::assertInstanceOf(RoleInterface::class, $role);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
  }

  /**
   * Revokes permissions from a role.
   *
   * @param string $role_id
   *   The role to revoke them from.
   * @param string[] $permissions
   *   The permissions to revoke.
   */
  protected function revokePermissionsFromRole(string $role_id, array $permissions): void {
    $role = Role::load($role_id);
    self::assertInstanceOf(RoleInterface::class, $role);
    foreach ($permissions as $permission) {
      $role->revokePermission($permission);
    }
    $role->save();
  }

}
