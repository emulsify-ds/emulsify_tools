<?php

declare(strict_types=1);

namespace Drush\Attributes;

/**
 * Minimal Drush help attribute stub for static analysis in tests.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Help {

  /**
   * Creates the attribute.
   */
  public function __construct(
    public ?string $description = NULL,
    public ?string $synopsis = NULL,
    public bool $hidden = FALSE,
  ) {}

}
