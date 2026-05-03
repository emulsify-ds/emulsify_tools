<?php

declare(strict_types=1);

namespace Drupal\emulsify_tools;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Applies Emulsify-generated favicon packages to admin pages when requested.
 */
final class AdminThemeFaviconManager {

  /**
   * The Emulsify base theme machine name.
   */
  private const EMULSIFY_THEME = 'emulsify';

  /**
   * The module config name.
   */
  private const CONFIG_NAME = 'emulsify_tools.settings';

  /**
   * The config key storing enabled theme names.
   */
  private const ENABLED_THEMES_KEY = 'admin_theme_favicon_themes';

  /**
   * Creates the manager.
   */
  public function __construct(
    private readonly AdminContext $adminContext,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ThemeManagerInterface $themeManager,
    private readonly ThemeSettingsProvider $themeSettingsProvider,
    private readonly ThemeExtensionList $themeExtensionList,
  ) {}

  /**
   * Returns whether the admin-theme override is enabled for a theme.
   */
  public function isEnabledForTheme(string $themeName): bool {
    if (!$this->supportsTheme($themeName)) {
      return FALSE;
    }

    return in_array($themeName, $this->getEnabledThemes(), TRUE);
  }

  /**
   * Returns whether a theme can use the Emulsify admin favicon override.
   */
  public function supportsTheme(string $themeName): bool {
    $themeName = trim($themeName);
    if ($themeName === '') {
      return FALSE;
    }

    if ($themeName === self::EMULSIFY_THEME) {
      return TRUE;
    }

    $theme = $this->themeExtensionList->getList()[$themeName] ?? NULL;
    if ($theme === NULL) {
      return FALSE;
    }

    return isset($theme->base_themes[self::EMULSIFY_THEME]);
  }

  /**
   * Persists whether the admin-theme override is enabled for a theme.
   */
  public function setEnabledForTheme(string $themeName, bool $enabled): void {
    $enabledThemes = $this->getEnabledThemes();
    $themeName = trim($themeName);
    if ($themeName === '') {
      return;
    }

    if (!$this->supportsTheme($themeName)) {
      $enabledThemes = array_values(array_filter(
        $enabledThemes,
        static fn (string $enabledTheme): bool => $enabledTheme !== $themeName,
      ));
      $this->configFactory
        ->getEditable(self::CONFIG_NAME)
        ->set(self::ENABLED_THEMES_KEY, $enabledThemes)
        ->save();
      return;
    }

    if ($enabled && !in_array($themeName, $enabledThemes, TRUE)) {
      $enabledThemes[] = $themeName;
    }
    elseif (!$enabled) {
      $enabledThemes = array_values(array_filter(
        $enabledThemes,
        static fn (string $enabledTheme): bool => $enabledTheme !== $themeName,
      ));
    }

    sort($enabledThemes);

    $this->configFactory
      ->getEditable(self::CONFIG_NAME)
      ->set(self::ENABLED_THEMES_KEY, $enabledThemes)
      ->save();
  }

  /**
   * Applies the default frontend theme favicon package to admin pages.
   *
   * @param array $attachments
   *   The page attachments array.
   */
  public function applyToAdminPageAttachments(array &$attachments): void {
    if (!$this->adminContext->isAdminRoute()) {
      return;
    }

    $frontendTheme = $this->getDefaultFrontendTheme();
    if ($frontendTheme === '' || !$this->supportsTheme($frontendTheme) || !$this->isEnabledForTheme($frontendTheme)) {
      return;
    }

    if ($this->themeManager->getActiveTheme()->getName() === $frontendTheme) {
      return;
    }

    $settings = $this->loadThemeFaviconSettings($frontendTheme);
    if (!$settings['favicon_package_enabled'] || $settings['favicon_package_path'] === '') {
      return;
    }

    $this->removeConflictingLinks($attachments);
    $this->attachGeneratedPackage($attachments, $settings);
  }

  /**
   * Returns the configured admin theme name.
   */
  public function getConfiguredAdminTheme(): string {
    return (string) $this->configFactory->get('system.theme')->get('admin');
  }

  /**
   * Returns the configured default frontend theme.
   */
  private function getDefaultFrontendTheme(): string {
    return (string) $this->configFactory->get('system.theme')->get('default');
  }

  /**
   * Returns the list of themes enabled for the admin-theme override.
   *
   * @return string[]
   *   A list of theme machine names.
   */
  private function getEnabledThemes(): array {
    $themes = $this->configFactory->get(self::CONFIG_NAME)->get(self::ENABLED_THEMES_KEY);
    if (!is_array($themes)) {
      return [];
    }

    return array_values(array_filter(
      array_map(static fn (mixed $theme): string => trim((string) $theme), $themes),
      static fn (string $theme): bool => $theme !== '',
    ));
  }

  /**
   * Loads just the Emulsify favicon settings needed for head-tag generation.
   */
  private function loadThemeFaviconSettings(string $themeName): array {
    $siteName = trim((string) $this->configFactory->get('system.site')->get('name'));
    $iconName = trim((string) $this->themeSettingsProvider->getSetting('favicon_ios_icon_name', $themeName));
    if ($iconName === '') {
      $iconName = trim((string) $this->themeSettingsProvider->getSetting('favicon_manifest_short_name', $themeName));
    }
    if ($iconName === '') {
      $iconName = $siteName;
    }

    return [
      'favicon_package_enabled' => (bool) $this->themeSettingsProvider->getSetting('favicon_package_enabled', $themeName),
      'favicon_package_path' => trim((string) $this->themeSettingsProvider->getSetting('favicon_package_path', $themeName)),
      'favicon_android_background_color' => $this->normalizeColor(
        $this->themeSettingsProvider->getSetting('favicon_android_background_color', $themeName),
      ),
      'favicon_ios_icon_name' => $iconName,
    ];
  }

  /**
   * Attaches the generated favicon package to page attachments.
   *
   * @param array $attachments
   *   The page attachments array.
   * @param array $settings
   *   The normalized theme settings.
   */
  private function attachGeneratedPackage(array &$attachments, array $settings): void {
    $packagePath = $settings['favicon_package_path'];
    $themeColor = $settings['favicon_android_background_color'];
    $iconName = trim((string) $settings['favicon_ios_icon_name']);

    $attachments['#attached']['html_head_link'][] = [[
      'rel' => 'icon',
      'href' => $this->fileUrlGenerator->generateString($packagePath . '/favicon.ico'),
      'sizes' => 'any',
    ], 'emulsify_tools_admin_favicon_ico'];

    $attachments['#attached']['html_head_link'][] = [[
      'rel' => 'icon',
      'type' => 'image/svg+xml',
      'href' => $this->fileUrlGenerator->generateString($packagePath . '/favicon.svg'),
    ], 'emulsify_tools_admin_favicon_svg'];

    $attachments['#attached']['html_head_link'][] = [[
      'rel' => 'apple-touch-icon',
      'href' => $this->fileUrlGenerator->generateString($packagePath . '/apple-touch-icon.png'),
    ], 'emulsify_tools_admin_favicon_ios'];

    $attachments['#attached']['html_head_link'][] = [[
      'rel' => 'manifest',
      'href' => $this->fileUrlGenerator->generateString($packagePath . '/site.webmanifest'),
    ], 'emulsify_tools_admin_favicon_manifest'];

    $attachments['#attached']['html_head'][] = [[
      '#tag' => 'meta',
      '#attributes' => [
        'name' => 'theme-color',
        'content' => $themeColor,
      ],
    ], 'emulsify_tools_admin_favicon_theme_color'];

    if ($iconName !== '') {
      $attachments['#attached']['html_head'][] = [[
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'apple-mobile-web-app-title',
          'content' => $iconName,
        ],
      ], 'emulsify_tools_admin_favicon_ios_title'];
    }
  }

  /**
   * Removes conflicting favicon links and metadata from attachments.
   *
   * @param array $attachments
   *   The page attachments array.
   */
  private function removeConflictingLinks(array &$attachments): void {
    if (!empty($attachments['#attached']['html_head_link'])) {
      $attachments['#attached']['html_head_link'] = array_values(array_filter(
        $attachments['#attached']['html_head_link'],
        static function (array $item): bool {
          $attributes = $item[0] ?? [];
          $rel = strtolower((string) ($attributes['rel'] ?? ''));
          return !in_array($rel, ['shortcut icon', 'icon', 'apple-touch-icon', 'manifest'], TRUE);
        },
      ));
    }

    if (!empty($attachments['#attached']['html_head'])) {
      $attachments['#attached']['html_head'] = array_values(array_filter(
        $attachments['#attached']['html_head'],
        static function (array $item): bool {
          $element = $item[0] ?? [];
          $name = strtolower((string) ($element['#attributes']['name'] ?? ''));
          return !in_array($name, ['theme-color', 'apple-mobile-web-app-title'], TRUE);
        },
      ));
    }
  }

  /**
   * Normalizes a hex color string.
   */
  private function normalizeColor(mixed $value): string {
    $candidate = strtolower(trim((string) $value));
    if (preg_match('/^#[0-9a-f]{6}$/', $candidate)) {
      return $candidate;
    }
    if (preg_match('/^#[0-9a-f]{3}$/', $candidate)) {
      return sprintf(
        '#%s%s%s%s%s%s',
        $candidate[1],
        $candidate[1],
        $candidate[2],
        $candidate[2],
        $candidate[3],
        $candidate[3],
      );
    }

    return '#ffffff';
  }

}
