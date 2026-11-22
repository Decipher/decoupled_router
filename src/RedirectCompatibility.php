<?php

declare(strict_types=1);

namespace Drupal\decoupled_router;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Reports whether the installed Redirect module is new enough.
 *
 * The requirement itself is built here, without a severity. Severity is the
 * one part that differs by Drupal version, so each caller adds its own.
 *
 * @see decoupled_router_requirements()
 * @see \Drupal\decoupled_router\Hook\RequirementsHooks
 */
class RedirectCompatibility {

  use StringTranslationTrait;

  /**
   * The oldest Redirect release this module works with.
   */
  protected const MINIMUM_REDIRECT_VERSION = '1.12';

  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleExtensionList,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the Redirect compatibility requirement.
   *
   * @return array|null
   *   The requirement, without a severity, or NULL when Redirect is absent,
   *   new enough, or built from a checkout with no version in its info.
   */
  public function getRequirement(): ?array {
    if (!$this->moduleHandler->moduleExists('redirect')) {
      return NULL;
    }
    $version = $this->moduleExtensionList->get('redirect')->info['version'] ?? NULL;
    if ($version === NULL) {
      return NULL;
    }
    // A Drupal 8 style release carries the core prefix, which cannot be
    // compared against a plain version number.
    if (str_starts_with($version, '8.x-')) {
      $version = substr($version, 4);
    }
    if (version_compare($version, static::MINIMUM_REDIRECT_VERSION, '>=')) {
      return NULL;
    }

    return [
      'title' => $this->t('Decoupled router - Redirect compatibility'),
      // The version is spelled out rather than placed, so the string stays
      // identical to the one this module has always shipped.
      'description' => $this->t('The Redirect module must be version 1.12 or greater.'),
    ];
  }

}
