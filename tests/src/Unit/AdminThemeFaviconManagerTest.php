<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\emulsify_tools\AdminThemeFaviconManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests admin-theme favicon attachment behavior.
 */
#[CoversClass(AdminThemeFaviconManager::class)]
#[Group('emulsify_tools')]
final class AdminThemeFaviconManagerTest extends UnitTestCase {

  /**
   * Tests theme support detection.
   */
  public function testSupportsThemeRecognizesBaseAndChildThemes(): void {
    $manager = $this->createManager(
      enabledThemes: ['sfasu'],
      themes: [
        'sfasu' => (object) ['base_themes' => ['emulsify' => 'emulsify']],
        'claro' => (object) ['base_themes' => []],
      ],
    );

    self::assertTrue($manager->supportsTheme('emulsify'));
    self::assertTrue($manager->supportsTheme('sfasu'));
    self::assertFalse($manager->supportsTheme('claro'));
    self::assertTrue($manager->isEnabledForTheme('sfasu'));
    self::assertFalse($manager->isEnabledForTheme('claro'));
  }

  /**
   * Tests generated favicon assets replace conflicting admin-theme defaults.
   */
  public function testApplyToAdminPageAttachmentsReplacesConflictingAssets(): void {
    $manager = $this->createManager(
      enabledThemes: ['sfasu'],
      themeSettings: [
        'favicon_package_enabled' => TRUE,
        'favicon_package_path' => 'public://favicon-package/sfasu/hash',
        'favicon_android_background_color' => '#abc',
        'favicon_ios_icon_name' => 'Stephen',
      ],
      themes: [
        'sfasu' => (object) ['base_themes' => ['emulsify' => 'emulsify']],
      ],
      activeTheme: 'claro',
      adminTheme: 'claro',
      defaultTheme: 'sfasu',
      siteName: 'Stephen F. Austin State University',
      isAdminRoute: TRUE,
    );

    $attachments = [
      '#attached' => [
        'html_head_link' => [
          [['rel' => 'icon', 'href' => '/misc/favicon.ico'], 'core_favicon'],
          [['rel' => 'manifest', 'href' => '/misc/site.webmanifest'], 'core_manifest'],
          [['rel' => 'canonical', 'href' => '/node/1'], 'canonical'],
        ],
        'html_head' => [
          [[
            '#tag' => 'meta',
            '#attributes' => [
              'name' => 'theme-color',
              'content' => '#000000',
            ],
          ], 'core_theme_color'],
          [[
            '#tag' => 'meta',
            '#attributes' => [
              'name' => 'robots',
              'content' => 'noindex',
            ],
          ], 'robots'],
        ],
      ],
    ];

    $manager->applyToAdminPageAttachments($attachments);

    self::assertSame([
      [
        [
          'rel' => 'canonical',
          'href' => '/node/1',
        ],
        'canonical',
      ],
      [
        [
          'rel' => 'icon',
          'href' => '/generated/favicon-package/sfasu/hash/favicon.ico',
          'sizes' => 'any',
        ],
        'emulsify_tools_admin_favicon_ico',
      ],
      [
        [
          'rel' => 'icon',
          'type' => 'image/svg+xml',
          'href' => '/generated/favicon-package/sfasu/hash/favicon.svg',
        ],
        'emulsify_tools_admin_favicon_svg',
      ],
      [
        [
          'rel' => 'apple-touch-icon',
          'href' => '/generated/favicon-package/sfasu/hash/apple-touch-icon.png',
        ],
        'emulsify_tools_admin_favicon_ios',
      ],
      [
        [
          'rel' => 'manifest',
          'href' => '/generated/favicon-package/sfasu/hash/site.webmanifest',
        ],
        'emulsify_tools_admin_favicon_manifest',
      ],
    ], $attachments['#attached']['html_head_link']);

    self::assertSame([
      [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'robots',
            'content' => 'noindex',
          ],
        ],
        'robots',
      ],
      [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'theme-color',
            'content' => '#aabbcc',
          ],
        ],
        'emulsify_tools_admin_favicon_theme_color',
      ],
      [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'apple-mobile-web-app-title',
            'content' => 'Stephen',
          ],
        ],
        'emulsify_tools_admin_favicon_ios_title',
      ],
    ], $attachments['#attached']['html_head']);
  }

  /**
   * Tests that admin attachments stay untouched on the frontend theme itself.
   */
  public function testApplyToAdminPageAttachmentsSkipsWhenFrontendThemeIsActive(): void {
    $manager = $this->createManager(
      enabledThemes: ['sfasu'],
      themeSettings: [
        'favicon_package_enabled' => TRUE,
        'favicon_package_path' => 'public://favicon-package/sfasu/hash',
      ],
      themes: [
        'sfasu' => (object) ['base_themes' => ['emulsify' => 'emulsify']],
      ],
      activeTheme: 'sfasu',
      defaultTheme: 'sfasu',
      isAdminRoute: TRUE,
    );

    $attachments = [
      '#attached' => [
        'html_head_link' => [
          [['rel' => 'icon', 'href' => '/misc/favicon.ico'], 'core_favicon'],
        ],
      ],
    ];

    $manager->applyToAdminPageAttachments($attachments);

    self::assertSame('/misc/favicon.ico', $attachments['#attached']['html_head_link'][0][0]['href']);
  }

  /**
   * Creates a configured manager instance for unit tests.
   *
   * @param string[] $enabledThemes
   *   Themes enabled for the admin favicon override.
   * @param array<string, mixed> $themeSettings
   *   Theme setting values keyed by setting name.
   * @param array<string, object> $themes
   *   Theme definitions keyed by machine name.
   */
  private function createManager(
    array $enabledThemes = [],
    array $themeSettings = [],
    array $themes = [],
    string $activeTheme = 'claro',
    string $adminTheme = 'claro',
    string $defaultTheme = 'sfasu',
    string $siteName = 'Site',
    bool $isAdminRoute = TRUE,
  ): AdminThemeFaviconManager {
    $adminContext = $this->createMock(AdminContext::class);
    $adminContext->method('isAdminRoute')->willReturn($isAdminRoute);

    $systemThemeConfig = $this->createConfigMock([
      'default' => $defaultTheme,
      'admin' => $adminTheme,
    ]);
    $moduleConfig = $this->createConfigMock([
      'admin_theme_favicon_themes' => $enabledThemes,
    ]);
    $siteConfig = $this->createConfigMock([
      'name' => $siteName,
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['system.theme', $systemThemeConfig],
      ['emulsify_tools.settings', $moduleConfig],
      ['system.site', $siteConfig],
    ]);

    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $fileUrlGenerator->method('generateString')
      ->willReturnCallback(static fn (string $path): string => '/generated/' . ltrim(str_replace('public://', '', $path), '/'));

    $themeManager = $this->createMock(ThemeManagerInterface::class);
    $themeManager->method('getActiveTheme')->willReturn(new ActiveTheme(['name' => $activeTheme]));

    $themeSettingsProvider = $this->createMock(ThemeSettingsProvider::class);
    $themeSettingsProvider->method('getSetting')
      ->willReturnCallback(static fn (string $key): mixed => $themeSettings[$key] ?? NULL);

    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('getList')->willReturn($themes);

    return new AdminThemeFaviconManager(
      $adminContext,
      $configFactory,
      $fileUrlGenerator,
      $themeManager,
      $themeSettingsProvider,
      $themeExtensionList,
    );
  }

  /**
   * Creates a lightweight immutable config mock backed by an array.
   *
   * @param array<string, mixed> $values
   *   Config values keyed by config item name.
   */
  private function createConfigMock(array $values): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn (string $key): mixed => $values[$key] ?? NULL);

    return $config;
  }

}
