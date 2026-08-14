<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\path_alias\Entity\PathAlias;

/**
 * Tests decoupled_router.module hook implementations.
 *
 * Verifies that creating, updating, and deleting path aliases triggers
 * the correct cache invalidation through the entity-type hooks
 * (hook_path_alias_insert/update/delete).
 *
 * @group decoupled_router
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class DecoupledRouterModuleHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'file',
    'entity_test',
    'path',
    'path_alias',
    'link',
    'views',
    'test_decoupled_router',
    'decoupled_router',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('state')->set('entity_test.additional_base_field_definitions', [
      'path' => BaseFieldDefinition::create('path')->setComputed(TRUE),
    ]);
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('path_alias');
  }

  /**
   * Stores a cache entry tagged the way invalidateByPath() tags 4xx responses.
   */
  protected function primeFourXxResponseCache(string $cid = 'decoupled_router_test:4xx'): void {
    \Drupal::cache()->set($cid, 'cached', Cache::PERMANENT, ['4xx-response']);
    self::assertNotFalse(\Drupal::cache()->get($cid));
  }

  /**
   * Stores a cache entry tagged with the given entity cache tags.
   */
  protected function primeEntityCache(string $cid, array $tags): void {
    \Drupal::cache()->set($cid, 'cached', Cache::PERMANENT, $tags);
    self::assertNotFalse(\Drupal::cache()->get($cid));
  }

  /**
   * Creates an entity_test entity reachable at the given alias.
   */
  protected function createTestEntity(string $alias = '/test-entity') {
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create(['name' => 'test', 'path' => $alias]);
    $entity->save();
    return $entity;
  }

  /**
   * Loads the path alias associated with an entity_test entity.
   */
  protected function loadPathAliasForEntity(string $entity_id): ?PathAlias {
    $aliases = \Drupal::entityTypeManager()
      ->getStorage('path_alias')
      ->loadByProperties(['path' => '/entity_test/' . $entity_id]);
    return $aliases ? reset($aliases) : NULL;
  }

  /**
   * Tests that saving a new path alias invalidates cached 4xx responses.
   */
  public function testPathAliasInsertInvalidatesFourXxResponseCache(): void {
    $this->primeFourXxResponseCache();

    $this->createTestEntity('/insert-test');

    self::assertFalse(\Drupal::cache()->get('decoupled_router_test:4xx'));
  }

  /**
   * Tests that insert invalidates entity-specific cache tags.
   *
   * This verifies that getPath() (the internal source path) is used rather
   * than getAlias() (the URL alias), so the CacheInvalidator can resolve
   * entity cache tags from the route parameters.
   */
  public function testPathAliasInsertInvalidatesEntityCacheTags(): void {
    $entity = $this->createTestEntity('/entity-tag-insert');
    $tags = $entity->getCacheTagsToInvalidate();
    $this->primeEntityCache('test:entity_insert', $tags);

    PathAlias::create([
      'path' => '/entity_test/' . $entity->id(),
      'alias' => '/second-alias-for-insert-test',
    ])->save();

    self::assertFalse(\Drupal::cache()->get('test:entity_insert'));
  }

  /**
   * Tests that updating a path alias invalidates cached 4xx responses.
   */
  public function testPathAliasUpdateInvalidatesFourXxResponseCache(): void {
    $entity = $this->createTestEntity('/update-test');
    $this->primeFourXxResponseCache('test:4xx_update');

    $alias = $this->loadPathAliasForEntity((string) $entity->id());
    self::assertNotNull($alias);
    $alias->set('alias', '/updated-alias')->save();

    self::assertFalse(\Drupal::cache()->get('test:4xx_update'));
  }

  /**
   * Tests that updating a path alias invalidates entity-specific cache tags.
   */
  public function testPathAliasUpdateInvalidatesEntityCacheTags(): void {
    $entity = $this->createTestEntity('/entity-tag-update');
    $tags = $entity->getCacheTagsToInvalidate();
    $this->primeEntityCache('test:entity_update', $tags);

    $alias = $this->loadPathAliasForEntity((string) $entity->id());
    self::assertNotNull($alias);
    $alias->set('alias', '/changed-alias')->save();

    self::assertFalse(\Drupal::cache()->get('test:entity_update'));
  }

  /**
   * Tests that repointing an alias to a different source invalidates both.
   *
   * The alias edit form allows changing the "Path" (source) a alias points
   * to, not just its alias text. Both the entity that lost the alias and
   * the entity that gained it must have their caches invalidated.
   */
  public function testPathAliasUpdateRepointedSourceInvalidatesBothEntities(): void {
    $old_entity = $this->createTestEntity('/repoint-old');
    $new_entity = $this->createTestEntity('/repoint-new-target');

    $this->primeEntityCache('test:repoint_old', $old_entity->getCacheTagsToInvalidate());
    $this->primeEntityCache('test:repoint_new', $new_entity->getCacheTagsToInvalidate());

    $alias = $this->loadPathAliasForEntity((string) $old_entity->id());
    self::assertNotNull($alias);
    $alias->set('path', '/entity_test/' . $new_entity->id())->save();

    self::assertFalse(\Drupal::cache()->get('test:repoint_old'), 'The entity that lost the alias is invalidated.');
    self::assertFalse(\Drupal::cache()->get('test:repoint_new'), 'The entity that gained the alias is invalidated.');
  }

  /**
   * Tests that deleting a path alias invalidates cached 4xx responses.
   */
  public function testPathAliasDeleteInvalidatesFourXxResponseCache(): void {
    $entity = $this->createTestEntity('/delete-test');
    $this->primeFourXxResponseCache('test:4xx_delete');

    $alias = $this->loadPathAliasForEntity((string) $entity->id());
    self::assertNotNull($alias);
    $alias->delete();

    self::assertFalse(\Drupal::cache()->get('test:4xx_delete'));
  }

  /**
   * Tests that deleting a path alias invalidates entity-specific cache tags.
   */
  public function testPathAliasDeleteInvalidatesEntityCacheTags(): void {
    $entity = $this->createTestEntity('/entity-tag-delete');
    $tags = $entity->getCacheTagsToInvalidate();
    $this->primeEntityCache('test:entity_delete', $tags);

    $alias = $this->loadPathAliasForEntity((string) $entity->id());
    self::assertNotNull($alias);
    $alias->delete();

    self::assertFalse(\Drupal::cache()->get('test:entity_delete'));
  }

  /**
   * Tests that invalidateByPath handles a non-existent entity ID gracefully.
   *
   * The route has an entity-typed parameter but the ID does not resolve to
   * a real entity. The 4xx-response tag is still invalidated.
   */
  public function testInvalidateByPathForNonExistentEntity(): void {
    $this->primeFourXxResponseCache('test:4xx_nonexistent');

    \Drupal::service('decoupled_router.cache_invalidation')
      ->invalidateByPath(['source' => '/test-decoupled-router/raw-entity-test-param/999999']);

    self::assertFalse(\Drupal::cache()->get('test:4xx_nonexistent'));
  }

  /**
   * Tests that invalidateByPath handles a non-entity route parameter.
   */
  public function testInvalidateByPathForUnknownEntityType(): void {
    $this->primeFourXxResponseCache('test:4xx_unknown');

    \Drupal::service('decoupled_router.cache_invalidation')
      ->invalidateByPath(['source' => '/test-decoupled-router/raw-nonentity-param/bar']);

    self::assertFalse(\Drupal::cache()->get('test:4xx_unknown'));
  }

  /**
   * Tests that invalidateByPath handles an empty parameter value.
   */
  public function testInvalidateByPathForEmptyParameterValue(): void {
    $this->primeFourXxResponseCache('test:4xx_empty');

    \Drupal::service('decoupled_router.cache_invalidation')
      ->invalidateByPath(['source' => '/test-decoupled-router/raw-nonentity-param/0']);

    self::assertFalse(\Drupal::cache()->get('test:4xx_empty'));
  }

}
