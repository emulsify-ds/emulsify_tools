<?php

declare(strict_types=1);

namespace Drupal\emulsify\Favicon;

use Drupal\file\FileInterface;

/**
 * Minimal companion-theme stub for static analysis in the test suite.
 */
final class FaviconPackageGenerator {

  /**
   * Creates the generator stub.
   */
  public function __construct(
    mixed ...$arguments,
  ) {}

  /**
   * Returns a sanitized SVG payload placeholder.
   *
   * @return array{sanitized_svg?: string}
   *   Placeholder validation output.
   */
  public function validateSourceFile(FileInterface $file, bool $sanitize): array {
    return ['sanitized_svg' => ''];
  }

}
