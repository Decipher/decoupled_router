<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the resourceVersion parameter against content_moderation.
 *
 * A pending revision of a moderated entity needs more than view access and
 * revision access. content_moderation adds a latest-version check that reads
 * the entity off a route match, so the subscriber has to build one the way
 * JSON:API does. Nothing else in the suite runs that branch, and getting it
 * wrong reports a draft label that the JSON:API link then refuses with a 403.
 *
 * The permission sets below are chosen to isolate that branch: view access and
 * revision access both pass in every case, so only the latest-version check
 * decides the outcome.
 *
 * @group decoupled_router
 * @coversDefaultClass \Drupal\decoupled_router\EventSubscriber\RouterPathTranslatorSubscriber
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class ResourceVersionModerationPathTranslatorTest extends KernelTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'field',
    'filter',
    'text',
    'file',
    'node',
    'path',
    'path_alias',
    'serialization',
    'jsonapi',
    'workflows',
    'content_moderation',
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
    $this->installConfig(['user', 'system', 'field', 'filter', 'node', 'content_moderation', 'decoupled_router']);
    $this->installEntitySchema('user');
    $this->container->get('entity_type.manager')->getStorage('user')
      ->create([
        'uid' => 0,
        'status' => 0,
        'name' => '',
      ])
      ->save();

    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', 'node_access');

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();

    $workflow = $this->createEditorialWorkflow();
    $type_plugin = $workflow->getTypePlugin();
    if (!$type_plugin instanceof ContentModerationInterface) {
      self::fail('The editorial workflow does not use the content_moderation type plugin.');
    }
    $type_plugin->addEntityTypeAndBundle('node', 'page');
    $workflow->save();
  }

  /**
   * Creates a published node and a draft pending revision of it.
   *
   * @return \Drupal\node\NodeInterface
   *   The published default revision.
   */
  protected function createNodeWithDraft(): NodeInterface {
    $node = Node::create([
      'type' => 'page',
      'title' => 'published',
      'moderation_state' => 'published',
    ]);
    $node->save();

    $this->container->get('entity_type.manager')->getStorage('path_alias')
      ->create([
        'path' => '/node/' . $node->id(),
        'alias' => '/published',
      ])
      ->save();

    $draft = Node::load($node->id());
    if ($draft === NULL) {
      self::fail('The test node was not saved.');
    }
    $draft->set('title', 'draft');
    $draft->set('moderation_state', 'draft');
    $draft->save();

    return $node;
  }

  /**
   * Translates a path and returns the status code and the decoded body.
   *
   * @param string $path
   *   The path to translate.
   * @param string|null $resource_version
   *   The resourceVersion to send, if any.
   *
   * @return array
   *   The status code, and the decoded response body.
   */
  protected function translate(string $path, ?string $resource_version = NULL): array {
    $query = ['path' => $path, '_format' => 'json'];
    if ($resource_version !== NULL) {
      $query['resourceVersion'] = $resource_version;
    }
    $request = Request::create(
      Url::fromRoute('decoupled_router.path_translation', [], ['query' => $query])->toString()
    );

    $response = $this->container->get('http_kernel')->handle($request);
    $content = $response->getContent();
    return [$response->getStatusCode(), Json::decode($content === FALSE ? '' : $content)];
  }

  /**
   * Tests that the latest-version check can refuse a moderated draft.
   *
   * These permissions pass both the view check, because content_moderation
   * grants view on unpublished content, and the revision check. Only
   * "view latest version" is withheld, so a 403 here is the latest-version
   * check doing its work.
   */
  public function testModeratedDraftRefusedWithoutViewLatestVersion(): void {
    $this->createNodeWithDraft();
    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      ['access content', 'view all revisions', 'view any unpublished content']
    );

    [$status, $data] = $this->translate('/published', 'rel:working-copy');

    self::assertSame(403, $status, var_export($data, TRUE));
    self::assertArrayNotHasKey('label', $data);
  }

  /**
   * Tests that a moderated draft resolves with the moderation permissions.
   *
   * The same request as above, with "view latest version" added. Reaching the
   * draft label proves the faked route match satisfies content_moderation.
   */
  public function testModeratedDraftResolvesWithViewLatestVersion(): void {
    $node = $this->createNodeWithDraft();
    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      [
        'access content',
        'view all revisions',
        'view any unpublished content',
        'view latest version',
      ]
    );

    [$status, $data] = $this->translate('/published', 'rel:working-copy');

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('draft', $data['label']);
    self::assertSame((string) $node->id(), (string) ($data['entity']['id'] ?? ''));
    $expected = UrlHelper::buildQuery(['resourceVersion' => 'rel:working-copy']);
    self::assertStringEndsWith('?' . $expected, $data['jsonapi']['individual']);
  }

  /**
   * Tests that the published default revision is unaffected.
   */
  public function testPublishedDefaultRevisionStillResolves(): void {
    $this->createNodeWithDraft();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);

    [$status, $data] = $this->translate('/published');

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('published', $data['label']);
    self::assertStringNotContainsString('resourceVersion', $data['jsonapi']['individual']);
  }

}
