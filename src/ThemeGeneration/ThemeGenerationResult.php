<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\ThemeGeneration;

/**
 * Describes the outcome of a theme generation request.
 */
final readonly class ThemeGenerationResult {

  /**
   * Creates a theme generation result.
   *
   * @param int $exitCode
   *   The generator exit code.
   * @param list<string> $messages
   *   User-facing messages produced while generating the theme.
   * @param string|null $destinationPath
   *   The generated theme path when available.
   */
  public function __construct(
    public int $exitCode,
    public array $messages,
    public ?string $destinationPath = NULL,
  ) {}

}
