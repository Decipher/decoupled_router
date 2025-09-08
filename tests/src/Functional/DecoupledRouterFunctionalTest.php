<?php

namespace Drupal\Tests\decoupled_router\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Language\Language;
use Drupal\Core\Url;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\NodeInterface;
use Drupal\redirect\Entity\Redirect;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;

/**
 * Test class.
 *
 * @group decoupled_router
 */
class DecoupledRouterFunctionalTest extends BrowserTestBase {
  use AssertPageCacheContextsAndTagsTrait;

  const DRUPAL_CI_BASE_URL = 'http://localhost/subdir';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The user.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $user;

  /**
   * The nodes.
   *
   * @var \Drupal\node\Entity\Node[]
   */
  protected $nodes = [];

  /**
   * Modules list.
   *
   * @var array
   */
  protected static $modules = [
    'language',
    'node',
    'path',
    'decoupled_router',
    'redirect',
    'jsonapi',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $language = ConfigurableLanguage::createFromLangcode('ca');
    $language->save();

    // In order to reflect the changes for a multilingual site in the container
    // we have to rebuild it.
    $this->rebuildContainer();

    \Drupal::configFactory()->getEditable('language.negotiation')
      ->set('url.prefixes.ca', 'ca')
      ->save();
    $this->drupalCreateContentType([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $this->user = $this->drupalCreateUser([
      'access content',
      'create article content',
      'edit any article content',
      'delete any article content',
    ]);
    $this->createDefaultContent(3);
    $redirect = Redirect::create(['status_code' => '301']);
    $redirect->setSource('/foo');
    $redirect->setRedirect('/node--0');
    $redirect->setLanguage(Language::LANGCODE_NOT_SPECIFIED);
    $redirect->save();
    // Create redirect chain (bar -> foo -> node--0).
    $redirect = Redirect::create(['status_code' => '301']);
    $redirect->setSource('/bar');
    $redirect->setRedirect('/foo');
    $redirect->setLanguage(Language::LANGCODE_NOT_SPECIFIED);
    $redirect->save();
    // Create redirect chain (chain -> bar -> foo -> node--0).
    $redirect = Redirect::create(['status_code' => '301']);
    $redirect->setSource('/chain');
    $redirect->setRedirect('/bar');
    $redirect->setLanguage(Language::LANGCODE_NOT_SPECIFIED);
    $redirect->save();
    $redirect = Redirect::create(['status_code' => '301']);
    $redirect->setSource('/foo--ca');
    $redirect->setRedirect('/node--0--ca');
    $redirect->setLanguage('ca');
    $redirect->save();
    $redirect = Redirect::create(['status_code' => '301']);
    $redirect->setSource('/foobar');
    $redirect->setRedirect('http://example.com/foobar');
    $redirect->save();
    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Creates default content to test the API.
   *
   * @param int $num_articles
   *   Number of articles to create.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createDefaultContent($num_articles) {
    $random = $this->getRandomGenerator();
    for ($created_nodes = 0; $created_nodes < $num_articles; $created_nodes++) {
      $values = [
        'uid' => ['target_id' => $this->user->id()],
        'type' => 'article',
        'path' => '/node--' . $created_nodes,
        'title' => $random->name(),
      ];
      $node = $this->createNode($values);
      $values['title'] = $node->getTitle() . ' (ca)';
      $values['field_image']['alt'] = 'alt text (ca)';
      $values['path'] = '/node--' . $created_nodes . '--ca';
      $node->addTranslation('ca', $values);

      $node->save();
      $this->nodes[] = $node;
    }
  }

  /**
   * Tests reading multilingual content.
   */
  public function testNegotiationNoMultilingual() {
    // This is not build with data providers to avoid rebuilding the environment
    // each test.
    $make_assertions = function ($path, DecoupledRouterFunctionalTest $test) {
      $path = $test->addBasePath($path);
      $res = $test->drupalGet(
        Url::fromRoute('decoupled_router.path_translation'),
        [
          'query' => [
            'path' => $path,
            '_format' => 'json',
          ],
        ]
      );
      $test->assertSession()->statusCodeEquals(200);
      $output = Json::decode($res);
      $test->assertStringEndsWith('/node--0', $output['resolved']);
      $test->assertSame($test->nodes[0]->id(), $output['entity']['id']);
      $test->assertSame('node--article', $output['jsonapi']['resourceName']);
      $test->assertStringEndsWith('/jsonapi/node/article/' . $test->nodes[0]->uuid(), $output['jsonapi']['individual']);
    };

    // Test cases:
    $test_cases = [
      // 1. Test negotiation by system path for /node/1 -> /node--0.
      'node/1',
      // 2. Test negotiation by alias for /node--0.
      'node--0',
      // 3. Test negotiation by multiple redirects for /bar -> /foo -> /node--0.
      'bar',
    ];
    array_walk($test_cases, function ($test_case) use ($make_assertions) {
      $make_assertions($test_case, $this);
    });
  }

  /**
   * Tests reading external redirect.
   */
  public function testExternalRedirect() {
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'foobar',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $expected = [
      'resolved' => 'http://example.com/foobar',
      'isHomePath' => FALSE,
      'redirect' => [
        [
          'from' => '/foobar',
          'to' => 'http://example.com/foobar',
          'status' => '301',
        ],
      ],
      'isExternal' => TRUE,
    ];
    $this->assertEquals($expected, $output);

    // Ensure external redirects are still absolute URLs.
    $this->config('decoupled_router.settings')->set('absolute_resolved_urls', FALSE)->save();
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'foobar',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $this->assertEquals($expected, $output);

    // Test with fragment.
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'foobar#anchor',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $expected['resolved'] = 'http://example.com/foobar#anchor';
    $expected['redirect'][0]['from'] = '/foobar#anchor';
    $expected['redirect'][0]['to'] = 'http://example.com/foobar#anchor';
    $this->assertEquals($expected, $output);

    // Test redirect with anchor.
    $redirect = Redirect::create(['status_code' => '301']);
    $redirect->setSource('/foobar-anchor');
    $redirect->setRedirect('http://example.com/foobar#another-anchor');
    $redirect->save();

    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'foobar-anchor',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $expected = [
      'resolved' => 'http://example.com/foobar#another-anchor',
      'isHomePath' => FALSE,
      'redirect' => [
        [
          'from' => '/foobar-anchor',
          'to' => 'http://example.com/foobar#another-anchor',
          'status' => '301',
        ],
      ],
      'isExternal' => TRUE,
    ];
    $this->assertEquals($expected, $output);

    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'foobar-anchor#anchor',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $expected['redirect'][0]['from'] = '/foobar-anchor#anchor';
    $this->assertEquals($expected, $output);
  }

  /**
   * Tests decoupled_router.settings:absolute.
   */
  public function testRelativeAndAbsolutePaths() {
    $node = $this->nodes[0];
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => '/node--0',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);

    $expected = [
      'resolved' => $this->buildUrl('/node--0'),
      'isExternal' => FALSE,
      'isHomePath' => FALSE,
      'entity' => [
        'canonical' => $this->buildUrl('/node--0'),
        'type' => 'node',
        'bundle' => 'article',
        'id' => $node->id(),
        'uuid' => $node->uuid(),
      ],
      'label' => $node->label(),
      'jsonapi' => [
        'individual' => $this->buildUrl('/jsonapi/node/article/' . $node->uuid()),
        'resourceName' => 'node--article',
        'pathPrefix' => 'jsonapi',
        'basePath' => '/jsonapi',
        'entryPoint' => $this->buildUrl('/jsonapi'),
      ],
      'meta' => [
        'deprecated' => [
          'jsonapi.pathPrefix' => 'This property has been deprecated and will be removed in the next version of Decoupled Router. Use basePath instead.',
        ],
      ],
    ];
    $this->assertSame($expected, $output);

    $this->config('decoupled_router.settings')->set('absolute_resolved_urls', FALSE)->save();
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => '/node--0',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $expected['resolved'] = $this->addBasePath('node--0');
    $this->assertSame($expected, $output);
  }

  /**
   * Tests fragment handing on redirects to entities.
   */
  public function testFragmentRedirectOnEntity() {
    $redirect = Redirect::create(['status_code' => '301']);
    $redirect->setSource('/foo-anchor');
    $redirect->setRedirect('/node--0#anchor');
    $redirect->setLanguage(Language::LANGCODE_NOT_SPECIFIED);
    $redirect->save();

    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => '/foo#test',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $this->assertEquals($this->buildUrl('/node--0', ['fragment' => 'test']), $output['resolved']);

    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => '/foo-anchor#test',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $this->assertEquals($this->buildUrl('/node--0', ['fragment' => 'anchor']), $output['resolved']);
  }

  /**
   * Tests reading redirect chain.
   */
  public function testChainedRedirect() {
    $node = $this->nodes[0];
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'chain',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    // Ensure all redirects involved are in the cache tags for the response.
    $this->assertCacheTags([
      'config:decoupled_router.settings',
      'config:redirect.settings',
      'config:system.site',
      'node:1',
      'redirect:1',
      'redirect:2',
      'redirect:3',
    ]);
    $output = Json::decode($res);
    $expected = [
      'resolved' => $this->buildUrl('/node--0'),
      'isHomePath' => FALSE,
      'redirect' => [
        [
          'from' => '/chain',
          'to' => $this->addBasePath('node--0'),
          'status' => '301',
        ],
      ],
      'isExternal' => FALSE,
      'entity' => [
        'canonical' => $this->buildUrl('/node--0'),
        'type' => 'node',
        'bundle' => 'article',
        'id' => $node->id(),
        'uuid' => $node->uuid(),
      ],
      'label' => $node->label(),
      'jsonapi' => [
        'individual' => $this->buildUrl('/jsonapi/node/article/' . $node->uuid()),
        'resourceName' => 'node--article',
        'pathPrefix' => 'jsonapi',
        'basePath' => '/jsonapi',
        'entryPoint' => $this->buildUrl('/jsonapi'),
      ],
      'meta' => [
        'deprecated' => [
          'jsonapi.pathPrefix' => 'This property has been deprecated and will be removed in the next version of Decoupled Router. Use basePath instead.',
        ],
      ],
    ];
    $this->assertEquals($expected, $output);
  }

  /**
   * Tests without redirect module installed.
   */
  public function testNoRedirectModule() {
    $redirects = Redirect::loadMultiple();
    \Drupal::entityTypeManager()->getStorage('redirect')->delete($redirects);
    \Drupal::service('module_installer')->uninstall(['redirect']);
    $this->rebuildAll();
    $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'foobar',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'node--0',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Test that unpublished content ist not available.
   */
  public function testUnpublishedContent() {
    $values = [
      'uid' => ['target_id' => $this->user->id()],
      'type' => 'article',
      'path' => '/node--unpublished',
      'title' => $this->getRandomGenerator()->name(),
      'status' => NodeInterface::NOT_PUBLISHED,
    ];
    $node = $this->createNode($values);

    $redirect = Redirect::create(['status_code' => '301']);
    $redirect->setSource('/unp');
    $redirect->setRedirect('/node--unpublished');
    $redirect->setLanguage(Language::LANGCODE_NOT_SPECIFIED);
    $redirect->save();

    // Test access via node_id to unpublished content.
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => '/unp',
          '_format' => 'json',
        ],
      ]
    );
    $output = Json::decode($res);
    $this->assertArrayNotHasKey('redirect', $output);
    $this->assertEquals(
      [
        'message' => 'Access denied for entity.',
        'details' => 'This user does not have access to view the resolved entity. Please authenticate and try again.',
      ],
      $output
    );
    $this->assertSession()->statusCodeEquals(403);

    // Make sure privileged users can access the output.
    $admin_user = $this->drupalCreateUser([
      'administer nodes',
      'bypass node access',
    ]);
    $this->drupalLogin($admin_user);
    // Test access via node_id to unpublished content.
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => '/unp',
          '_format' => 'json',
        ],
      ]
    );
    $output = Json::decode($res);
    $this->assertSession()->statusCodeEquals(200);
    $expected = [
      'resolved' => $this->buildUrl('/node--unpublished'),
      'isHomePath' => FALSE,
      'entity' => [
        'canonical' => $this->buildUrl('/node--unpublished'),
        'type' => 'node',
        'bundle' => 'article',
        'id' => $node->id(),
        'uuid' => $node->uuid(),
      ],
      'label' => $node->label(),
      'jsonapi' => [
        'individual' => $this->buildUrl('/jsonapi/node/article/' . $node->uuid()),
        'resourceName' => 'node--article',
        'pathPrefix' => 'jsonapi',
        'basePath' => '/jsonapi',
        'entryPoint' => $this->buildUrl('/jsonapi'),
      ],
      'meta' => [
        'deprecated' => [
          'jsonapi.pathPrefix' => 'This property has been deprecated and will be removed in the next version of Decoupled Router. Use basePath instead.',
        ],
      ],
      'redirect' => [
        [
          'from' => '/unp',
          'to' => $this->addBasePath('node--unpublished'),
          'status' => '301',
        ],
      ],
      'isExternal' => FALSE,
    ];
    $this->assertEquals($expected, $output);
  }

  /**
   * Test that the home path check is working.
   */
  public function testHomPathCheck() {

    // Create front page node.
    $this->createNode([
      'uid' => ['target_id' => $this->user->id()],
      'type' => 'article',
      'path' => '/node--homepage',
      'title' => $this->getRandomGenerator()->name(),
      'status' => NodeInterface::NOT_PUBLISHED,
    ]);

    // Update front page.
    \Drupal::configFactory()->getEditable('system.site')
      ->set('page.front', '/node--homepage')
      ->save();

    $user = $this->drupalCreateUser(['bypass node access']);
    $this->drupalLogin($user);

    // Test front page node.
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => '/node--homepage',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $this->assertTrue($output['isHomePath']);

    // Test non-front page node.
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => '/node--1',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $this->assertFalse($output['isHomePath']);
  }

  /**
   * Tests decoupled router with non-entity routes.
   */
  public function testViews() {
    \Drupal::service('module_installer')->install(['views']);
    // Create a redirect to the node listing.
    $redirect = Redirect::create(['status_code' => '301']);
    $redirect->setSource('/non-entity');
    $redirect->setRedirect('/node');
    $redirect->setLanguage(Language::LANGCODE_NOT_SPECIFIED);
    $redirect->save();
    $this->rebuildAll();

    $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'node',
          '_format' => 'json',
        ],
      ]
    );
    // Accessing the non-entity route should 404 because the decoupled router is
    // about getting entity and redirect info.
    $this->assertSession()->statusCodeEquals(404);

    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'non-entity',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $expected = [
      'resolved' => $this->buildUrl('/node'),
      'isExternal' => FALSE,
      'isHomePath' => FALSE,
      'redirect' => [
        [
          'from' => '/non-entity',
          'to' => $this->addBasePath('node'),
          'status' => '301',
        ],
      ],
    ];
    $this->assertSame($expected, $output);

    // Make /node the front page.
    \Drupal::configFactory()->getEditable('system.site')
      ->set('page.front', '/node')
      ->save();
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'non-entity',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $expected['isHomePath'] = TRUE;
    $this->assertSame($expected, $output);

    // Test with relative resolved URLs.
    $this->config('decoupled_router.settings')->set('absolute_resolved_urls', FALSE)->save();
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'non-entity',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $expected['resolved'] = $this->addBasePath('/node');
    $this->assertSame($expected, $output);
  }

  /**
   * Adds the base path to a path.
   *
   * This helps testing on Drupal gitlab where tests are run on a subfolder.
   *
   * @param string $path
   *   The path to add the base path to.
   *
   * @return string
   *   The path with the base path added.
   */
  private function addBasePath(string $path): string {
    return '/' . implode('/', array_filter([
      trim($this->getBasePath(), '/'),
      ltrim($path, '/'),
    ]));
  }

  /**
   * Tests decoupled router with non-entity routes.
   */
  public function testRedirectQueryStringAndFragment() {
    // Create a redirect to user login.
    $redirect = Redirect::create(['status_code' => '301']);
    $redirect->setSource('/funky-login');
    $redirect->setRedirect('/user/login');
    $redirect->setLanguage(Language::LANGCODE_NOT_SPECIFIED);
    $redirect->save();
    $this->rebuildAll();

    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'funky-login',
          '_format' => 'json',
        ],
      ]
    );
    // Accessing the non-entity route should 404 because the decoupled router is
    // about getting entity and redirect info.
    $this->assertSession()->statusCodeEquals(200);

    $output = Json::decode($res);
    $expected_url = $this->buildUrl('/user/login');
    $this->assertSame($expected_url, $output['resolved']);

    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'funky-login?foo=bar',
          '_format' => 'json',
        ],
      ]
    );
    // Accessing the non-entity route should 404 because the decoupled router is
    // about getting entity and redirect info.
    $this->assertSession()->statusCodeEquals(200);

    $output = Json::decode($res);
    $expected_url = $this->buildUrl('/user/login', ['query' => ['foo' => 'bar']]);

    $this->assertSame($expected_url, $output['resolved']);

    $this->config('redirect.settings')->set('passthrough_querystring', FALSE)->save();

    $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'funky-login?foo=bar',
          '_format' => 'json',
        ],
      ]
    );
    // Accessing the non-entity route should 404 because there is no redirect
    // for funky-login?foo=bar.
    $this->assertSession()->statusCodeEquals(404);

    $redirect->setSource('/funky-login', ['foo' => 'bar']);
    $redirect->save();
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'funky-login?foo=bar',
          '_format' => 'json',
        ],
      ]
    );
    // Accessing the non-entity route should 404 because there is no redirect
    // for funky-login?foo=bar.
    $this->assertSession()->statusCodeEquals(200);

    $output = Json::decode($res);
    $expected_url = $this->buildUrl('/user/login');

    $this->assertSame($expected_url, $output['resolved']);

    $this->config('redirect.settings')->set('passthrough_querystring', TRUE)->save();
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'funky-login?foo=bar',
          '_format' => 'json',
        ],
      ]
    );
    // Accessing the non-entity route should 404 because there is no redirect
    // for funky-login?foo=bar.
    $this->assertSession()->statusCodeEquals(200);

    $output = Json::decode($res);
    $expected_url = $this->buildUrl('/user/login', ['query' => ['foo' => 'bar']]);

    $this->assertSame($expected_url, $output['resolved']);

    $redirect->setSource('/funky-login');
    $redirect->setRedirect('/user/login', ['foo' => 'redirect']);
    $redirect->save();
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'funky-login?foo=bar&bar=baz',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);

    $output = Json::decode($res);
    $expected_url = $this->buildUrl('/user/login', ['query' => ['foo' => 'redirect', 'bar' => 'baz']]);
    $this->assertSame($expected_url, $output['resolved']);
  }

  /**
   * Tests query string and fragment handling on entities.
   */
  public function testQueryStringsAndFragmentsOnEntities() {
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'node/1?foo=bar#fragment',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $expected_url = $this->buildUrl('/node--0', ['fragment' => 'fragment', 'query' => ['foo' => 'bar']]);
    $this->assertSame($expected_url, $output['resolved']);

    // Test using an aliased path.
    $res = $this->drupalGet(
      Url::fromRoute('decoupled_router.path_translation'),
      [
        'query' => [
          'path' => 'node--0?foo=bar#fragment',
          '_format' => 'json',
        ],
      ]
    );
    $this->assertSession()->statusCodeEquals(200);
    $output = Json::decode($res);
    $this->assertSame($expected_url, $output['resolved']);
  }

  /**
   * Computes the base path under which the Drupal managed URLs are available.
   *
   * @return string
   *   The path.
   */
  private function getBasePath() {
    $parts = parse_url(
      (
        getenv('SIMPLETEST_BASE_URL') ?: getenv('WEB_HOST')
      ) ?: self::DRUPAL_CI_BASE_URL
    );
    $path = empty($parts['path']) ? '' : $parts['path'];
    return rtrim($path, '/') . '/';
  }

}
