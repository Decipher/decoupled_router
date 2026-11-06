<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the resourceVersion link when the JSON:API URL already has a query.
 *
 * Outbound path processors add their own query parameters to a generated URL.
 * Session language negotiation is one of them: it repeats the language
 * parameter the client sent. The version has to join that query string rather
 * than start a second one, or the link JSON:API is handed is malformed and the
 * client is told to fetch a URL that cannot be fetched.
 *
 * @group decoupled_router
 * @coversDefaultClass \Drupal\decoupled_router\EventSubscriber\RouterPathTranslatorSubscriber
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class ResourceVersionQueryStringPathTranslatorTest extends KernelTestBase {

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
    'language',
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
    $this->installConfig(['user', 'system', 'language', 'decoupled_router']);
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

    ConfigurableLanguage::createFromLangcode('de')->save();

    // Negotiate the interface language from a query parameter. This is the
    // negotiation method that rewrites outbound URLs, so every generated URL
    // carries a query string before the version is added to it.
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-session' => 0])
      ->save();

    // Rebuild so the negotiator picks up the new language and config.
    $this->container->get('kernel')->rebuildContainer();

    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      ['access content', 'view test entity']
    );
  }

  /**
   * Translates a path and returns the status code and the decoded body.
   *
   * The request carries the language parameter, which is what makes the
   * negotiation method rewrite the URLs generated while handling it.
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
    $query = ['path' => $path, '_format' => 'json', 'language' => 'de'];
    if ($resource_version !== NULL) {
      $query['resourceVersion'] = $resource_version;
    }
    // Built by hand rather than with Url::fromRoute(), which would generate a
    // URL before the kernel handles the request. The session negotiation
    // method decides once per instance whether it rewrites outbound URLs, and
    // it would decide on that earlier request, which has no language.
    $request = Request::create('/router/translate-path?' . UrlHelper::buildQuery($query));

    $response = $this->container->get('http_kernel')->handle($request);
    $content = $response->getContent();
    return [$response->getStatusCode(), Json::decode($content === FALSE ? '' : $content)];
  }

  /**
   * Creates a published entity with a viewable pending revision on top.
   */
  protected function createEntityWithDraft(): void {
    $published = EntityTestRev::create([
      'name' => 'published',
      'path' => '/published',
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
   * Tests that the version joins a query string another processor has started.
   */
  public function testResourceVersionJoinsAnExistingQueryString(): void {
    $this->createEntityWithDraft();

    [$status, $data] = $this->translate('/published', 'rel:working-copy');

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('draft, view all revisions', $data['label']);

    $individual = $data['jsonapi']['individual'];
    self::assertSame(1, substr_count($individual, '?'), $individual);

    // Read the link back the way the server will read it, so that this holds
    // whichever order the parameters are generated in and whether or not the
    // colon is encoded.
    $query = Request::create($individual)->query;
    self::assertSame('rel:working-copy', $query->get('resourceVersion'), $individual);
    self::assertSame('de', $query->get('language'), $individual);
  }

  /**
   * Tests that the language parameter is there without a version to add.
   *
   * Without this the test above would pass on a link that never carried a
   * query string in the first place.
   */
  public function testLanguageParameterIsAddedWithNoVersion(): void {
    $this->createEntityWithDraft();

    [$status, $data] = $this->translate('/published');

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('published', $data['label']);

    $individual = $data['jsonapi']['individual'];
    $query = Request::create($individual)->query;
    self::assertSame('de', $query->get('language'), $individual);
    self::assertFalse($query->has('resourceVersion'), $individual);
  }

}
