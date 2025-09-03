<?php

namespace Drupal\decoupled_router;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\decoupled_router\EventSubscriber\RedirectPathTranslatorSubscriber;
use Symfony\Component\DependencyInjection\ChildDefinition;

/**
 * Registers redirect event listener if Redirect is installed.
 */
class DecoupledRouterServiceProvider implements ServiceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    if (isset($container->getParameter('container.modules')['redirect'])) {
      $definition = new ChildDefinition('decoupled_router.router_path_translator.subscriber');
      $definition->addTag('event_subscriber')
        ->setPublic(TRUE)
        ->setClass(RedirectPathTranslatorSubscriber::class);
      $container->setDefinition('decoupled_router.redirect_path_translator.subscriber', $definition);
    }
  }

}
