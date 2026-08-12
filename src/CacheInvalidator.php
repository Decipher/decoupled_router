<?php

declare(strict_types=1);

namespace Drupal\decoupled_router;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;

/**
 * Invalidates decoupled router cache entries based on path events.
 */
class CacheInvalidator {

  /**
   * CacheInvalidator constructor.
   *
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tag invalidator.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manger.
   */
  public function __construct(
    /**
     * The invalidator.
     */
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    /**
     * The entity type manager.
     */
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Invalidate cached responses associated with the given path.
   *
   * This is called whenever an URL alias is created, updated or deleted and
   * makes sure the relevant Decoupled Router responses are invalidated.
   *
   * @param array $path
   *   The path array.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *
   * @see https://www.drupal.org/project/drupal/issues/2480077
   */
  public function invalidateByPath(array $path): void {
    // Derive cache tags by source path.
    $tags = $this->getTagsBySourcePath($path['source']);

    // Path changes may change a cached 403 or 404 response.
    $tags = Cache::mergeTags($tags, ['4xx-response']);

    $this->cacheTagsInvalidator->invalidateTags($tags);
  }

  /**
   * Invalidates cache for an entity based on its internal system path.
   *
   * Derives the entity associated with the given path (if any) and collects the
   * cache tags associated with it.
   *
   * @param string $source_path
   *   An internal system path.
   *
   * @return string[]
   *   The merged array of cache tags, if any.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  private function getTagsBySourcePath($source_path) {
    $tags = [];

    try {
      $parameters = Url::fromUri('internal:' . $source_path)
        ->getRouteParameters();
    }
    catch (\UnexpectedValueException) {
      $parameters = [];
    }
    if (empty($parameters)) {
      return $tags;
    }

    $entity_type_id = key($parameters);
    $entity_id = reset($parameters);
    if (empty($entity_type_id) || empty($entity_id)) {
      return $tags;
    }

    $entity_type = $this->entityTypeManager
      ->getDefinition($entity_type_id, FALSE);
    if (!$entity_type) {
      return $tags;
    }

    $entity = $this->entityTypeManager
      ->getStorage($entity_type_id)
      ->load($entity_id);
    if (!$entity) {
      return $tags;
    }

    return Cache::mergeTags(
      $entity_type->getListCacheTags(),
      $entity->getCacheTagsToInvalidate()
    );
  }

}
