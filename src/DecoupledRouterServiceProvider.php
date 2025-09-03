<?php

namespace Drupal\decoupled_router;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\decoupled_router\EventSubscriber\RedirectPathTranslatorSubscriber;

/**
 * Registers redirect event listener if Redirect is installed.
 */
class DecoupledRouterServiceProvider implements ServiceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    if (isset($container->getParameter('container.modules')['redirect'])) {
      $container->register('decoupled_router.redirect_path_translator.subscriber', RedirectPathTranslatorSubscriber::class)
        ->setAutowired(TRUE)
        ->setAutoconfigured(TRUE)
        ->setPublic(TRUE);
    }
  }

}
