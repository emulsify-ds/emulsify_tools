<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Favicon;

use Drupal\file\FileInterface;

/**
 * Sanitizes portable favicon source files for config storage.
 */
interface FaviconSourceSanitizerInterface {

  /**
   * Returns sanitized SVG markup for a managed favicon source file.
   *
   * @throws \Throwable
   *   Thrown when the source file cannot be validated or sanitized.
   */
  public function sanitizeSourceFile(FileInterface $file): string;

}
