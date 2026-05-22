<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools\Favicon;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\emulsify\Favicon\FaviconSettings;
use Drupal\file\FileInterface;

/**
 * Backfills favicon settings for installed Emulsify-based themes.
 */
final class FaviconThemeSettingsBackfill {

  /**
   * The Emulsify base theme machine name.
   */
  private const BASE_THEME = 'emulsify';

  /**
   * Creates the backfill service.
   */
  public function __construct(
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FaviconSourceSanitizerInterface $sourceSanitizer,
  ) {}

  /**
   * Backfills missing favicon keys for installed Emulsify-based themes.
   *
   * @return array{affected_count: int, updated_count: int, updated_themes: string[]}
   *   A summary of the migrated theme settings.
   */
  public function backfill(): array {
    if (!class_exists(FaviconSettings::class)) {
      // Older companion-theme builds do not ship the Emulsify 7.x favicon API.
      // In that case the migration is intentionally a no-op instead of failing.
      return [
        'affected_count' => 0,
        'updated_count' => 0,
        'updated_themes' => [],
      ];
    }

    $siteName = $this->getSiteName();
    $affectedThemeNames = $this->getAffectedThemeNames();
    $updatedThemes = [];

    foreach ($affectedThemeNames as $themeName) {
      $config = $this->configFactory->getEditable($themeName . '.settings');
      $storedSettings = $this->getStoredSettings($config);
      $normalizedSettings = FaviconSettings::normalize($storedSettings, $siteName);

      $changed = $this->backfillMissingDefaults($config, $storedSettings, $normalizedSettings);
      $changed = $this->backfillPortableSource($config, $storedSettings) || $changed;

      if ($changed) {
        $config->save(TRUE);
        $updatedThemes[] = $themeName;
      }
    }

    return [
      'affected_count' => count($affectedThemeNames),
      'updated_count' => count($updatedThemes),
      'updated_themes' => $updatedThemes,
    ];
  }

  /**
   * Returns the installed themes that should receive the migration.
   *
   * @return string[]
   *   Theme machine names.
   */
  private function getAffectedThemeNames(): array {
    $themeNames = [];

    foreach ($this->themeHandler->listInfo() as $themeName => $theme) {
      if ($themeName === self::BASE_THEME) {
        $themeNames[] = $themeName;
        continue;
      }

      $baseThemes = [];
      if (is_object($theme) && isset($theme->base_themes) && is_array($theme->base_themes)) {
        $baseThemes = array_keys($theme->base_themes);
      }

      if (in_array(self::BASE_THEME, $baseThemes, TRUE)) {
        $themeNames[] = $themeName;
      }
    }

    sort($themeNames);

    return $themeNames;
  }

  /**
   * Returns editable config raw data as an array.
   */
  private function getStoredSettings(Config $config): array {
    $settings = $config->getRawData();

    return is_array($settings) ? $settings : [];
  }

  /**
   * Fills any missing or NULL favicon settings using current defaults.
   */
  private function backfillMissingDefaults(Config $config, array $storedSettings, array $normalizedSettings): bool {
    $changed = FALSE;

    foreach (array_keys(FaviconSettings::DEFAULTS) as $key) {
      if (array_key_exists($key, $storedSettings) && $storedSettings[$key] !== NULL) {
        continue;
      }

      $config->set($key, $normalizedSettings[$key] ?? FaviconSettings::DEFAULTS[$key]);
      $changed = TRUE;
    }

    return $changed;
  }

  /**
   * Backfills the portable SVG source from an existing managed file.
   */
  private function backfillPortableSource(Config $config, array $storedSettings): bool {
    if (empty($storedSettings['favicon_package_enabled'])) {
      return FALSE;
    }

    if (trim((string) ($storedSettings['favicon_source_svg'] ?? '')) !== '') {
      return FALSE;
    }

    $sourceFileId = FaviconSettings::getSourceFileId($storedSettings);
    if ($sourceFileId === NULL) {
      return FALSE;
    }

    $sourceFile = $this->loadSourceFile($sourceFileId);
    if (!$sourceFile instanceof FileInterface) {
      return FALSE;
    }

    try {
      $sourceSvg = $this->sourceSanitizer->sanitizeSourceFile($sourceFile);
    }
    catch (\Throwable) {
      return FALSE;
    }

    if ($sourceSvg === '') {
      return FALSE;
    }

    $config->set('favicon_source_svg', $sourceSvg);
    $config->set('favicon_source_filename', $sourceFile->getFilename());

    return TRUE;
  }

  /**
   * Loads a source file entity from its file ID.
   */
  private function loadSourceFile(int $fileId): ?FileInterface {
    $storage = $this->getFileStorage();
    $file = $storage->load($fileId);

    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Returns file storage.
   */
  private function getFileStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('file');
  }

  /**
   * Returns the current site name.
   */
  private function getSiteName(): string {
    return (string) $this->configFactory->get('system.site')->get('name');
  }

}
