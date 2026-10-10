<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests path translation with the JSON:API resourceVersion parameter.
 *
 * A client that previews content sends resourceVersion with the path. The
 * label in the response and the JSON:API link must both describe the revision
 * the client asked for, or the preview shows published content under a draft
 * label, or the other way round.
 *
 * Revision access on entity_test_rev is granted by the revision label: a
 * revision whose label contains "view all revisions" may be viewed.
 *
 * @group decoupled_router
 * @coversDefaultClass \Drupal\decoupled_router\EventSubscriber\RouterPathTranslatorSubscriber
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class ResourceVersionPathTranslatorTest extends KernelTestBase {

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

    $this->container->get('state')->set('entity_test_rev.additional_base_field_definitions', [
      'path' => BaseFieldDefinition::create('path')->setComputed(TRUE),
    ]);

    $this->installEntitySchema('entity_test_rev');
    $this->installEntitySchema('path_alias');

    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      ['access content', 'view test entity']
    );
  }

  /**
   * Creates a published entity and saves a pending revision on top of it.
   *
   * @param string $draft_label
   *   The label of the pending revision.
   * @param string $alias
   *   The path alias of the entity.
   *
   * @return array{\Drupal\entity_test\Entity\EntityTestRev, \Drupal\entity_test\Entity\EntityTestRev}
   *   The default revision and the pending revision.
   */
  protected function createEntityWithDraft(string $draft_label, string $alias = '/published'): array {
    $published = EntityTestRev::create([
      'name' => 'published',
      'path' => $alias,
    ]);
    $published->save();

    $draft = EntityTestRev::load($published->id());
    if ($draft === NULL) {
      self::fail('The test entity was not saved.');
    }
    $draft->setNewRevision();
    $draft->isDefaultRevision(FALSE);
    $draft->set('name', $draft_label);
    $draft->save();

    return [$published, $draft];
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
   * Tests that the working copy resolves to the pending revision.
   */
  public function testWorkingCopyResolvesToPendingRevision(): void {
    [$published] = $this->createEntityWithDraft('draft, view all revisions');

    [$status, $data] = $this->translate('/published', 'rel:working-copy');

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('draft, view all revisions', $data['label']);
    self::assertSame($published->id(), $data['entity']['id']);
    self::assertStringEndsWith(
      '/jsonapi/entity_test_rev/entity_test_rev/' . $published->uuid() . '?resourceVersion=rel:working-copy',
      $data['jsonapi']['individual']
    );
  }

  /**
   * Tests that a request without resourceVersion is unchanged.
   */
  public function testNoResourceVersionResolvesToDefaultRevision(): void {
    [$published] = $this->createEntityWithDraft('draft, view all revisions');

    [$status, $data] = $this->translate('/published');

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('published', $data['label']);
    self::assertStringEndsWith(
      '/jsonapi/entity_test_rev/entity_test_rev/' . $published->uuid(),
      $data['jsonapi']['individual']
    );
  }

  /**
   * Tests that a revision ID selects that revision of the entity.
   */
  public function testRevisionIdResolvesToThatRevision(): void {
    [, $draft] = $this->createEntityWithDraft('draft, view all revisions');

    [$status, $data] = $this->translate('/published', 'id:' . $draft->getRevisionId());

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('draft, view all revisions', $data['label']);
    self::assertStringEndsWith('?resourceVersion=id:' . $draft->getRevisionId(), $data['jsonapi']['individual']);
  }

  /**
   * Tests that a revision ID of another entity is not used for this path.
   *
   * Revision IDs are shared by every entity of a type. Loading one without
   * checking its owner reports another entity's label at this path.
   */
  public function testRevisionIdOfAnotherEntityIsIgnored(): void {
    $this->createEntityWithDraft('draft, view all revisions');
    [, $other_draft] = $this->createEntityWithDraft('other, view all revisions', '/other');

    [$status, $data] = $this->translate('/published', 'id:' . $other_draft->getRevisionId());

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('published', $data['label']);
    self::assertStringNotContainsString('resourceVersion', $data['jsonapi']['individual']);
  }

  /**
   * Tests that a version JSON:API does not understand is dropped.
   */
  #[TestWith(['rel:bogus'])]
  #[TestWith(['id:abc'])]
  #[TestWith(['bogus'])]
  public function testUnknownResourceVersionIsIgnored(string $resource_version): void {
    $this->createEntityWithDraft('draft, view all revisions');

    [$status, $data] = $this->translate('/published', $resource_version);

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('published', $data['label']);
    self::assertStringNotContainsString('resourceVersion', $data['jsonapi']['individual']);
  }

  /**
   * Tests that the latest version stays on the default revision.
   */
  public function testLatestVersionResolvesToDefaultRevision(): void {
    $this->createEntityWithDraft('draft, view all revisions');

    [$status, $data] = $this->translate('/published', 'rel:latest-version');

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('published', $data['label']);
    self::assertStringEndsWith('?resourceVersion=rel:latest-version', $data['jsonapi']['individual']);
  }

  /**
   * Tests that a pending revision needs revision access, as in JSON:API.
   *
   * View access to the entity is not enough. Without revision access the
   * JSON:API link returns 403, so this response must not report the label.
   */
  public function testWorkingCopyRequiresRevisionAccess(): void {
    $this->createEntityWithDraft('draft');

    [$status, $data] = $this->translate('/published', 'rel:working-copy');

    self::assertSame(403, $status, var_export($data, TRUE));
    self::assertArrayNotHasKey('label', $data);
  }

}
