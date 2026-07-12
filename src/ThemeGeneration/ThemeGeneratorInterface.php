<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\ThemeGeneration;

/**
 * Generates a Drupal theme from a starterkit.
 */
interface ThemeGeneratorInterface {

  /**
   * Generates a theme for the supplied request.
   */
  public function generate(ThemeGenerationRequest $request): ThemeGenerationResult;

}
