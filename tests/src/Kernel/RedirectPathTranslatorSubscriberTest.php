<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\redirect\Entity\Redirect;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests redirect resolution via RedirectPathTranslatorSubscriber.
 *
 * Functional tests exercise this class too (DecoupledRouterFunctionalTest),
 * but BrowserTestBase dispatches the request to a separate spawned server
 * process, so PHPUnit's code coverage driver never sees it execute. Kernel
 * tests run the request in-process via http_kernel, so they are what
 * actually contributes to measured coverage for this class.
 *
 * @group decoupled_router
 * @coversDefaultClass \Drupal\decoupled_router\EventSubscriber\RedirectPathTranslatorSubscriber
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class RedirectPathTranslatorSubscriberTest extends KernelTestBase {

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
    'redirect',
    'decoupled_router',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'system', 'redirect', 'decoupled_router']);
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
    $this->installEntitySchema('redirect');

    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      ['access content', 'view test entity']
    );
  }

  /**
   * Creates an entity_test entity reachable at the given path.
   */
  protected function createTestEntity(string $path): ContentEntityInterface {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->container->get('entity_type.manager')->getStorage('entity_test')
      ->create([
        'name' => 'test',
        'path' => $path,
      ]);
    $entity->save();
    return $entity;
  }

  /**
   * Sends a translate-path request in-process and decodes the JSON response.
   *
   * @return array
   *   The decoded response body.
   */
  protected function translatePath(string $path): array {
    $request = Request::create(
      Url::fromRoute('decoupled_router.path_translation', [], [
        'query' => [
          'path' => $path,
          '_format' => 'json',
        ],
      ])->toString()
    );
    $response = $this->container->get('http_kernel')->handle($request);
    $content = $response->getContent();
    return Json::decode($content === FALSE ? '' : $content);
  }

  /**
   * Tests that a matching redirect resolves through to its target entity.
   */
  public function testRedirectResolvesToEntity(): void {
    $entity = $this->createTestEntity('/entity-test');

    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/old-path');
    $redirect->setRedirect('/entity-test');
    $redirect->save();

    $data = $this->translatePath('/old-path');

    self::assertArrayHasKey('redirect', $data, var_export($data, TRUE));
    self::assertSame('/old-path', $data['redirect'][0]['from']);
    self::assertSame('301', $data['redirect'][0]['status']);
    self::assertArrayHasKey('resolved', $data);
    self::assertEquals(
      $entity->toUrl('canonical')->setAbsolute(TRUE)->toString(),
      $data['resolved']
    );
  }

  /**
   * Tests that redirect query strings are passed through when configured.
   */
  public function testRedirectPassthroughQuerystring(): void {
    $this->config('redirect.settings')->set('passthrough_querystring', TRUE)->save();
    $this->createTestEntity('/entity-test');

    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/old-path');
    $redirect->setRedirect('/entity-test');
    $redirect->save();

    $data = $this->translatePath('/old-path?foo=bar');

    self::assertArrayHasKey('resolved', $data, var_export($data, TRUE));
    self::assertStringContainsString('foo=bar', $data['resolved']);
  }

  /**
   * Tests that a path with no matching redirect falls through to the router.
   */
  public function testNoRedirectFallsThroughToRouter(): void {
    $this->createTestEntity('/entity-test');

    $data = $this->translatePath('/entity-test');

    self::assertArrayNotHasKey('redirect', $data, var_export($data, TRUE));
    self::assertArrayHasKey('resolved', $data);
  }

  /**
   * Tests a redirect to a path the router cannot resolve at all.
   *
   * The router leaves the response at its default 404, so the subscriber
   * takes its 404 fallback branch: it reports the redirect target as
   * "resolved" without any entity information attached.
   */
  public function testRedirectToUnresolvablePathReturnsFallbackData(): void {
    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/old-path');
    $redirect->setRedirect('/does-not-exist-at-all');
    $redirect->save();

    $data = $this->translatePath('/old-path');

    self::assertArrayHasKey('redirect', $data, var_export($data, TRUE));
    self::assertSame('/old-path', $data['redirect'][0]['from']);
    self::assertArrayHasKey('resolved', $data);
    self::assertStringContainsString('/does-not-exist-at-all', $data['resolved']);
    self::assertArrayNotHasKey('entity', $data);
  }

  /**
   * Tests that a redirect loop does not cause a 500 error.
   *
   * Two redirects that point at each other form a cycle. The redirect
   * module detects this and throws RedirectLoopException from
   * findMatchingRedirect(). Without the catch in the subscriber, this
   * would bubble up as a 500 response.
   */
  public function testRedirectLoopDoesNotReturnError(): void {
    $redirect_a = Redirect::create(['status_code' => 301]);
    $redirect_a->setSource('/loop-a');
    $redirect_a->setRedirect('/loop-b');
    $redirect_a->save();

    $redirect_b = Redirect::create(['status_code' => 301]);
    $redirect_b->setSource('/loop-b');
    $redirect_b->setRedirect('/loop-a');
    $redirect_b->save();

    $request = Request::create(
      Url::fromRoute('decoupled_router.path_translation', [], [
        'query' => ['path' => '/loop-a', '_format' => 'json'],
      ])->toString()
    );
    $response = $this->container->get('http_kernel')->handle($request);

    self::assertNotSame(500, $response->getStatusCode());

    $content = $response->getContent();
    $data = Json::decode($content === FALSE ? '' : $content);
    self::assertArrayNotHasKey('redirect', $data, var_export($data, TRUE));
  }

  /**
   * Tests that valid redirects still work when a loop exists elsewhere.
   *
   * A loop on one pair of paths should not prevent a separate, valid
   * redirect from resolving correctly.
   */
  public function testValidRedirectWorksAlongsideLoop(): void {
    $entity = $this->createTestEntity('/valid-redirect-target');

    $valid_redirect = Redirect::create(['status_code' => 301]);
    $valid_redirect->setSource('/old-valid-path');
    $valid_redirect->setRedirect('/valid-redirect-target');
    $valid_redirect->save();

    $loop_a = Redirect::create(['status_code' => 301]);
    $loop_a->setSource('/loop-x');
    $loop_a->setRedirect('/loop-y');
    $loop_a->save();

    $loop_b = Redirect::create(['status_code' => 301]);
    $loop_b->setSource('/loop-y');
    $loop_b->setRedirect('/loop-x');
    $loop_b->save();

    $data = $this->translatePath('/old-valid-path');

    self::assertArrayHasKey('redirect', $data, var_export($data, TRUE));
    self::assertSame('/old-valid-path', $data['redirect'][0]['from']);
    self::assertArrayHasKey('resolved', $data, var_export($data, TRUE));
    self::assertEquals(
      $entity->toUrl('canonical')->setAbsolute(TRUE)->toString(),
      $data['resolved']
    );
  }

  /**
   * Tests a redirect to an entity the anonymous user cannot view.
   *
   * The router sets a 403 response, which is neither "successful" nor 404,
   * so the subscriber returns without attaching redirect trace data.
   */
  public function testRedirectToForbiddenEntityIsNotIntercepted(): void {
    $this->createTestEntity('/entity-test');
    user_role_revoke_permissions(RoleInterface::ANONYMOUS_ID, ['view test entity']);

    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/old-path');
    $redirect->setRedirect('/entity-test');
    $redirect->save();

    $request = Request::create(
      Url::fromRoute('decoupled_router.path_translation', [], [
        'query' => ['path' => '/old-path', '_format' => 'json'],
      ])->toString()
    );
    $response = $this->container->get('http_kernel')->handle($request);

    self::assertSame(403, $response->getStatusCode());
    $content = $response->getContent();
    $data = Json::decode($content === FALSE ? '' : $content);
    self::assertArrayNotHasKey('redirect', $data, var_export($data, TRUE));
  }

}
