<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests path translation when the matched route is a JSON:API route.
 *
 * A JSON:API individual route takes the entity UUID in its parameter. It
 * cannot accept an entity ID. The resolved URL must therefore use the UUID,
 * or it points at a path that does not exist.
 *
 * @group decoupled_router
 * @coversDefaultClass \Drupal\decoupled_router\EventSubscriber\RouterPathTranslatorSubscriber
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class JsonapiIndividualPathTranslatorTest extends KernelTestBase {

  /**
   * The UUID given to the test entity.
   */
  private const ENTITY_UUID = '01deaea2-e5dc-4255-8d97-ba0543cf790b';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'text',
    'file',
    'entity_test',
    'path',
    'path_alias',
    'serialization',
    'jsonapi',
    'decoupled_router',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    // The parent class clears these tags.
    $container->getDefinition('path_alias.path_processor')
      ->addTag('path_processor_inbound', ['priority' => 100])
      ->addTag('path_processor_outbound', ['priority' => 300]);

    $container->set('cache.data', new NullBackend('data'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'system', 'decoupled_router']);
    $this->installEntitySchema('user');
    $this->container->get('entity_type.manager')->getStorage('user')
      ->create([
        'uid' => 0,
        'status' => 0,
        'name' => '',
      ])
      ->save();

    $this->container->get('state')->set('entity_test.additional_base_field_definitions', [
      'path' => BaseFieldDefinition::create('path')->setComputed(TRUE),
    ]);

    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('path_alias');

    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      ['access content', 'view test entity']
    );
  }

  /**
   * Creates the test entity with a known UUID and path alias.
   */
  protected function createTestEntity(): EntityInterface {
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create([
        'uuid' => self::ENTITY_UUID,
        'name' => 'test',
        'path' => '/entity-test',
      ]);
    $entity->save();
    return $entity;
  }

  /**
   * Translates a path and returns the decoded response body.
   */
  protected function translate(string $path): array {
    $request = Request::create(
      Url::fromRoute('decoupled_router.path_translation', [], [
        'query' => ['path' => $path, '_format' => 'json'],
      ])->toString()
    );

    $response = $this->container->get('http_kernel')->handle($request);
    $content = $response->getContent();
    return Json::decode($content === FALSE ? '' : $content);
  }

  /**
   * Tests that a JSON:API individual path resolves to the UUID form #3411402.
   *
   * The JSON:API parameter converter only accepts a UUID. Substituting the
   * entity ID gives a URL that returns a 404.
   */
  public function testJsonapiIndividualPathResolvesToUuid(): void {
    $this->createTestEntity();

    $path = '/jsonapi/entity_test/entity_test/' . self::ENTITY_UUID;
    $data = $this->translate($path);

    self::assertArrayHasKey('resolved', $data, var_export($data, TRUE));
    self::assertSame(
      'http://localhost/jsonapi/entity_test/entity_test/' . self::ENTITY_UUID,
      $data['resolved']
    );
  }

  /**
   * Tests that the resolved JSON:API URL is a route the site can serve.
   *
   * This is the check that fails when the entity ID is used. The generated
   * URL parses back to the same route and the same entity only if the
   * parameter holds a UUID.
   */
  public function testResolvedJsonapiUrlMatchesTheSameRoute(): void {
    $entity = $this->createTestEntity();

    $path = '/jsonapi/entity_test/entity_test/' . self::ENTITY_UUID;
    $data = $this->translate($path);

    $resolved_path = parse_url((string) $data['resolved'], PHP_URL_PATH);
    if (!is_string($resolved_path)) {
      self::fail('The resolved URL has no path: ' . var_export($data['resolved'], TRUE));
    }

    $match = $this->container->get('router.no_access_checks')->match($resolved_path);

    self::assertSame('jsonapi.entity_test--entity_test.individual', $match['_route']);
    self::assertSame($entity->id(), $match['entity']->id());
  }

  /**
   * Tests that a canonical path still resolves to the entity ID form.
   *
   * The fix for the JSON:API case must not change routes that take an ID.
   * This covers the alias and the canonical path.
   *
   * @testWith ["/entity-test"]
   *           ["/entity_test/1"]
   */
  #[TestWith(['/entity-test'])]
  #[TestWith(['/entity_test/1'])]
  public function testCanonicalPathResolvesToEntityId(string $path): void {
    $entity = $this->createTestEntity();

    $data = $this->translate($path);

    self::assertArrayHasKey('resolved', $data, var_export($data, TRUE));
    self::assertSame(
      $entity->toUrl('canonical')->setAbsolute(TRUE)->toString(),
      $data['resolved']
    );
  }

}
