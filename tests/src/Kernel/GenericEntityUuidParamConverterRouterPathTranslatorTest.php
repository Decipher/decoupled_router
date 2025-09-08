<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\ParamConverter\DynamicEntityTypeParamConverterTrait;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\Core\Url;
use Drupal\jsonapi\Routing\Routes;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests path translation when param converters have the name `entity_uuid`.
 *
 * @group decoupled_router
 * @coversDefaultClass \Drupal\decoupled_router\EventSubscriber\RouterPathTranslatorSubscriber
 */
final class GenericEntityUuidParamConverterRouterPathTranslatorTest extends KernelTestBase implements ParamConverterInterface {

  use DynamicEntityTypeParamConverterTrait;

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
  public function register(ContainerBuilder $container) {
    parent::register($container);

    // Parent class clears these tags.
    $container->getDefinition('path_alias.path_processor')
      ->addTag('path_processor_inbound', ['priority' => 100])
      ->addTag('path_processor_outbound', ['priority' => 300]);

    $container->register('paramconverter.test_decoupled_router.entity_uuid', self::class)
      ->addTag('paramconverter', ['priority' => 20]);
    $container->set('paramconverter.test_decoupled_router.entity_uuid', $this);
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
  }

  /**
   * Tests generating canonical URL from UUID #3116487.
   *
   * @testWith ["/entity_test/01deaea2-e5dc-4255-8d97-ba0543cf790b"]
   *           ["/entity_test/1"]
   *           ["/entity-test"]
   */
  public function testTranslationFromUuid(string $path): void {
    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      ['access content', 'view test entity']
    );

    $entity = $this->container->get('entity_type.manager')->getStorage('entity_test')
      ->create([
        'uuid' => '01deaea2-e5dc-4255-8d97-ba0543cf790b',
        'name' => 'test',
        'path' => '/entity-test',
      ]);
    $entity->save();

    $request = Request::create(
      Url::fromRoute('decoupled_router.path_translation', [], [
        'query' => [
          'path' => $path,
          '_format' => 'json',
        ],
      ])->toString()
    );

    $response = $this->container->get('http_kernel')->handle($request);
    $data = Json::decode($response->getContent());
    self::assertArrayHasKey('resolved', $data, var_export($data, TRUE));
    self::assertEquals(
      $entity->toUrl('canonical')->setAbsolute(TRUE)->toString(),
      $data['resolved']);
  }

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    if (!Uuid::isValid($value)) {
      return $this->container->get('paramconverter.entity')->convert($value, $definition, $name, $defaults);
    }
    $entity_type_id = $this->getEntityTypeFromDefaults($definition, $name, $defaults);
    $entity = $this->container->get('entity.repository')->loadEntityByUuid($entity_type_id, $value);
    return $entity === NULL ? NULL : $this->container->get('entity.repository')->getCanonical($entity_type_id, $entity->id());

  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    // Opposite of \Drupal\jsonapi\ParamConverter\EntityUuidConverter::applies.
    return (
      Routes::getResourceTypeNameFromParameters($route->getDefaults()) === NULL &&
      !empty($definition['type']) && str_starts_with($definition['type'], 'entity')
    );
  }

}
