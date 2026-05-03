<?php

namespace Drupal\emulsify_tools;

use Twig\Extension\AbstractExtension;

/**
 * Creates a new switch case extension for Twig.
 */
class SwitchExtension extends AbstractExtension {

  /**
   * Gets token parsers.
   */
  public function getTokenParsers() {
    return [
      new SwitchTokenParser(),
    ];
  }

}
