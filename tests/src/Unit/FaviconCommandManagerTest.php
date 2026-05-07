<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\emulsify\Favicon\FaviconThemeManager as ThemeManagerFixture;
use Drupal\emulsify_tools\Favicon\FaviconCommandManager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests module-owned Drush orchestration for Emulsify favicon packages.
 */
#[CoversClass(FaviconCommandManager::class)]
#[Group('emulsify_tools')]
final class FaviconCommandManagerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    ThemeManagerFixture::resetFixture();
    parent::tearDown();
  }

  /**
   * Tests generation uses the configured frontend theme when no theme is given.
   */
  public function testGenerateUsesDefaultFrontendTheme(): void {
    ThemeManagerFixture::resetFixture();
    ThemeManagerFixture::$settings = [
      'favicon_package_enabled' => TRUE,
    ];
    ThemeManagerFixture::$generateResult = [
      'generated' => TRUE,
      'settings' => [
        'favicon_package_enabled' => TRUE,
        'favicon_package_hash' => 'abc123',
        'favicon_package_path' => 'public://favicon-package/sfasu/abc123',
        'favicon_package_generated_at' => 1700000000,
      ],
      'result' => [
        'hash' => 'abc123',
        'path' => 'public://favicon-package/sfasu/abc123',
        'generated_at' => 1700000000,
      ],
      'source_context' => [
        'analysis' => [
          'warnings' => ['Portable source contains embedded raster data.'],
        ],
      ],
    ];

    $manager = $this->createCommandManager('sfasu', $this->createSupportedThemes());
    $result = $manager->generate();

    self::assertSame('sfasu', $result['theme']);
    self::assertTrue($result['generated']);
    self::assertSame('abc123', $result['result']['hash']);
    self::assertSame(['Portable source contains embedded raster data.'], $result['warnings']);
    self::assertSame('generatePackage', ThemeManagerFixture::$calls[1]['method']);
    self::assertFalse(ThemeManagerFixture::$calls[1]['overwrite']);
    self::assertSame('writeThemeSettings', ThemeManagerFixture::$calls[2]['method']);
    self::assertSame('abc123', ThemeManagerFixture::$calls[2]['settings']['favicon_package_hash']);
  }

  /**
   * Tests unsupported themes are rejected before any theme API calls happen.
   */
  public function testGenerateRejectsUnsupportedThemes(): void {
    ThemeManagerFixture::resetFixture();
    $manager = $this->createCommandManager('bartik', $this->createUnsupportedThemes());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Theme "bartik" is not an Emulsify-based theme in this codebase.');
    $manager->generate('bartik');
  }

  /**
   * Tests status returns package metadata and runtime dependency visibility.
   */
  public function testStatusReturnsPackageAndDependencyData(): void {
    ThemeManagerFixture::resetFixture();
    ThemeManagerFixture::$settings = [
      'favicon_package_enabled' => TRUE,
    ];
    ThemeManagerFixture::$sourceFile = new \stdClass();
    ThemeManagerFixture::$packageStatus = [
      'state' => 'missing',
      'hash' => 'abc123',
      'path' => 'public://favicon-package/sfasu/abc123',
      'package_exists' => FALSE,
      'source_available' => TRUE,
      'portable_source_available' => TRUE,
      'portable_source_size' => 128,
      'analysis_warnings' => ['Portable source contains embedded raster data.'],
      'generated_at' => 1700000000,
    ];
    ThemeManagerFixture::$runtimeDependencyStatus = [
      'gd' => TRUE,
      'imagick' => FALSE,
    ];

    $manager = $this->createCommandManager('sfasu', $this->createSupportedThemes());
    $result = $manager->status('sfasu');

    self::assertSame('sfasu', $result['theme']);
    self::assertSame(ThemeManagerFixture::$settings, $result['settings']);
    self::assertSame(ThemeManagerFixture::$packageStatus, $result['package_status']);
    self::assertSame(['gd' => TRUE, 'imagick' => FALSE], $result['dependencies']);
    self::assertSame('buildPackageStatus', ThemeManagerFixture::$calls[2]['method']);
  }

  /**
   * Tests reset delegates to the theme manager and returns restored defaults.
   */
  public function testResetRestoresThemeDefaults(): void {
    ThemeManagerFixture::resetFixture();
    ThemeManagerFixture::$resetSettings = [
      'favicon_package_enabled' => FALSE,
      'favicon_source_svg' => '',
      'favicon_source_filename' => '',
    ];

    $manager = $this->createCommandManager('sfasu', $this->createSupportedThemes());
    $result = $manager->reset('sfasu');

    self::assertSame('sfasu', $result['theme']);
    self::assertSame(ThemeManagerFixture::$resetSettings, $result['settings']);
    self::assertSame('resetThemeSettings', ThemeManagerFixture::$calls[0]['method']);
  }

  /**
   * Creates the command manager under test.
   *
   * @param array<string, object> $themes
   *   Themes available in the current codebase.
   */
  private function createCommandManager(string $defaultTheme, array $themes): FaviconCommandManager {
    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('getList')->willReturn($themes);

    $systemThemeConfig = $this->createMock(ImmutableConfig::class);
    $systemThemeConfig->method('get')
      ->with('default')
      ->willReturn($defaultTheme);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('system.theme')
      ->willReturn($systemThemeConfig);

    return new FaviconCommandManager(
      $themeExtensionList,
      $configFactory,
      $this->createMock(ThemeSettingsProvider::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LockBackendInterface::class),
    );
  }

  /**
   * Returns a supported base-theme and child-theme list.
   *
   * @return array<string, object>
   *   Supported Emulsify-based themes.
   */
  private function createSupportedThemes(): array {
    return [
      'emulsify' => (object) [
        'info' => ['name' => 'Emulsify'],
      ],
      'sfasu' => (object) [
        'info' => ['name' => 'SFA'],
        'base_themes' => ['emulsify' => 'emulsify'],
      ],
    ];
  }

  /**
   * Returns a non-Emulsify theme list for validation failures.
   *
   * @return array<string, object>
   *   Unsupported themes.
   */
  private function createUnsupportedThemes(): array {
    return [
      'bartik' => (object) [
        'info' => ['name' => 'Bartik'],
      ],
    ];
  }

}
