<?php

namespace Drupal\decoupled_router\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\decoupled_router\PathTranslatorEvent;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\redirect\RedirectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * Event subscriber that processes a path translation with the redirect info.
 *
 * @see \Drupal\decoupled_router\DecoupledRouterServiceProvider
 */
class RedirectPathTranslatorSubscriber extends RouterPathTranslatorSubscriber {

  public function __construct(
    ContainerInterface $container,
    #[Autowire(service: 'logger.channel.decoupled_router')] LoggerInterface $logger,
    #[Autowire(service: 'router.no_access_checks')] UrlMatcherInterface $router,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory,
    AliasManagerInterface $aliasManager,
    protected LanguageManagerInterface $languageManager,
    protected RedirectRepository $redirectRepository,
  ) {
    parent::__construct($container, $logger, $router, $module_handler, $config_factory, $aliasManager);
  }

  /**
   * {@inheritdoc}
   */
  protected const LOG_ENTITY_NOT_FOUND = FALSE;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // We wanna run before the router-based path translator because redirects
    // naturally act before routing subsystem in Drupal HTTP kernel.
    $events[PathTranslatorEvent::TRANSLATE][] = ['onPathTranslation', 10];
    return $events;
  }

  /**
   * {@inheritdoc}
   */
  public function onPathTranslation(PathTranslatorEvent $event) {
    $response = $event->getResponse();
    if (!$response instanceof CacheableJsonResponse) {
      $this->logger->error('Unable to get the response object for the decoupled router event.');
      return;
    }

    // Find the redirected path. Bear in mind that we need to go through several
    // redirection levels before handing off to the route translator.
    $request_query = UrlHelper::parse($event->getPath())['query'];
    $source_path = $this->cleanSubdirInPath(parse_url($event->getPath(), PHP_URL_PATH), $event->getRequest());

    $cacheable_metadata = new CacheableMetadata();
    $cacheable_metadata->addCacheableDependency($this->configFactory->get('redirect.settings'));

    $redirect = $this->redirectRepository->findMatchingRedirect(
      $source_path,
      $request_query,
      $this->languageManager->getCurrentLanguage()->getId(),
      $cacheable_metadata
    );
    if (!$redirect) {
      return;
    }

    $response->addCacheableDependency($cacheable_metadata);
    $redirect_url = $redirect->getRedirectUrl();
    if ($this->configFactory->get('redirect.settings')->get('passthrough_querystring')) {
      $redirect_url->setOption('query', (array) $redirect_url->getOption('query') + $request_query);
    }

    $redirect_url_string = $redirect_url->toString();
    $redirects_trace[] = [
      'from' => $event->getPath(),
      'to' => $redirect_url_string,
      'status' => $redirect->getStatusCode(),
    ];

    // At this point we should be pointing to a system route or path alias.
    $event->setPath($redirect_url_string);

    // Now call the route level.
    parent::onPathTranslation($event);

    if ($response->isSuccessful()) {
      $content = Json::decode($response->getContent());
    }
    elseif ($response->getStatusCode() === 404) {
      // We should return the redirect data.
      $response->setStatusCode(200);
      $cacheable_metadata->addCacheableDependency($this->decoupledRouterConfig);
      $content = [
        'resolved' => $redirect_url->setAbsolute($this->decoupledRouterConfig->get('absolute_resolved_urls'))->toString(TRUE)->getGeneratedUrl(),
        'isExternal' => FALSE,
        'isHomePath' => $this->resolvedPathIsHomePath($redirect_url, $cacheable_metadata),
      ];
    }
    else {
      return;
    }

    // Set the content in the response.
    $response->setData(array_merge(
      $content,
      ['redirect' => $redirects_trace]
    ));

    $response->addCacheableDependency($cacheable_metadata);

    $event->stopPropagation();
  }

}
