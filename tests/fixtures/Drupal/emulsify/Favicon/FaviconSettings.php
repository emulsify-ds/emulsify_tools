<?php

declare(strict_types=1);

namespace Drupal\emulsify\Favicon;

/**
 * Lightweight test double for the companion theme's favicon settings helper.
 */
final class FaviconSettings {

  /**
   * Minimal defaults needed by the module unit tests.
   */
  public const DEFAULTS = [
    'favicon_package_enabled' => FALSE,
    'favicon_source_fid' => [],
    'favicon_source_svg' => '',
    'favicon_source_filename' => '',
    'favicon_manifest_short_name' => '',
  ];

  /**
   * Normalizes settings the same way the module expects during backfill tests.
   */
  public static function normalize(array $settings, string $siteName): array {
    return array_replace(
      self::DEFAULTS,
      ['favicon_manifest_short_name' => $siteName],
      $settings,
    );
  }

  /**
   * Extracts a file ID from the saved source-file setting shape.
   */
  public static function getSourceFileId(array $settings): ?int {
    $value = $settings['favicon_source_fid'] ?? NULL;

    if (is_int($value)) {
      return $value;
    }

    if (is_array($value)) {
      foreach ($value as $candidate) {
        if (is_scalar($candidate) && preg_match('/^\d+$/', (string) $candidate)) {
          return (int) $candidate;
        }
      }
    }

    return NULL;
  }

}
