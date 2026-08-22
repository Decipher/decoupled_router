<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\decoupled_router\PathTranslatorEvent;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\LanguageNegotiationMethodInterface;
use Drupal\language\LanguageNegotiatorInterface;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl;
use Drupal\redirect\Entity\Redirect;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests path translation for paths that carry a language prefix #3111456.
 *
 * The router matches with the negotiated request language. An alias behind
 * another language's prefix does not resolve without the alias mapping this
 * module adds. These tests cover the behaviour matrix for that mapping.
 *
 * @group decoupled_router
 * @coversDefaultClass \Drupal\decoupled_router\EventSubscriber\RouterPathTranslatorSubscriber
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class LanguagePrefixPathTranslatorTest extends KernelTestBase {

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
    'link',
    'views',
    'redirect',
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
    $this->installConfig(['user', 'system', 'language', 'redirect', 'decoupled_router']);
    $this->installEntitySchema('user');
    $this->container->get('entity_type.manager')->getStorage('user')
      ->create([
        'uid' => 0,
        'status' => 0,
        'name' => '',
      ])
      ->save();

    $this->installEntitySchema('entity_test_mul');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('redirect');

    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'de' => 'de'])
      ->save();
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-url' => 0])
      ->save();

    // Rebuild so the negotiator picks up the new language and config.
    $this->container->get('kernel')->rebuildContainer();

    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      ['access content', 'view test entity']
    );
  }

  /**
   * Creates a German-only test entity with a German alias.
   */
  protected function createGermanEntity(string $alias = '/hallowelt'): ContentEntityInterface {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test_mul')
      ->create([
        'name' => 'Hallo Welt',
        'langcode' => 'de',
      ]);
    $entity->save();

    $this->container->get('entity_type.manager')->getStorage('path_alias')
      ->create([
        'path' => '/entity_test_mul/manage/' . $entity->id(),
        'alias' => $alias,
        'langcode' => 'de',
      ])
      ->save();

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
   * Switches URL language negotiation from path prefixes to domains.
   */
  protected function configureDomainNegotiation(): void {
    $this->config('language.negotiation')
      ->set('url.source', LanguageNegotiationUrl::CONFIG_DOMAIN)
      ->set('url.domains', ['en' => 'example.test', 'de' => 'de.example.test'])
      ->save();
    $this->container->get('kernel')->rebuildContainer();
  }

  /**
   * Translates a path for a call that arrived on a given host.
   *
   * The event is dispatched directly rather than through the HTTP kernel.
   * A kernel test has one base URL, so a request on a second host makes
   * Drupal normalise the route to an absolute URL and then reject it as
   * external.
   *
   * @param string $path
   *   The path to translate.
   * @param string $base_url
   *   The scheme and host the call arrived on.
   *
   * @return array
   *   The decoded response body.
   */
  protected function translateOnHost(string $path, string $base_url): array {
    $event = new PathTranslatorEvent(
      $this->container->get('http_kernel'),
      Request::create($base_url . '/router/translate-path'),
      HttpKernelInterface::MAIN_REQUEST,
      $path
    );
    $this->container->get('event_dispatcher')
      ->dispatch($event, PathTranslatorEvent::TRANSLATE);

    $content = $event->getResponse()->getContent();
    return Json::decode($content === FALSE ? '' : $content);
  }

  /**
   * Tests that an alias in a non-default language resolves via its prefix.
   *
   * This is the original report: /de/helloworld fails while /de/node/63
   * works.
   */
  public function testPrefixedAliasResolves(): void {
    $entity = $this->createGermanEntity();

    $data = $this->translate('/de/hallowelt');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests that a prefixed system path keeps resolving.
   */
  public function testPrefixedSystemPathResolves(): void {
    $entity = $this->createGermanEntity();

    $data = $this->translate('/de/entity_test_mul/manage/' . $entity->id());

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests that a prefixed alias with query and fragment resolves #3495182.
   *
   * The alias lookup must see the bare path. The query string and fragment
   * must still survive into the resolved URL.
   */
  public function testPrefixedAliasWithQueryAndFragmentResolves(): void {
    $entity = $this->createGermanEntity();

    $data = $this->translate('/de/hallowelt?query=value#anchor');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
    self::assertStringContainsString('query=value', $data['resolved']);
    self::assertStringEndsWith('#anchor', $data['resolved']);
  }

  /**
   * Tests that the langcode is inside the entity object.
   *
   * Regression guard: consumers such as next-drupal read entity.langcode.
   * Moving it broke them once already (issue comment 90).
   */
  public function testLangcodeStaysInsideEntityObject(): void {
    $this->createGermanEntity();

    $data = $this->translate('/de/hallowelt');

    self::assertSame('de', $data['entity']['langcode'] ?? NULL, var_export($data, TRUE));
    self::assertArrayNotHasKey('langcode', array_diff_key($data, ['entity' => 1]), 'langcode must not sit at the response root.');
  }

  /**
   * Tests that a prefix different from the langcode still resolves.
   *
   * Sites can configure a prefix such as "ger" for the "de" langcode. The
   * mapping must come from the negotiation plugin, not from assuming the
   * prefix equals the langcode.
   */
  public function testPrefixDifferentFromLangcodeResolves(): void {
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'de' => 'ger'])
      ->save();
    $this->container->get('kernel')->rebuildContainer();

    $entity = $this->createGermanEntity();

    $data = $this->translate('/ger/hallowelt');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
    self::assertSame('de', $data['entity']['langcode'] ?? NULL);
  }

  /**
   * Tests that the JSON:API block carries the resolved language.
   */
  public function testJsonapiBlockCarriesLanguage(): void {
    $this->createGermanEntity();

    $data = $this->translate('/de/hallowelt');

    self::assertArrayHasKey('jsonapi', $data, var_export($data, TRUE));
    self::assertStringContainsString('/de/', $data['jsonapi']['entryPoint']);
    self::assertStringContainsString('/de/', $data['jsonapi']['basePath']);
  }

  /**
   * Tests that a language-specific redirect matches behind its prefix.
   *
   * Redirects are stored without a language prefix. The lookup must strip
   * the prefix and match in the language the prefix negotiates.
   */
  public function testPrefixedRedirectMatchesInPathLanguage(): void {
    $entity = $this->createGermanEntity();

    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/aktion');
    $redirect->setRedirect('/hallowelt');
    $redirect->setLanguage('de');
    $redirect->save();

    $data = $this->translate('/de/aktion');

    self::assertArrayHasKey('redirect', $data, var_export($data, TRUE));
    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests that a German redirect does not match without its prefix.
   */
  public function testGermanRedirectDoesNotMatchUnprefixed(): void {
    $this->createGermanEntity();

    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/aktion');
    $redirect->setRedirect('/hallowelt');
    $redirect->setLanguage('de');
    $redirect->save();

    $data = $this->translate('/aktion');

    self::assertArrayNotHasKey('redirect', $data, var_export($data, TRUE));
  }

  /**
   * Tests a prefixed redirect to a path with no entity route.
   *
   * The route level cannot resolve /user/login to an entity, so the
   * subscriber reports the redirect target itself. The reported target
   * must keep the path prefix and match the redirect trace.
   */
  public function testPrefixedRedirectToNonEntityPathKeepsPrefix(): void {
    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/zum-login');
    $redirect->setRedirect('/user/login');
    $redirect->setLanguage('de');
    $redirect->save();

    $data = $this->translate('/de/zum-login');

    self::assertArrayHasKey('resolved', $data, var_export($data, TRUE));
    self::assertStringEndsWith('/de/user/login', $data['resolved']);
    self::assertSame('/de/user/login', $data['redirect'][0]['to']);
    self::assertArrayNotHasKey('entity', $data);
  }

  /**
   * Tests an unprefixed redirect to a path with no entity route.
   *
   * Guards against over-prefixing. With no prefix on the source path the
   * reported target must stay unprefixed.
   */
  public function testUnprefixedRedirectToNonEntityPathStaysUnprefixed(): void {
    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/plain-login');
    $redirect->setRedirect('/user/login');
    $redirect->save();

    $data = $this->translate('/plain-login');

    self::assertArrayHasKey('resolved', $data, var_export($data, TRUE));
    self::assertStringEndsWith('/user/login', $data['resolved']);
    self::assertStringNotContainsString('/de/', $data['resolved']);
  }

  /**
   * Tests that a bare language prefix resolves the front page #3111456.
   *
   * A decoupled frontend asks for the prefixed homepage as its first call:
   * /router/translate-path?path=/de. The path is only the prefix, so the
   * alias mapping must handle the inner path being "/".
   */
  public function testPrefixedHomepageResolves(): void {
    $entity = $this->createGermanEntity();
    $this->config('system.site')
      ->set('page.front', '/entity_test_mul/manage/' . $entity->id())
      ->save();

    $data = $this->translate('/de');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
    self::assertTrue($data['isHomePath']);
  }

  /**
   * Tests the Umami-style configuration where every language has a prefix.
   *
   * The umami.demo.druxtjs.org site runs this configuration and asserts that
   * an unprefixed system path resolves to the prefixed alias form. That is
   * the consumer contract for DruxtJS.
   */
  public function testAllLanguagesPrefixedSystemPathResolvesToPrefixedAlias(): void {
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => 'en', 'de' => 'de'])
      ->save();
    $this->container->get('kernel')->rebuildContainer();

    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test_mul')
      ->create(['name' => 'test', 'langcode' => 'en']);
    $entity->save();
    $this->container->get('entity_type.manager')->getStorage('path_alias')
      ->create([
        'path' => '/entity_test_mul/manage/' . $entity->id(),
        'alias' => '/english-alias',
        'langcode' => 'en',
      ])
      ->save();

    $data = $this->translate('/entity_test_mul/manage/' . $entity->id());

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
    self::assertStringContainsString('/en/english-alias', $data['resolved']);
  }

  /**
   * Tests a prefixed default-language alias in the Umami-style configuration.
   */
  public function testAllLanguagesPrefixedDefaultLanguageAliasResolves(): void {
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => 'en', 'de' => 'de'])
      ->save();
    $this->container->get('kernel')->rebuildContainer();

    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test_mul')
      ->create(['name' => 'test', 'langcode' => 'en']);
    $entity->save();
    $this->container->get('entity_type.manager')->getStorage('path_alias')
      ->create([
        'path' => '/entity_test_mul/manage/' . $entity->id(),
        'alias' => '/english-alias',
        'langcode' => 'en',
      ])
      ->save();

    $data = $this->translate('/en/english-alias');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests that an unprefixed alias still resolves in the default language.
   */
  public function testDefaultLanguageAliasStillResolves(): void {
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test_mul')
      ->create(['name' => 'test', 'langcode' => 'en']);
    $entity->save();
    $this->container->get('entity_type.manager')->getStorage('path_alias')
      ->create([
        'path' => '/entity_test_mul/manage/' . $entity->id(),
        'alias' => '/english-alias',
        'langcode' => 'en',
      ])
      ->save();

    $data = $this->translate('/english-alias');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests a prefixed redirect to a route when every language has a prefix.
   *
   * A target that has a route is built with the language of the current
   * request. Where every language carries a prefix that is the fallback
   * language, so the target comes back as "/en/user/login" and then gains
   * the path prefix as well, giving "/de/en/user/login".
   */
  public function testAllLanguagesPrefixedRedirectToRouteKeepsOnePrefix(): void {
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => 'en', 'de' => 'de'])
      ->save();
    $this->container->get('kernel')->rebuildContainer();

    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/zum-login');
    $redirect->setRedirect('/user/login');
    $redirect->setLanguage('de');
    $redirect->save();

    $data = $this->translate('/de/zum-login');

    self::assertArrayHasKey('resolved', $data, var_export($data, TRUE));
    self::assertStringEndsWith('/de/user/login', $data['resolved']);
    self::assertSame('/de/user/login', $data['redirect'][0]['to']);
    self::assertStringNotContainsString('/de/en/', $data['resolved']);
  }

  /**
   * Tests a prefixed redirect to an alias when every language has a prefix.
   */
  public function testAllLanguagesPrefixedRedirectToAliasResolves(): void {
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => 'en', 'de' => 'de'])
      ->save();
    $this->container->get('kernel')->rebuildContainer();

    $entity = $this->createGermanEntity();

    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/aktion');
    $redirect->setRedirect('/hallowelt');
    $redirect->setLanguage('de');
    $redirect->save();

    $data = $this->translate('/de/aktion');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
    self::assertStringNotContainsString('/de/en/', $data['resolved']);
  }

  /**
   * Tests the translation lookup for a plugin language without a prefix.
   *
   * A negotiation method can report a language for a path that carries no
   * prefix, by domain or by session for example. The entity must then be
   * reported in that language, the same language the redirect lookup uses.
   */
  public function testNonStrippingPluginLanguageSelectsTranslation(): void {
    // Viewing a translation that is not the default one needs its own
    // permission, see EntityTestAccessControlHandler.
    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      ['view test entity translations']
    );

    $negotiator = $this->createMock(LanguageNegotiatorInterface::class);
    $negotiator->method('getNegotiationMethodInstance')
      ->willReturn(new NonStrippingUrlNegotiationMethodStub());
    $negotiator->method('getNegotiationMethods')->willReturn([]);
    $this->container->set('language_negotiator', $negotiator);

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test_mul')
      ->create(['name' => 'English name', 'langcode' => 'en']);
    $entity->addTranslation('de', ['name' => 'Deutscher Name']);
    $entity->save();

    $this->container->get('entity_type.manager')->getStorage('path_alias')
      ->create([
        'path' => '/entity_test_mul/manage/' . $entity->id(),
        'alias' => '/english-alias',
        'langcode' => 'en',
      ])
      ->save();

    $data = $this->translate('/english-alias');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
    self::assertSame('de', $data['entity']['langcode'], var_export($data, TRUE));
  }

  /**
   * Tests domain negotiation, where the language comes from the host.
   *
   * The URL negotiation plugin reads the host of the request it is given for
   * domain negotiation. A path carries no language of its own there, so the
   * request the subscriber builds for the plugin must keep the host that the
   * call arrived on.
   */
  public function testDomainNegotiationResolvesFromInboundHost(): void {
    // Viewing a translation that is not the default one needs its own
    // permission, see EntityTestAccessControlHandler.
    user_role_grant_permissions(
      RoleInterface::ANONYMOUS_ID,
      ['view test entity translations']
    );
    $this->configureDomainNegotiation();

    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test_mul')
      ->create(['name' => 'English name', 'langcode' => 'en']);
    $entity->addTranslation('de', ['name' => 'Deutscher Name']);
    $entity->save();

    $this->container->get('entity_type.manager')->getStorage('path_alias')
      ->create([
        'path' => '/entity_test_mul/manage/' . $entity->id(),
        'alias' => '/english-alias',
        'langcode' => 'en',
      ])
      ->save();

    $data = $this->translateOnHost('/english-alias', 'http://de.example.test');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
    self::assertSame('de', $data['entity']['langcode'], var_export($data, TRUE));
  }

  /**
   * Tests that a redirect is matched in the language of the inbound host.
   *
   * Redirects are looked up in the language of the path. Under domain
   * negotiation that language is only in the host, so the redirect
   * subscriber needs the host as well. Without it the lookup runs in the
   * default language and finds nothing.
   *
   * The target of the redirect is a separate matter. Drupal generates an
   * absolute URL for every route under domain negotiation, and this module
   * has always reported such a target as external.
   */
  public function testDomainNegotiationMatchesRedirectFromInboundHost(): void {
    $this->configureDomainNegotiation();

    $entity = $this->createGermanEntity();

    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/umleitung');
    $redirect->setRedirect('/entity_test_mul/manage/' . $entity->id());
    $redirect->setLanguage('de');
    $redirect->save();

    $data = $this->translateOnHost('/umleitung', 'http://de.example.test');

    self::assertArrayHasKey('redirect', $data, var_export($data, TRUE));
    self::assertSame('/umleitung', $data['redirect'][0]['from']);
    self::assertStringEndsWith(
      '/entity_test_mul/manage/' . $entity->id(),
      $data['redirect'][0]['to'],
      var_export($data, TRUE)
    );
  }

  /**
   * Tests a plugin whose returned path is not a tail of the one given.
   *
   * The prefix cannot be worked out from such a path, so the path has to be
   * left as it arrived rather than have a wrong prefix built from it.
   */
  public function testUnidentifiablePrefixLeavesPathAlone(): void {
    $negotiator = $this->createMock(LanguageNegotiatorInterface::class);
    $negotiator->method('getNegotiationMethodInstance')
      ->willReturn(new RewritingUrlNegotiationMethodStub());
    $negotiator->method('getNegotiationMethods')->willReturn([]);
    $this->container->set('language_negotiator', $negotiator);

    $this->createGermanEntity();

    $data = $this->translate('/de/hallowelt');

    // The path was left alone, so it does not resolve, and nothing broke.
    self::assertArrayNotHasKey('entity', $data, var_export($data, TRUE));
    self::assertArrayHasKey('message', $data, var_export($data, TRUE));
  }

  /**
   * Tests translating a path with no HTTP request in flight.
   *
   * Drush, cron and queue workers dispatch the event directly. The language
   * negotiator gets its current user from LanguageRequestSubscriber, which
   * only runs for an HTTP request, so the plugin must not be handed a NULL
   * user.
   */
  public function testPathTranslationOutsideHttpRequest(): void {
    $entity = $this->createGermanEntity();

    $event = new PathTranslatorEvent(
      $this->container->get('http_kernel'),
      Request::create('/router/translate-path'),
      HttpKernelInterface::MAIN_REQUEST,
      '/de/hallowelt'
    );
    $this->container->get('event_dispatcher')
      ->dispatch($event, PathTranslatorEvent::TRANSLATE);

    $content = $event->getResponse()->getContent();
    $data = Json::decode($content === FALSE ? '' : $content);

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests that a prefixed path with a trailing slash still resolves.
   *
   * The negotiation plugin normalises the trailing slash away, so the inner
   * path is not a literal tail of the input. Deriving the prefix by counting
   * characters leaves the separator behind and builds "/de//hallowelt".
   */
  public function testPrefixedAliasWithTrailingSlashResolves(): void {
    $entity = $this->createGermanEntity();

    $data = $this->translate('/de/hallowelt/');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests that a prefixed system path with a trailing slash resolves.
   */
  public function testPrefixedSystemPathWithTrailingSlashResolves(): void {
    $entity = $this->createGermanEntity();

    $data = $this->translate('/de/entity_test_mul/manage/' . $entity->id() . '/');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests that the prefix goes after the base path in a subdirectory install.
   *
   * Drupal below /subdir serves the German login page at
   * /subdir/de/user/login, not /de/subdir/user/login.
   */
  public function testPrefixedRedirectKeepsBasePathBeforePrefix(): void {
    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/zum-login');
    $redirect->setRedirect('/user/login');
    $redirect->setLanguage('de');
    $redirect->save();

    $request = Request::create(
      'http://localhost/subdir/router/translate-path?path=/subdir/de/zum-login&_format=json',
      'GET',
      [],
      [],
      [],
      ['SCRIPT_NAME' => '/subdir/index.php', 'SCRIPT_FILENAME' => '/subdir/index.php']
    );
    $response = $this->container->get('http_kernel')->handle($request);
    $content = $response->getContent();
    $data = Json::decode($content === FALSE ? '' : $content);

    self::assertArrayHasKey('resolved', $data, var_export($data, TRUE));
    self::assertStringEndsWith('/subdir/de/user/login', $data['resolved']);
    self::assertSame('/subdir/de/user/login', $data['redirect'][0]['to']);
  }

  /**
   * Tests a prefixed alias for an entity without that translation.
   *
   * The alias negotiates German but the entity only exists in English. The
   * entity repository picks the best translation from context instead.
   */
  public function testPrefixedAliasToUntranslatedEntityResolves(): void {
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test_mul')
      ->create(['name' => 'english only', 'langcode' => 'en']);
    $entity->save();
    $this->container->get('entity_type.manager')->getStorage('path_alias')
      ->create([
        'path' => '/entity_test_mul/manage/' . $entity->id(),
        'alias' => '/englischer-inhalt',
        'langcode' => 'de',
      ])
      ->save();

    $data = $this->translate('/de/englischer-inhalt');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
    self::assertSame('en', $data['entity']['langcode']);
  }

  /**
   * Tests that a missing URL negotiation plugin degrades gracefully.
   *
   * Language negotiation is pluggable, so the URL method can be missing
   * even when the negotiator service exists. Path translation must then
   * behave as if no prefix mapping is available.
   */
  public function testMissingUrlNegotiationPluginDegradesGracefully(): void {
    $negotiator = $this->createMock(LanguageNegotiatorInterface::class);
    $negotiator->method('getNegotiationMethodInstance')
      ->willThrowException(new PluginNotFoundException('language-url'));
    $negotiator->method('getNegotiationMethods')->willReturn([]);
    $this->container->set('language_negotiator', $negotiator);

    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test_mul')
      ->create(['name' => 'test', 'langcode' => 'en']);
    $entity->save();
    $this->container->get('entity_type.manager')->getStorage('path_alias')
      ->create([
        'path' => '/entity_test_mul/manage/' . $entity->id(),
        'alias' => '/english-alias',
        'langcode' => 'en',
      ])
      ->save();

    $data = $this->translate('/english-alias');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests a negotiation plugin that reports a language but strips nothing.
   *
   * When the plugin removes no prefix there is nothing to re-map, so the
   * path must pass through unchanged.
   */
  public function testNegotiationPluginWithoutPrefixLeavesPathAlone(): void {
    $negotiator = $this->createMock(LanguageNegotiatorInterface::class);
    $negotiator->method('getNegotiationMethodInstance')
      ->willReturn(new NonStrippingUrlNegotiationMethodStub());
    $negotiator->method('getNegotiationMethods')->willReturn([]);
    $this->container->set('language_negotiator', $negotiator);

    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test_mul')
      ->create(['name' => 'test', 'langcode' => 'en']);
    $entity->save();
    $this->container->get('entity_type.manager')->getStorage('path_alias')
      ->create([
        'path' => '/entity_test_mul/manage/' . $entity->id(),
        'alias' => '/english-alias',
        'langcode' => 'en',
      ])
      ->save();

    $data = $this->translate('/english-alias');

    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

  /**
   * Tests redirect matching for a plugin language without a prefix.
   *
   * A negotiation method can know the path language without a prefix in
   * the path, for example by domain. Redirect matching must then use that
   * language even though there is nothing to strip.
   */
  public function testRedirectMatchesLanguageFromNonStrippingPlugin(): void {
    $negotiator = $this->createMock(LanguageNegotiatorInterface::class);
    $negotiator->method('getNegotiationMethodInstance')
      ->willReturn(new NonStrippingUrlNegotiationMethodStub());
    $negotiator->method('getNegotiationMethods')->willReturn([]);
    $this->container->set('language_negotiator', $negotiator);

    $entity = $this->createGermanEntity();

    $redirect = Redirect::create(['status_code' => 301]);
    $redirect->setSource('/umleitung');
    $redirect->setRedirect('/entity_test_mul/manage/' . $entity->id());
    $redirect->setLanguage('de');
    $redirect->save();

    $data = $this->translate('/umleitung');

    self::assertArrayHasKey('redirect', $data, var_export($data, TRUE));
    self::assertSame('/umleitung', $data['redirect'][0]['from']);
    self::assertArrayHasKey('entity', $data, var_export($data, TRUE));
    self::assertSame($entity->uuid(), $data['entity']['uuid']);
  }

}

/**
 * Stub negotiation method that returns an unrelated path.
 *
 * The path it returns is not a tail of the path it was given, so the prefix
 * cannot be worked out by comparing the two.
 */
final class RewritingUrlNegotiationMethodStub implements LanguageNegotiationMethodInterface, InboundPathProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function setLanguageManager(ConfigurableLanguageManagerInterface $language_manager): void {
  }

  /**
   * {@inheritdoc}
   */
  public function setConfig(ConfigFactoryInterface $config): void {
  }

  /**
   * {@inheritdoc}
   */
  public function setCurrentUser(AccountInterface $current_user): void {
  }

  /**
   * {@inheritdoc}
   */
  public function getLangcode(?Request $request = NULL): string {
    return 'de';
  }

  /**
   * {@inheritdoc}
   */
  public function persist(LanguageInterface $language): void {
  }

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request): string {
    return '/somewhere-entirely-different';
  }

}

/**
 * Stub negotiation method that reports a language but strips no prefix.
 */
final class NonStrippingUrlNegotiationMethodStub implements LanguageNegotiationMethodInterface, InboundPathProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function setLanguageManager(ConfigurableLanguageManagerInterface $language_manager): void {
  }

  /**
   * {@inheritdoc}
   */
  public function setConfig(ConfigFactoryInterface $config): void {
  }

  /**
   * {@inheritdoc}
   */
  public function setCurrentUser(AccountInterface $current_user): void {
  }

  /**
   * {@inheritdoc}
   */
  public function getLangcode(?Request $request = NULL): string {
    return 'de';
  }

  /**
   * {@inheritdoc}
   */
  public function persist(LanguageInterface $language): void {
  }

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    return $path;
  }

}
