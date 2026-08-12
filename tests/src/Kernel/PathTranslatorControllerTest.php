<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_router\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the PathTranslator controller directly.
 *
 * @group decoupled_router
 * @coversDefaultClass \Drupal\decoupled_router\Controller\PathTranslator
 */
#[Group('decoupled_router')]
#[RunTestsInSeparateProcesses]
final class PathTranslatorControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'path',
    'path_alias',
    'link',
    'views',
    'decoupled_router',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'system', 'decoupled_router']);
    $this->installEntitySchema('user');
    $this->container->get('entity_type.manager')->getStorage('user')
      ->create(['uid' => 0, 'status' => 0, 'name' => ''])
      ->save();
    $this->installEntitySchema('path_alias');
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
  }

  /**
   * Tests that a request without a "path" query parameter is a 404.
   */
  public function testMissingPathQueryParameterReturns404(): void {
    $request = Request::create(
      Url::fromRoute('decoupled_router.path_translation', [], [
        'query' => ['_format' => 'json'],
      ])->toString()
    );

    $response = $this->container->get('http_kernel')->handle($request);

    self::assertSame(404, $response->getStatusCode());
  }

}
