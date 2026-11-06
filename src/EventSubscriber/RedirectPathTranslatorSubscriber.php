<?php

declare(strict_types=1);

namespace Drupal\decoupled_router\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\decoupled_router\PathTranslatorEvent;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\redirect\Exception\RedirectLoopException;
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
  public function onPathTranslation(PathTranslatorEvent $event): void {
    $response = $event->getResponse();

    // Find the redirected path. Bear in mind that we need to go through several
    // redirection levels before handing off to the route translator.
    $original_parsed_url = UrlHelper::parse($event->getPath());
    $request_query = $original_parsed_url['query'];
    $source_path = $this->cleanSubdirInPath($original_parsed_url['path'], $event->getRequest());

    // Redirects are stored without a language prefix. When the path carries
    // one, strip it and match in the language the prefix negotiates. The
    // negotiated request language is the fallback.
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $prefix = '';
    $method = $this->getUrlNegotiationMethod();
    if ($method instanceof InboundPathProcessorInterface) {
      $source_request = $this->createNegotiationRequest($source_path, $event->getRequest());
      $path_langcode = $method->getLangcode($source_request);
      if ($path_langcode) {
        // The language of the path wins over the negotiated request
        // language, also when the path carries no prefix.
        $langcode = $path_langcode;
        $inner_path = $method->processInbound($source_path, $source_request);
        if ($inner_path !== $source_path) {
          $detected_prefix = $this->splitLanguagePrefix($source_path, $inner_path);
          if ($detected_prefix !== NULL) {
            $prefix = $detected_prefix;
            $source_path = $inner_path;
          }
        }
      }
    }

    $cacheable_metadata = new CacheableMetadata();
    $cacheable_metadata->addCacheableDependency($this->configFactory->get('redirect.settings'));

    try {
      $redirect = $this->redirectRepository->findMatchingRedirect(
        $source_path,
        $request_query,
        $langcode,
        $cacheable_metadata
      );
    }
    catch (RedirectLoopException $e) {
      // Redirect loops are data problems that the redirect module already
      // guards against, see RedirectRequestSubscriber. Log the loop and fall
      // through to the route level translator so the response degrades to a
      // regular route lookup instead of a 500.
      $this->logger->warning('Redirect loop identified at %path for redirect %rid. Redirect resolution skipped.', [
        '%path' => $e->getPath(),
        '%rid' => $e->getRedirectId(),
      ]);
      $response->addCacheableDependency($cacheable_metadata);
      return;
    }
    if (!$redirect) {
      return;
    }

    $response->addCacheableDependency($cacheable_metadata);
    $redirect_url = $redirect->getRedirectUrl();
    if ($this->configFactory->get('redirect.settings')->get('passthrough_querystring')) {
      $redirect_url->setOption('query', (array) $redirect_url->getOption('query') + $request_query);
    }

    // Build the target in the language of the path prefix. A target that has
    // a route is stamped with the language of the current request otherwise,
    // which on a site where every language has a prefix means the fallback
    // language, and the target then carries the wrong prefix. Only do this
    // when a prefix was actually stripped, since a negotiation method can
    // report a language for a path that carries no prefix at all.
    if ($prefix !== '' && !$redirect_url->isExternal()) {
      $path_language = $this->languageManager->getLanguage($langcode);
      if ($path_language) {
        $redirect_url->setOption('language', $path_language);
      }
    }

    $redirect_url_string = $redirect_url->toString();

    // Preserve the fragment as per RFC 7231, see
    // https://www.rfc-editor.org/rfc/rfc7231#section-7.1.2. Only replace the
    // fragment if the redirect does not have a fragment. Redirects store
    // fragments as part of the path so we need to parse the URI.
    if (isset($original_parsed_url['fragment']) && empty(UrlHelper::parse($redirect_url_string)['fragment'])) {
      $redirect_url->setOption('fragment', $original_parsed_url['fragment']);
      $redirect_url_string = $redirect_url->toString();
    }

    // Keep the path prefix on an internal redirect target. Without it the
    // route level cannot resolve a destination alias in the path language.
    if ($prefix !== '' && !$redirect_url->isExternal()) {
      // The generated URL carries the subdirectory Drupal is installed in.
      // The prefix belongs behind it, so a site below /subdir serves
      // /subdir/de/user/login and not /de/subdir/user/login.
      $base_path = rtrim($event->getRequest()->getBasePath(), '/');
      $target = $redirect_url_string;
      if ($base_path !== '' && str_starts_with($target, $base_path)) {
        $target = substr($target, strlen($base_path));
      }
      if ($target !== $prefix && !str_starts_with($target, "$prefix/")) {
        $redirect_url_string = $base_path . $prefix . $target;
      }
    }

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
      // The path prefix lives only on the string, so the string is the
      // source of truth for the reported target. It must match the
      // redirect trace above.
      $resolved = $redirect_url_string;
      if (!$redirect_url->isExternal() && $this->decoupledRouterConfig->get('absolute_resolved_urls')) {
        $resolved = $event->getRequest()->getSchemeAndHttpHost() . $resolved;
      }
      // Compare the home path in the language of the path prefix.
      $language = $this->languageManager->getLanguage($langcode);
      if ($language && !$redirect_url->isExternal()) {
        $redirect_url->setOption('language', $language);
      }
      $content = [
        'resolved' => $resolved,
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
