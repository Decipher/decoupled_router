<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the resourceVersion parameter together with translations.
 *
 * The revision and the translation are selected one after the other, so a
 * mistake in either order is invisible until both are in play: the response
 * either describes the right revision in the wrong language, or the right
 * language of the wrong revision. A preview of a draft translation shows the
 * published text in that case, which is the bug this covers.
 *
 * Revision access on entity_test_mulrev is granted by the revision label, and
 * the label varies per translation, so the label also shows which translation
 * the access check read.
 *
 * @group decoupled_router
 * @coversDefaultClass \Drupal\decoupled_router\EventSubscriber\RouterPathTranslatorSubscriber
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class ResourceVersionTranslationPathTranslatorTest extends KernelTestBase {

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
    'entity_test',
    'path',
    'path_alias',
    'serialization',
    'jsonapi',
    'language',
    'content_translation',
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

    $this->installEntitySchema('entity_test_mulrev');
    $this->installEntitySchema('path_alias');

    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'de' => 'de'])
      ->save();
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-url' => 0])
      ->save();

    // Rebuild so the negotiator picks up the new language and config.
    $this->container->get('kernel')->rebuildContainer();

    // Viewing a translation needs its own permission on entity_test, or every
    // prefixed request is refused before the revision logic is reached.
    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      ['access content', 'view test entity', 'view test entity translations']
    );
  }

  /**
   * Creates a translated entity and a pending revision of both translations.
   *
   * @param string $en_draft_label
   *   The label the pending revision carries in English.
   * @param string $de_draft_label
   *   The label the pending revision carries in German.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The default revision, in its original language.
   */
  protected function createTranslatedEntityWithDraft(string $en_draft_label, string $de_draft_label): ContentEntityInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test_mulrev');
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $storage->create([
      'name' => 'published en',
      'langcode' => 'en',
    ]);
    $entity->save();
    $entity->addTranslation('de', ['name' => 'veroeffentlicht de'])->save();

    $alias_storage = $this->container->get('entity_type.manager')->getStorage('path_alias');
    $system_path = '/entity_test_mulrev/manage/' . $entity->id();
    $alias_storage->create([
      'path' => $system_path,
      'alias' => '/published',
      'langcode' => 'en',
    ])->save();
    $alias_storage->create([
      'path' => $system_path,
      'alias' => '/veroeffentlicht',
      'langcode' => 'de',
    ])->save();

    /** @var \Drupal\Core\Entity\ContentEntityInterface $draft */
    $draft = $storage->load($entity->id());
    $draft->setNewRevision();
    $draft->isDefaultRevision(FALSE);
    $draft->set('name', $en_draft_label);
    $draft->getTranslation('de')->set('name', $de_draft_label);
    $draft->save();

    return $entity;
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
   * Tests that a prefixed path reports the draft in that language.
   */
  public function testWorkingCopyOnPrefixedPathUsesTranslatedRevision(): void {
    $this->createTranslatedEntityWithDraft(
      'draft en, view all revisions',
      'entwurf de, view all revisions'
    );

    [$status, $data] = $this->translate('/de/veroeffentlicht', 'rel:working-copy');

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('entwurf de, view all revisions', $data['label']);
    self::assertSame('de', $data['entity']['langcode'] ?? NULL, var_export($data, TRUE));
  }

  /**
   * Tests that the unprefixed path reports the draft in the default language.
   *
   * The two languages must not cross. Reading the revision without carrying
   * the language over returns the default language of the same revision.
   */
  public function testWorkingCopyOnDefaultPathUsesDefaultLanguageRevision(): void {
    $this->createTranslatedEntityWithDraft(
      'draft en, view all revisions',
      'entwurf de, view all revisions'
    );

    [$status, $data] = $this->translate('/published', 'rel:working-copy');

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('draft en, view all revisions', $data['label']);
    self::assertSame('en', $data['entity']['langcode'] ?? NULL, var_export($data, TRUE));
  }

  /**
   * Tests that a prefixed path without the parameter stays on the default.
   */
  public function testNoResourceVersionOnPrefixedPathUsesTranslatedDefault(): void {
    $this->createTranslatedEntityWithDraft(
      'draft en, view all revisions',
      'entwurf de, view all revisions'
    );

    [$status, $data] = $this->translate('/de/veroeffentlicht');

    self::assertSame(200, $status, var_export($data, TRUE));
    self::assertSame('veroeffentlicht de', $data['label']);
    self::assertSame('de', $data['entity']['langcode'] ?? NULL, var_export($data, TRUE));
  }

  /**
   * Tests that revision access is read from the requested translation.
   *
   * Only the German label withholds revision access here. If the check read
   * the English translation instead, it would allow the request and report a
   * label the JSON:API link then refuses.
   */
  public function testRevisionAccessIsCheckedOnTheRequestedTranslation(): void {
    $this->createTranslatedEntityWithDraft(
      'draft en, view all revisions',
      'entwurf de'
    );

    [$status, $data] = $this->translate('/de/veroeffentlicht', 'rel:working-copy');

    self::assertSame(403, $status, var_export($data, TRUE));
    self::assertArrayNotHasKey('label', $data);
  }

}
