<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\ThemeGeneration;

/**
 * Generates an Emulsify child theme.
 */
interface ThemeGeneratorInterface {

  /**
   * Generates a theme for the supplied request.
   */
  public function generate(ThemeGenerationRequest $request): ThemeGenerationResult;

}
