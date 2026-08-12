<?php

declare(strict_types=1);

namespace Drupal\decoupled_router\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\decoupled_router\PathTranslatorEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Controller that receives the path to inspect.
 */
class PathTranslator extends ControllerBase {

  /**
   * EventInfoController constructor.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   Event dispatcher service.
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
   *   The HTTP kernel.
   */
  public function __construct(protected EventDispatcherInterface $eventDispatcher, protected HttpKernelInterface $httpKernel) {
  }

  /**
   * Create function for dependency injection.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('event_dispatcher'),
      $container->get('http_kernel')
    );
  }

  /**
   * Responds with all the information about the path.
   */
  public function translate(Request $request) {
    $path = $request->query->get('path');
    if (empty($path)) {
      throw new NotFoundHttpException('Unable to translate empty path. Please send a ?path query string parameter with your request.');
    }
    // Now that we have the path, let's fire an event for translations.
    $event = new PathTranslatorEvent(
      $this->httpKernel,
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      sprintf('/%s', ltrim($path, '/'))
    );
    // Event subscribers are in charge of setting the appropriate response,
    // including cacheability metadata.
    $this->eventDispatcher->dispatch($event, PathTranslatorEvent::TRANSLATE);
    $response = $event->getResponse();
    $response->headers->add(['Content-Type' => 'application/json']);
    $response->getCacheableMetadata()->addCacheContexts([
      'url.query_args:path',
      'languages:' . LanguageInterface::TYPE_CONTENT,
    ]);
    if ($response->getStatusCode() === Response::HTTP_NOT_FOUND) {
      $response->getCacheableMetadata()->addCacheTags(['4xx-response']);
    }
    return $response;
  }

}
