<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\emulsify_tools\Favicon\FaviconSourceSanitizerInterface;
use Drupal\emulsify_tools\Favicon\FaviconThemeSettingsBackfill;
use Drupal\file\FileInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests favicon-setting backfills for installed Emulsify child themes.
 */
#[CoversClass(FaviconThemeSettingsBackfill::class)]
#[Group('emulsify_tools')]
final class FaviconThemeSettingsBackfillTest extends UnitTestCase {

  /**
   * Tests missing defaults and portable SVG data are backfilled together.
   */
  public function testBackfillAddsMissingDefaultsAndPortableSource(): void {
    $storedSettings = [
      'favicon_package_enabled' => TRUE,
      'favicon_source_fid' => [42],
    ];

    $themeHandler = $this->createMock(ThemeHandlerInterface::class);
    $themeHandler->method('listInfo')->willReturn([
      'sfasu' => (object) [
        'base_themes' => ['emulsify' => 'emulsify'],
      ],
    ]);

    $editableConfig = $this->createEditableConfigDouble($storedSettings);
    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')->with('name')->willReturn('Stephen F. Austin State University');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('getEditable')
      ->with('sfasu.settings')
      ->willReturn($editableConfig);
    $configFactory->method('get')
      ->with('system.site')
      ->willReturn($siteConfig);

    $file = $this->createMock(FileInterface::class);
    $file->method('getFilename')->willReturn('circle-info.svg');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(42)->willReturn($file);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('file')->willReturn($storage);

    $sourceSanitizer = $this->createMock(FaviconSourceSanitizerInterface::class);
    $sourceSanitizer->method('sanitizeSourceFile')->with($file)->willReturn('<svg viewBox="0 0 1 1" />');

    $backfill = new FaviconThemeSettingsBackfill(
      $themeHandler,
      $configFactory,
      $entityTypeManager,
      $sourceSanitizer,
    );

    $result = $backfill->backfill();

    self::assertSame([
      'affected_count' => 1,
      'updated_count' => 1,
      'updated_themes' => ['sfasu'],
    ], $result);

    self::assertSame(TRUE, $storedSettings['favicon_package_enabled']);
    self::assertSame([42], $storedSettings['favicon_source_fid']);
    self::assertSame('<svg viewBox="0 0 1 1" />', $storedSettings['favicon_source_svg']);
    self::assertSame('circle-info.svg', $storedSettings['favicon_source_filename']);
    self::assertSame('Stephen F. Austin State University', $storedSettings['favicon_manifest_short_name']);
  }

  /**
   * Creates an editable config mock that mutates an in-memory settings array.
   *
   * @param array<string, mixed> $storedSettings
   *   Theme settings that will be updated by reference.
   */
  private function createEditableConfigDouble(array &$storedSettings): Config {
    $config = $this->getMockBuilder(Config::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getRawData', 'set', 'save'])
      ->getMock();

    $config->method('getRawData')
      ->willReturnCallback(static fn (): array => $storedSettings);
    $config->method('set')
      ->willReturnCallback(function (string $key, mixed $value) use (&$storedSettings, $config): Config {
        $storedSettings[$key] = $value;
        return $config;
      });
    $config->expects(self::once())
      ->method('save')
      ->with(TRUE)
      ->willReturnSelf();

    return $config;
  }

}
