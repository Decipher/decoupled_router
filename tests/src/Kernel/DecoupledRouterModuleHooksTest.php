<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\path_alias\Entity\PathAlias;

/**
 * Tests decoupled_router.module hook implementations.
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
  protected function primeFourXxResponseCache(): void {
    \Drupal::cache()->set('decoupled_router_test:4xx', 'cached', Cache::PERMANENT, ['4xx-response']);
    self::assertNotFalse(\Drupal::cache()->get('decoupled_router_test:4xx'));
  }

  /**
   * Tests that hook_path_update() invalidates cached 404/403 responses.
   */
  public function testPathUpdateInvalidatesFourXxResponseCache(): void {
    $this->primeFourXxResponseCache();

    decoupled_router_path_update(['source' => '/nonexistent-source']);

    self::assertFalse(\Drupal::cache()->get('decoupled_router_test:4xx'));
  }

  /**
   * Tests that hook_path_delete() invalidates cached 404/403 responses.
   */
  public function testPathDeleteInvalidatesFourXxResponseCache(): void {
    $this->primeFourXxResponseCache();

    decoupled_router_path_delete(['source' => '/nonexistent-source']);

    self::assertFalse(\Drupal::cache()->get('decoupled_router_test:4xx'));
  }

  /**
   * Tests that saving a new path alias invalidates cached 404/403 responses.
   */
  public function testPathAliasInsertInvalidatesFourXxResponseCache(): void {
    $this->primeFourXxResponseCache();

    PathAlias::create([
      'path' => '/nonexistent-source',
      'alias' => '/aliased-nonexistent-source',
    ])->save();

    self::assertFalse(\Drupal::cache()->get('decoupled_router_test:4xx'));
  }

  /**
   * Tests a path whose route parameter name is a real entity type ID.
   *
   * A normal entity route (like /entity_test/{entity_test}) requires the
   * entity to exist just to validate the URL, since its parameter is
   * upcast via an entity param converter. The test route used here has an
   * unconverted "{entity_test}" parameter instead, so the URL validates
   * from its pattern alone and getTagsBySourcePath() has to load the
   * (non-existent) entity itself and fail.
   */
  public function testPathUpdateForNonExistentEntityInvalidatesFourXxResponseCache(): void {
    $this->primeFourXxResponseCache();

    decoupled_router_path_update(['source' => '/test-decoupled-router/raw-entity-test-param/999999']);

    self::assertFalse(\Drupal::cache()->get('decoupled_router_test:4xx'));
  }

  /**
   * Tests a path whose route parameter name is not a real entity type ID.
   */
  public function testPathUpdateForUnknownEntityTypeInvalidatesFourXxResponseCache(): void {
    $this->primeFourXxResponseCache();

    decoupled_router_path_update(['source' => '/test-decoupled-router/raw-nonentity-param/bar']);

    self::assertFalse(\Drupal::cache()->get('decoupled_router_test:4xx'));
  }

  /**
   * Tests a path whose route parameter value is empty.
   */
  public function testPathUpdateForEmptyParameterValueInvalidatesFourXxResponseCache(): void {
    $this->primeFourXxResponseCache();

    decoupled_router_path_update(['source' => '/test-decoupled-router/raw-nonentity-param/0']);

    self::assertFalse(\Drupal::cache()->get('decoupled_router_test:4xx'));
  }

}
