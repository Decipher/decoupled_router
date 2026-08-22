<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\Core\Url;
use Drupal\decoupled_router\EventSubscriber\RouterPathTranslatorSubscriber;
use Drupal\decoupled_router\PathTranslatorEvent;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

/**
 * Tests path translation directly at the router level, without a redirect.
 *
 * @group decoupled_router
 * @coversDefaultClass \Drupal\decoupled_router\EventSubscriber\RouterPathTranslatorSubscriber
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class RouterPathTranslatorSubscriberTest extends KernelTestBase implements ParamConverterInterface {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
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
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('paramconverter.test_decoupled_router.malformed_entity', self::class)
      ->addTag('paramconverter', ['priority' => 20]);
    $container->set('paramconverter.test_decoupled_router.malformed_entity', $this);
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
   * Sends a translate-path request in-process and returns the raw response.
   */
  protected function translatePathResponse(string $path): Response {
    $request = Request::create(
      Url::fromRoute('decoupled_router.path_translation', [], [
        'query' => ['path' => $path, '_format' => 'json'],
      ])->toString()
    );
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Decodes a response body, tolerating an empty/false body.
   */
  protected function decode(Response $response): array {
    $content = $response->getContent();
    return Json::decode($content === FALSE ? '' : $content);
  }

  /**
   * Tests that an external path is resolved without touching the router.
   *
   * The controller entry point always prefixes the "path" query parameter
   * with "/", which makes a raw external URL passed that way look internal.
   * A genuinely external path only reaches the subscriber via setPath()
   * (as a redirect to an external destination would do), so this test
   * invokes the subscriber directly rather than going through the
   * translate-path route.
   */
  public function testExternalUrlIsResolvedWithoutRouting(): void {
    $event = new PathTranslatorEvent(
      $this->container->get('http_kernel'),
      Request::create('/router/translate-path'),
      HttpKernelInterface::MAIN_REQUEST,
      'http://example.com/foo'
    );

    $this->container->get('decoupled_router.router_path_translator.subscriber')
      ->onPathTranslation($event);

    $response = $event->getResponse();
    $data = $this->decode($response);

    self::assertSame(200, $response->getStatusCode());
    self::assertSame('http://example.com/foo', $data['resolved']);
    self::assertTrue($data['isExternal']);
    self::assertFalse($data['isHomePath']);
  }

  /**
   * Tests a path that carries a query string and a fragment #3397122.
   *
   * The path must still match its route, and both the query string and the
   * fragment must survive into the resolved URL.
   */
  public function testQueryStringAndFragmentArePreserved(): void {
    $entity = $this->container->get('entity_type.manager')->getStorage('entity_test')
      ->create(['name' => 'test', 'path' => '/entity-test', 'user_id' => 0]);
    $entity->save();

    $response = $this->translatePathResponse('/entity-test?foo=bar#content-id-fragment');
    $data = $this->decode($response);

    self::assertSame(200, $response->getStatusCode(), var_export($data, TRUE));
    self::assertStringContainsString('foo=bar', $data['resolved']);
    self::assertStringEndsWith('#content-id-fragment', $data['resolved']);
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests that a path with no matching route at all leaves the default 404.
   */
  public function testUnresolvablePathReturns404(): void {
    $response = $this->translatePathResponse('/this-path-does-not-exist-anywhere');

    self::assertSame(404, $response->getStatusCode());
    $data = $this->decode($response);
    self::assertArrayNotHasKey('resolved', $data, var_export($data, TRUE));
  }

  /**
   * Tests that a path whose route disallows GET results in a 403.
   */
  public function testMethodNotAllowedPathReturns403(): void {
    $response = $this->translatePathResponse('/test-decoupled-router/post-only');

    self::assertSame(403, $response->getStatusCode());
  }

  /**
   * Tests that a route with no resolvable entity leaves the default 404.
   */
  public function testRouteWithoutEntityInfoReturns404(): void {
    $response = $this->translatePathResponse('/user/login');

    self::assertSame(404, $response->getStatusCode());
    $data = $this->decode($response);
    self::assertArrayNotHasKey('resolved', $data, var_export($data, TRUE));
  }

  /**
   * Tests a route whose entity parameter is upcast under the "entity" key.
   *
   * A canonical entity route (like /entity_test/{entity_test}) names its
   * parameter after the entity type, so findEntityAndKeys() finds it via
   * the entity-type-lookup branch. This route instead names its converted
   * parameter literally "entity" (as generic/dynamic entity routes do),
   * exercising the other branch.
   */
  public function testGenericEntityParameterIsResolvedDirectly(): void {
    $entity = $this->container->get('entity_type.manager')->getStorage('entity_test')
      ->create(['name' => 'test', 'user_id' => 0]);
    $entity->save();

    $response = $this->translatePathResponse('/test-decoupled-router/generic-entity-param/' . $entity->id());
    $data = $this->decode($response);

    self::assertSame(200, $response->getStatusCode(), var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests that a resolved entity with no ID yields a 500 response.
   *
   * Generating the canonical URL throws EntityMalformedException for an
   * entity with no ID (i.e. unsaved). This is normally unreachable, since
   * routing only ever upcasts persisted entities, so this test uses a
   * param converter (registered below) that deliberately returns an
   * unsaved one.
   */
  public function testUnsavedResolvedEntityReturns500(): void {
    $response = $this->translatePathResponse('/test-decoupled-router/malformed-entity-param/anything');

    self::assertSame(500, $response->getStatusCode());
    $data = $this->decode($response);
    self::assertSame('Unable to build entity URL.', $data['message']);
  }

  /**
   * Invokes the protected resolvedPathIsHomePath() method via reflection.
   */
  protected function invokeResolvedPathIsHomePath(string|Url $resolved_url, ?CacheableMetadata $cacheable_metadata = NULL): bool {
    $subscriber = $this->container->get('decoupled_router.router_path_translator.subscriber');
    $method = new \ReflectionMethod(RouterPathTranslatorSubscriber::class, 'resolvedPathIsHomePath');
    return $method->invoke($subscriber, $resolved_url, $cacheable_metadata);
  }

  /**
   * Asserts that a callback triggers a deprecation containing $needle.
   *
   * The framework's own expectDeprecation()/expectUserDeprecationMessage*()
   * methods match the *entire* accumulated deprecation buffer for the test,
   * including unrelated framework-internal deprecations (Twig, Doctrine)
   * that happen to fire during the same Kernel boot. That makes them
   * unreliable here, so this captures the specific deprecation directly
   * instead.
   */
  protected function assertTriggersDeprecation(callable $callback, string $needle): void {
    $messages = [];
    set_error_handler(function (int $errno, string $errstr) use (&$messages): bool {
      $messages[] = $errstr;
      return TRUE;
    }, E_USER_DEPRECATED);
    try {
      $callback();
    }
    finally {
      restore_error_handler();
    }
    self::assertNotEmpty(
      array_filter($messages, static fn(string $message): bool => str_contains($message, $needle)),
      sprintf('Expected a deprecation containing "%s", got: %s', $needle, implode(' | ', $messages))
    );
  }

  /**
   * Tests that omitting $cacheable_metadata triggers a deprecation.
   */
  public function testMissingCacheableMetadataTriggersDeprecation(): void {
    $this->assertTriggersDeprecation(
      fn(): bool => $this->invokeResolvedPathIsHomePath(Url::fromRoute('user.login')),
      '$cacheable_metadata not being an instance'
    );
  }

  /**
   * Tests that passing a string $resolved_url triggers a deprecation.
   */
  public function testStringResolvedUrlTriggersDeprecation(): void {
    $this->assertTriggersDeprecation(
      fn(): bool => $this->invokeResolvedPathIsHomePath('/some/path', new CacheableMetadata()),
      '$resolved_url not being an instance'
    );
  }

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    return $this->container->get('entity_type.manager')->getStorage('entity_test')
      ->create(['name' => 'unsaved']);
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route): bool {
    // Route parameter conversion looks up an explicitly named converter
    // (see the "converter" option on the malformed_entity_param route)
    // directly by service ID, without consulting applies(). But this
    // converter is also considered, via normal applies()-based selection,
    // for every other entity-typed parameter in the same request, so it
    // must restrict itself to its own route to avoid hijacking them.
    return str_contains($route->getPath(), 'malformed-entity-param');
  }

}
