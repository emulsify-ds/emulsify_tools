<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\ThemeGeneration;

/**
 * Contains the original theme name input and its resolved machine name.
 */
final readonly class ThemeMachineName {

  /**
   * Creates a resolved theme machine name.
   */
  public function __construct(
    public string $originalInput,
    public string $machineName,
  ) {}

  /**
   * Returns whether normalization changed the meaningful input value.
   */
  public function wasNormalized(): bool {
    return $this->machineName !== trim($this->originalInput);
  }

}
