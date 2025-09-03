<?php

namespace Drupal\decoupled_router\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
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
    $destination = parse_url($event->getPath(), PHP_URL_PATH);
    $original_query_string = parse_url($event->getPath(), PHP_URL_QUERY);
    $redirects_trace = [];
    $destination = $this->cleanSubdirInPath($destination, $event->getRequest());

    $cacheable_metadata = new CacheableMetadata();
    $redirect = $this->redirectRepository->findMatchingRedirect(
      $destination,
      UrlHelper::parse($event->getPath())['query'] ?? [],
      $this->languageManager->getCurrentLanguage()->getId(),
      $cacheable_metadata
    );
    if (!$redirect) {
      return;
    }
    $response->addCacheableDependency($cacheable_metadata);
    $uri = $redirect->get('redirect_redirect')->uri;
    $url = Url::fromUri($uri)->toString(TRUE);
    $redirects_trace[] = [
      'from' => $this->makeRedirectUrl($destination, $original_query_string),
      'to' => $this->makeRedirectUrl($url->getGeneratedUrl(), $original_query_string),
      'status' => $redirect->getStatusCode(),
    ];
    $destination = $url->getGeneratedUrl();

    // At this point we should be pointing to a system route or path alias.
    $event->setPath($this->makeRedirectUrl($destination, $original_query_string));

    // Now call the route level.
    parent::onPathTranslation($event);

    if ($response->isSuccessful()) {
      $content = Json::decode($response->getContent());
    }
    elseif ($response->getStatusCode() === 404) {
      // We should return the redirect data.
      $response->setStatusCode(200);
      $redirect_url = $redirect->getRedirectUrl()->setAbsolute(TRUE)->toString();
      $content = [
        'resolved' => $this->makeRedirectUrl($redirect_url, $original_query_string),
        'isExternal' => FALSE,
        'isHomePath' => $this->resolvedPathIsHomePath($redirect_url),
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

    $event->stopPropagation();
  }

  /**
   * Generates URL for the redirect, based on redirect module configurations.
   *
   * @param string $path
   *   URL to redirect to.
   * @param string $query
   *   Original query string on the requested path.
   *
   * @return string
   *   Redirect URL to use.
   */
  private function makeRedirectUrl($path, $query) {
    return $query && $this->configFactory->get('redirect.settings')
      ->get('passthrough_querystring')
      ? "{$path}?{$query}"
      : $path;
  }

}
