<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools;

use Twig\Extension\AbstractExtension;

/**
 * Creates a new switch case extension for Twig.
 */
final class SwitchExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getTokenParsers(): array {
    return [
      new SwitchTokenParser(),
    ];
  }

}
