<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\ThemeGeneration;

/**
 * Describes an Emulsify theme generation request.
 */
final readonly class ThemeGenerationRequest {

  /**
   * Creates a theme generation request.
   */
  public function __construct(
    public string $machineName,
    public string $displayName,
    public string $description,
    public string $starterkitMachineName,
    public string $destinationPath,
  ) {}

}
