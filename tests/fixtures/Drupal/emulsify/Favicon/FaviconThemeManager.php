<?php

declare(strict_types=1);

namespace Drupal\emulsify\Favicon;

/**
 * Lightweight test double for the companion theme's favicon theme manager.
 */
final class FaviconThemeManager {

  /**
   * The settings returned by loadThemeSettings().
   *
   * @var array<string, mixed>
   */
  public static array $settings = [];

  /**
   * The generated package result returned by generatePackage().
   *
   * @var array<string, mixed>
   */
  public static array $generateResult = [];

  /**
   * The package status returned by buildPackageStatus().
   *
   * @var array<string, mixed>
   */
  public static array $packageStatus = [];

  /**
   * The runtime dependency status returned by getRuntimeDependencyStatus().
   *
   * @var array{gd: bool, imagick: bool}
   */
  public static array $runtimeDependencyStatus = [
    'gd' => TRUE,
    'imagick' => TRUE,
  ];

  /**
   * The settings returned by resetThemeSettings().
   *
   * @var array<string, mixed>
   */
  public static array $resetSettings = [];

  /**
   * Optional placeholder source file returned by resolveStoredSourceFile().
   */
  public static ?object $sourceFile = NULL;

  /**
   * Recorded method calls for assertions.
   *
   * @var list<array<string, mixed>>
   */
  public static array $calls = [];

  /**
   * Creates the test double.
   */
  public function __construct(mixed ...$arguments) {}

  /**
   * Resets static fixture state between tests.
   */
  public static function resetFixture(): void {
    self::$settings = [];
    self::$generateResult = [];
    self::$packageStatus = [];
    self::$runtimeDependencyStatus = [
      'gd' => TRUE,
      'imagick' => TRUE,
    ];
    self::$resetSettings = [];
    self::$sourceFile = NULL;
    self::$calls = [];
  }

  /**
   * Returns fixture settings for a theme.
   *
   * @return array<string, mixed>
   *   The configured settings fixture.
   */
  public function loadThemeSettings(string $themeName): array {
    self::$calls[] = [
      'method' => 'loadThemeSettings',
      'theme' => $themeName,
    ];

    return self::$settings;
  }

  /**
   * Returns the configured stored source-file fixture.
   */
  public function resolveStoredSourceFile(array $settings): ?object {
    self::$calls[] = [
      'method' => 'resolveStoredSourceFile',
      'settings' => $settings,
    ];

    return self::$sourceFile;
  }

  /**
   * Returns fixture package status for a theme.
   *
   * @return array<string, mixed>
   *   The configured package-status fixture.
   */
  public function buildPackageStatus(string $themeName, array $settings, mixed $sourceFile = NULL): array {
    self::$calls[] = [
      'method' => 'buildPackageStatus',
      'theme' => $themeName,
      'settings' => $settings,
      'source_file' => $sourceFile,
    ];

    return self::$packageStatus;
  }

  /**
   * Returns fixture dependency status.
   *
   * @return array{gd: bool, imagick: bool}
   *   Dependency availability keyed by extension name.
   */
  public function getRuntimeDependencyStatus(): array {
    self::$calls[] = [
      'method' => 'getRuntimeDependencyStatus',
    ];

    return self::$runtimeDependencyStatus;
  }

  /**
   * Returns fixture generation results for a theme.
   *
   * @return array<string, mixed>
   *   The configured generation-result fixture.
   */
  public function generatePackage(string $themeName, array $settings, bool $overwrite = FALSE): array {
    self::$calls[] = [
      'method' => 'generatePackage',
      'theme' => $themeName,
      'settings' => $settings,
      'overwrite' => $overwrite,
    ];

    return self::$generateResult;
  }

  /**
   * Records persisted settings written after command-driven generation.
   */
  public function writeThemeSettings(string $themeName, array $settings): void {
    self::$calls[] = [
      'method' => 'writeThemeSettings',
      'theme' => $themeName,
      'settings' => $settings,
    ];
  }

  /**
   * Returns fixture reset settings for a theme.
   *
   * @return array<string, mixed>
   *   The configured reset-settings fixture.
   */
  public function resetThemeSettings(string $themeName): array {
    self::$calls[] = [
      'method' => 'resetThemeSettings',
      'theme' => $themeName,
    ];

    return self::$resetSettings;
  }

}
