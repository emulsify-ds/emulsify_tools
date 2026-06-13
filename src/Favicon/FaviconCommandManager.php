<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Favicon;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Coordinates module-owned Drush workflows for Emulsify favicon packages.
 */
final class FaviconCommandManager {

  /**
   * The Emulsify base theme machine name.
   */
  private const EMULSIFY_THEME = 'emulsify';

  /**
   * The companion theme API class required for command execution.
   */
  private const THEME_MANAGER_CLASS = 'Drupal\\emulsify\\Favicon\\FaviconThemeManager';

  /**
   * Creates the command manager.
   */
  public function __construct(
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeSettingsProvider $themeSettingsProvider,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Generates the favicon package for a supported theme when needed.
   *
   * @return array<string, mixed>
   *   The theme name, persisted settings, and generation result.
   */
  public function generate(?string $themeName = NULL): array {
    $themeName = $this->resolveTargetThemeName($themeName);
    $themeManager = $this->createThemeManager();
    $settings = $themeManager->loadThemeSettings($themeName);
    $result = $themeManager->generatePackage($themeName, $settings, FALSE);
    $themeManager->writeThemeSettings($themeName, $result['settings'] ?? []);

    return [
      'theme' => $themeName,
      'generated' => (bool) ($result['generated'] ?? FALSE),
      'settings' => $result['settings'] ?? [],
      'result' => $result['result'] ?? [],
      'warnings' => $result['source_context']['analysis']['warnings'] ?? [],
    ];
  }

  /**
   * Returns package and dependency status for a supported theme.
   *
   * @return array<string, mixed>
   *   The theme name, normalized settings, package state, and dependencies.
   */
  public function status(?string $themeName = NULL): array {
    $themeName = $this->resolveTargetThemeName($themeName);
    $themeManager = $this->createThemeManager();
    $settings = $themeManager->loadThemeSettings($themeName);
    $sourceFile = $themeManager->resolveStoredSourceFile($settings);

    return [
      'theme' => $themeName,
      'settings' => $settings,
      'package_status' => $themeManager->buildPackageStatus($themeName, $settings, $sourceFile),
      'dependencies' => $themeManager->getRuntimeDependencyStatus(),
    ];
  }

  /**
   * Resets a supported theme back to default favicon settings.
   *
   * @return array<string, mixed>
   *   The theme name and restored settings.
   */
  public function reset(?string $themeName = NULL): array {
    $themeName = $this->resolveTargetThemeName($themeName);
    $themeManager = $this->createThemeManager();

    return [
      'theme' => $themeName,
      'settings' => $themeManager->resetThemeSettings($themeName),
    ];
  }

  /**
   * Returns whether a theme is Emulsify or an Emulsify child theme.
   */
  public function supportsTheme(string $themeName): bool {
    $themeName = trim($themeName);
    if ($themeName === '') {
      return FALSE;
    }

    $theme = $this->themeExtensionList->getList()[$themeName] ?? NULL;
    if ($theme === NULL) {
      return FALSE;
    }

    if ($themeName === self::EMULSIFY_THEME) {
      return TRUE;
    }

    return isset($theme->base_themes[self::EMULSIFY_THEME]);
  }

  /**
   * Resolves the command target to an installed Emulsify-based theme.
   */
  private function resolveTargetThemeName(?string $themeName): string {
    $themeName = trim((string) $themeName);
    if ($themeName === '') {
      $themeName = trim((string) $this->configFactory->get('system.theme')->get('default'));
    }

    if ($themeName === '') {
      throw new \InvalidArgumentException('No target theme was provided and no default frontend theme is configured.');
    }

    if (!$this->supportsTheme($themeName)) {
      throw new \InvalidArgumentException(sprintf('Theme "%s" is not an Emulsify-based theme in this codebase.', $themeName));
    }

    return $themeName;
  }

  /**
   * Creates the companion theme manager when the active theme API is present.
   *
   * @phpstan-return \Drupal\emulsify\Favicon\FaviconThemeManager
   */
  private function createThemeManager(): object {
    $class = self::THEME_MANAGER_CLASS;
    if (!class_exists($class)) {
      throw new \RuntimeException('Emulsify Tools favicon commands require Emulsify Drupal 7.x or newer. The installed Emulsify theme does not expose Drupal\\emulsify\\Favicon\\FaviconThemeManager.');
    }

    return new $class(
      $this->themeSettingsProvider,
      $this->configFactory,
      $this->fileSystem,
      $this->cacheTagsInvalidator,
      $this->fileUrlGenerator,
      $this->time,
      $this->lock,
    );
  }

}
