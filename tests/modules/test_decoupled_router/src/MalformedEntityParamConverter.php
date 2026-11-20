<?php

declare(strict_types=1);

namespace Drupal\test_decoupled_router;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Symfony\Component\Routing\Route;

/**
 * Converts a route parameter to an entity that was never saved.
 *
 * The router has to describe a path whose parameter converts to something it
 * cannot report, so a test needs a converter that hands back an entity with
 * no ID. The route that uses it names this service directly.
 *
 * @see test_decoupled_router.routing.yml
 */
class MalformedEntityParamConverter implements ParamConverterInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    return $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'unsaved']);
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route): bool {
    // A route that names a converter gets it by service ID, without this
    // method being asked. But the converter is still offered every other
    // entity typed parameter in the same request, so it has to keep to its
    // own route rather than hijack theirs.
    return str_contains($route->getPath(), 'malformed-entity-param');
  }

}
