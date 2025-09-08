<?php

namespace Drupal\decoupled_router\EventSubscriber;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\decoupled_router\PathTranslatorEvent;
use Drupal\path_alias\AliasManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\Route;

/**
 * Event subscriber that processes a path translation with the router info.
 */
class RouterPathTranslatorSubscriber implements EventSubscriberInterface {
  use StringTranslationTrait;

  /**
   * The service container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The router.
   *
   * @var \Symfony\Component\Routing\Matcher\UrlMatcherInterface
   */
  protected $router;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The decoupled_router.settings config.
   */
  protected Config $decoupledRouterConfig;

  /**
   * Determines if a message should be logged if an entity not found.
   */
  protected const LOG_ENTITY_NOT_FOUND = TRUE;

  /**
   * RouterPathTranslatorSubscriber constructor.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Symfony\Component\Routing\Matcher\UrlMatcherInterface $router
   *   The router.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The alias manager.
   */
  public function __construct(
    ContainerInterface $container,
    LoggerInterface $logger,
    UrlMatcherInterface $router,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory,
    AliasManagerInterface $aliasManager,
  ) {
    $this->container = $container;
    $this->logger = $logger;
    $this->router = $router;
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
    $this->aliasManager = $aliasManager;
    $this->decoupledRouterConfig = $config_factory->get('decoupled_router.settings');
  }

  /**
   * Processes a path translation request.
   */
  public function onPathTranslation(PathTranslatorEvent $event) {
    $response = $event->getResponse();
    $cacheable_metadata = new CacheableMetadata();
    if (!$response instanceof CacheableJsonResponse) {
      $this->logger->error('Unable to get the response object for the decoupled router event.');
      return;
    }
    $path = $this->cleanSubdirInPath($event->getPath(), $event->getRequest());

    // Preserve the original query string and fragment if any.
    $resolved_url_options = UrlHelper::parse($path);
    unset($resolved_url_options['path']);
    $resolved_url_options['absolute'] = $this->decoupledRouterConfig->get('absolute_resolved_urls');

    // If URL is external, we won't perform checks for content in Drupal,
    // but assume that it's working.
    if (UrlHelper::isExternal($path)) {
      $response->setStatusCode(200);
      $response->setData([
        'resolved' => $path,
        'isExternal' => TRUE,
        'isHomePath' => FALSE,
      ]);
      return;
    }

    try {
      $match_info = $this->router->match($path);
    }
    catch (ResourceNotFoundException $exception) {
      return;
    }
    catch (MethodNotAllowedException $exception) {
      $response->setStatusCode(403);
      return;
    }
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    [
      $entity,
      $route_parameter_entity_key,
    ] = $this->findEntityAndKeys($match_info);
    if (!$entity) {
      if (static::LOG_ENTITY_NOT_FOUND) {
        $this->logger->notice('A route has been found but it has no entity information.');
      }
      return;
    }
    $response->addCacheableDependency($entity);
    if ($entity->getEntityType() instanceof ContentEntityType) {
      $can_view = $entity->access('view', NULL, TRUE);
      if (!$can_view->isAllowed()) {
        $response->setData([
          'message' => 'Access denied for entity.',
          'details' => 'This user does not have access to view the resolved entity. Please authenticate and try again.',
        ]);
        $response->setStatusCode(403);
        $response->addCacheableDependency($can_view);
        return;
      }
    }

    $entity_type_id = $entity->getEntityTypeId();

    try {
      $canonical_url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
    }
    catch (EntityMalformedException $e) {
      $response->setData([
        'message' => 'Unable to build entity URL.',
        'details' => 'A valid entity was found but it was impossible to generate a valid canonical URL for it.',
      ]);
      $response->setStatusCode(500);
      // Logging error exceptions.
      Error::logException($this->logger, $e);

      return;
    }
    $entity_param = $entity->id();
    $resolved_url = Url::fromRoute($match_info[RouteObjectInterface::ROUTE_NAME], [
      $route_parameter_entity_key => $entity_param,
    ], $resolved_url_options);

    $resolved_generated_url = $resolved_url->toString(TRUE);
    $response->addCacheableDependency($canonical_url);
    $response->addCacheableDependency($resolved_generated_url);
    $is_home_path = $this->resolvedPathIsHomePath($resolved_url, $cacheable_metadata);

    $label_accessible = $entity->access('view label', NULL, TRUE);
    $response->addCacheableDependency($label_accessible);
    $output = [
      'resolved' => $resolved_generated_url->getGeneratedUrl(),
      'isExternal' => FALSE,
      'isHomePath' => $is_home_path,
      'entity' => [
        'canonical' => $canonical_url->getGeneratedUrl(),
        'type' => $entity_type_id,
        'bundle' => $entity->bundle(),
        'id' => $entity->id(),
        'uuid' => $entity->uuid(),
      ],
    ];
    if ($label_accessible->isAllowed()) {
      $output['label'] = $entity->label();
    }
    // Allow to alter basic router info.
    $this->moduleHandler->invokeAll('decoupled_router_info_alter', [
      &$output, ['entity' => $entity],
    ]);

    // If the route is JSON API, it means that JSON API is installed and its
    // services can be used.
    if ($this->moduleHandler->moduleExists('jsonapi')) {
      /** @var \Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface $rt_repo */
      $rt_repo = $this->container->get('jsonapi.resource_type.repository');
      $rt = $rt_repo->get($entity_type_id, $entity->bundle());
      $type_name = $rt->getTypeName();
      $jsonapi_base_path = $this->container->getParameter('jsonapi.base_path');
      $entry_point_url = Url::fromRoute('jsonapi.resource_list', [], ['absolute' => TRUE])->toString(TRUE);
      $route_name = sprintf('jsonapi.%s.individual', $type_name);
      $individual = Url::fromRoute(
        $route_name,
        [
          static::getEntityRouteParameterName($route_name, $entity_type_id) => $entity->uuid(),
        ],
        ['absolute' => TRUE]
      )->toString(TRUE);
      $response->addCacheableDependency($entry_point_url);
      $response->addCacheableDependency($individual);
      $output['jsonapi'] = [
        'individual' => $individual->getGeneratedUrl(),
        'resourceName' => $type_name,
        'pathPrefix' => trim($jsonapi_base_path, '/'),
        'basePath' => $jsonapi_base_path,
        'entryPoint' => $entry_point_url->getGeneratedUrl(),
      ];
      $output['meta'] = [
        'deprecated' => [
          'jsonapi.pathPrefix' => $this->t(
            'This property has been deprecated and will be removed in the next version of Decoupled Router. Use @alternative instead.', ['@alternative' => 'basePath']
          ),
        ],
      ];
    }
    $response->addCacheableDependency($entity);
    $response->addCacheableDependency($this->decoupledRouterConfig);
    $response->addCacheableDependency($cacheable_metadata);
    $response->setStatusCode(200);
    $response->setData($output);

    $event->stopPropagation();
  }

  /**
   * Get the underlying entity and the type of ID param enhancer for the routes.
   *
   * @param array $match_info
   *   The router match info.
   *
   * @return array
   *   The pair of \Drupal\Core\Entity\EntityInterface and bool with the
   *   underlying entity. It also returns the name of the parameter under which
   *   the entity lives in the route ('node' vs 'entity').
   */
  protected function findEntityAndKeys(array $match_info) {
    $entity = NULL;
    /** @var \Symfony\Component\Routing\Route $route */
    $route = $match_info[RouteObjectInterface::ROUTE_OBJECT];
    $route_parameter_entity_key = 'entity';
    if (
      !empty($match_info['entity']) &&
      $match_info['entity'] instanceof EntityInterface
    ) {
      $entity = $match_info['entity'];
    }
    else {
      $entity_type_id = $this->findEntityTypeFromRoute($route);
      // @todo $match_info[$entity_type_id] is broken for JSON API 2.x routes.
      // Now it will be $match_info[$entity_type_id] for core and
      // $match_info['entity'] for JSON API :-(.
      if (
        !empty($entity_type_id) &&
        !empty($match_info[$entity_type_id]) &&
        $match_info[$entity_type_id] instanceof EntityInterface
      ) {
        $route_parameter_entity_key = $entity_type_id;
        $entity = $match_info[$entity_type_id];
      }
    }

    return [$entity, $route_parameter_entity_key];
  }

  /**
   * Computes the name of the entity route parameter for JSON API routes.
   *
   * @param string $route_name
   *   A JSON API route name.
   * @param string $entity_type_id
   *   The corresponding entity type ID.
   *
   * @return string
   *   Either 'entity' or $entity_type_id.
   *
   * @todo Remove this once decoupled_router requires jsonapi >= 8.x-2.0.
   */
  protected static function getEntityRouteParameterName($route_name, $entity_type_id) {
    static $first;

    if (!isset($first)) {
      $route_parameters = \Drupal::service('router.route_provider')
        ->getRouteByName($route_name)
        ->getOption('parameters');
      $first = isset($route_parameters['entity'])
        ? 'entity'
        : $entity_type_id;
      return $first;
    }

    return $first === 'entity'
      ? 'entity'
      : $entity_type_id;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[PathTranslatorEvent::TRANSLATE][] = ['onPathTranslation'];
    return $events;
  }

  /**
   * Extracts the entity type for the route parameters.
   *
   * If there are more than one parameter, this function will return the first
   * one.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   *
   * @return string|null
   *   The entity type ID or NULL if not found.
   */
  protected function findEntityTypeFromRoute(Route $route) {
    $parameters = (array) $route->getOption('parameters');
    // Find the entity type for the first parameter that has one.
    return array_reduce($parameters, function ($carry, $parameter) {
      if (!$carry && !empty($parameter['type'])) {
        $parts = explode(':', $parameter['type']);
        // We know that the parameter is for an entity if the type is set to
        // 'entity:<entity-type-id>'.
        if ($parts[0] === 'entity' && !empty($parts[1])) {
          $carry = $parts[1];
        }
      }
      return $carry;
    }, NULL);
  }

  /**
   * Removes the subdir prefix from the path.
   *
   * @param string $path
   *   The path that can contain the subdir prefix.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to extract the path prefix from.
   *
   * @return string
   *   The clean path.
   */
  protected function cleanSubdirInPath($path, Request $request) {
    // Remove any possible leading subdir information in case Drupal is
    // installed under http://example.com/d8/index.php
    $regexp = preg_quote($request->getBasePath(), '/');
    return preg_replace(sprintf('/^%s/', $regexp), '', $path);
  }

  /**
   * Checks if the resolved path is the home path.
   *
   * @param string|\Drupal\Core\Url $resolved_url
   *   The resolved url from the request.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheable_metadata
   *   The cacheable metadata that will be added to the response.
   *
   * @return bool
   *   True if the resolved path is the home path, false otherwise.
   */
  protected function resolvedPathIsHomePath(string|Url $resolved_url, ?CacheableMetadata $cacheable_metadata = NULL): bool {
    $config = $this->configFactory->get('system.site');
    if ($cacheable_metadata) {
      $cacheable_metadata->addCacheableDependency($config);
    }
    else {
      @trigger_error('The $cacheable_metadata not being an instance of \Drupal\Core\Cache\CacheableMetadata is deprecated in decoupled_router:2.0.6 and is removed in decoupled_router:3.0.0. Pass in a \Drupal\Core\Cache\CacheableMetadata object instead. See https://www.drupal.org/node/3543536', E_USER_DEPRECATED);
    }
    $home_path = $this->configFactory->get('system.site')->get('page.front');
    if ($resolved_url instanceof Url) {
      $home_url = Url::fromUserInput($home_path)->setAbsolute((bool) $resolved_url->getOption('absolute'))->toString();
      $resolved_url = $resolved_url->toString();
    }
    else {
      @trigger_error('The $resolved_url not being an instance of \Drupal\Core\Url is deprecated in decoupled_router:2.0.6 and is removed in decoupled_router:3.0.0. Pass in a Url object instead. See https://www.drupal.org/node/3543536', E_USER_DEPRECATED);
      $home_url = Url::fromUserInput($home_path, ['absolute' => $this->decoupledRouterConfig->get('absolute_resolved_urls')])->toString(TRUE)->getGeneratedUrl();
    }

    return $resolved_url === $home_url;
  }

}
