<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that a resourceVersion response records the access result it used.
 *
 * Revision access can differ from one user to the next, so a response that
 * describes a draft has to vary by whatever the access check depended on.
 * Without that, the first user who may see the draft puts its label in the
 * cache and the next user is served it, whatever the check would have said.
 *
 * Core's own access handlers depend only on things the response already
 * records by other means, so the test module adds a cache tag of its own to
 * the access result. That stands in for a site whose access varies per user
 * through hook_entity_access(), which is the case this guards.
 *
 * @group decoupled_router
 * @coversDefaultClass \Drupal\decoupled_router\EventSubscriber\RouterPathTranslatorSubscriber
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class ResourceVersionAccessCacheabilityPathTranslatorTest extends KernelTestBase {

  /**
   * The cache tag the test module puts on the revision access result.
   */
  private const REVISION_ACCESS_TAG = 'test_decoupled_router:view_all_revisions';

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
    'test_decoupled_router',
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

    $this->container->get('state')->set('entity_test_rev.additional_base_field_definitions', [
      'path' => BaseFieldDefinition::create('path')->setComputed(TRUE),
    ]);

    $this->installEntitySchema('entity_test_rev');
    $this->installEntitySchema('path_alias');

    // Ask the test module to tag the access results it sees.
    $this->container->get('state')->set('test_decoupled_router.tag_access', TRUE);

    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      ['access content', 'view test entity']
    );
  }

  /**
   * Translates a path and returns the response.
   *
   * @param string $path
   *   The path to translate.
   * @param string|null $resource_version
   *   The resourceVersion to send, if any.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function handle(string $path, ?string $resource_version = NULL) {
    $query = ['path' => $path, '_format' => 'json'];
    if ($resource_version !== NULL) {
      $query['resourceVersion'] = $resource_version;
    }
    $request = Request::create(
      Url::fromRoute('decoupled_router.path_translation', [], ['query' => $query])->toString()
    );

    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Creates a published entity and a viewable pending revision on top of it.
   */
  protected function createEntityWithDraft(): void {
    $published = EntityTestRev::create([
      'name' => 'published',
      'path' => '/published',
      // The test module's info alter hook reads the owner.
      'user_id' => 0,
    ]);
    $published->save();

    $draft = EntityTestRev::load($published->id());
    if ($draft === NULL) {
      self::fail('The test entity was not saved.');
    }
    $draft->setNewRevision();
    $draft->isDefaultRevision(FALSE);
    $draft->set('name', 'draft, view all revisions');
    $draft->save();
  }

  /**
   * Returns the decoded body of a response.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response to read.
   *
   * @return mixed
   *   The decoded body.
   */
  protected function decode($response) {
    $content = $response->getContent();
    return Json::decode($content === FALSE ? '' : $content);
  }

  /**
   * Tests that an allowed draft response records the revision access result.
   */
  public function testAllowedDraftRecordsRevisionAccessCacheability(): void {
    $this->createEntityWithDraft();

    $response = $this->handle('/published', 'rel:working-copy');
    $data = $this->decode($response);

    self::assertSame(200, $response->getStatusCode(), var_export($data, TRUE));
    self::assertSame('draft, view all revisions', $data['label']);
    self::assertInstanceOf(CacheableResponseInterface::class, $response);
    self::assertContains(
      self::REVISION_ACCESS_TAG,
      $response->getCacheableMetadata()->getCacheTags(),
      'The allowed response has to carry the cacheability of the revision access check.'
    );
  }

  /**
   * Tests that a refused draft response records it too.
   *
   * This path already did, and keeping a test on it means the fix for the
   * allowed path cannot be made by moving the call rather than adding one.
   */
  public function testRefusedDraftRecordsRevisionAccessCacheability(): void {
    $published = EntityTestRev::create([
      'name' => 'published',
      'path' => '/published',
      // The test module's info alter hook reads the owner.
      'user_id' => 0,
    ]);
    $published->save();

    $draft = EntityTestRev::load($published->id());
    if ($draft === NULL) {
      self::fail('The test entity was not saved.');
    }
    $draft->setNewRevision();
    $draft->isDefaultRevision(FALSE);
    $draft->set('name', 'draft');
    $draft->save();

    $response = $this->handle('/published', 'rel:working-copy');
    $data = $this->decode($response);

    self::assertSame(403, $response->getStatusCode(), var_export($data, TRUE));
    self::assertInstanceOf(CacheableResponseInterface::class, $response);
    self::assertContains(
      self::REVISION_ACCESS_TAG,
      $response->getCacheableMetadata()->getCacheTags()
    );
  }

}
