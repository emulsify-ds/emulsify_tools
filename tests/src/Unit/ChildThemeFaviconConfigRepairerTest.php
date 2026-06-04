<?php

declare(strict_types=1);

namespace Drupal\Tests\emulsify_tools\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\emulsify_tools\Favicon\ChildThemeFaviconConfigRepairer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests child-theme favicon config repair behavior.
 */
#[CoversClass(ChildThemeFaviconConfigRepairer::class)]
#[Group('emulsify_tools')]
final class ChildThemeFaviconConfigRepairerTest extends UnitTestCase {

  /**
   * The filesystem helper used by the test.
   */
  private Filesystem $filesystem;

  /**
   * Temporary app root for generated theme fixtures.
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->filesystem = new Filesystem();
    $this->appRoot = sys_get_temp_dir() . '/emulsify_tools_repair_' . bin2hex(random_bytes(8));
    $this->filesystem->mkdir($this->appRoot);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->appRoot) && $this->filesystem->exists($this->appRoot)) {
      $this->filesystem->remove($this->appRoot);
    }

    parent::tearDown();
  }

  /**
   * Tests missing install and schema files are created from base templates.
   */
  public function testRepairCreatesMissingThemeConfigFromBaseThemeTemplates(): void {
    $this->writeFile(
      'themes/contrib/emulsify/config/install/emulsify.settings.yml',
      Yaml::encode([
        'favicon_package_enabled' => FALSE,
        'favicon_source_svg' => '',
        'favicon_source_filename' => '',
      ]) . "\n",
    );
    $this->writeFile(
      'themes/contrib/emulsify/config/schema/emulsify.schema.yml',
      Yaml::encode([
        'emulsify.settings' => [
          'type' => 'config_object',
          'label' => 'Emulsify settings',
          'mapping' => [
            'favicon_package_enabled' => ['type' => 'boolean'],
            'favicon_source_svg' => ['type' => 'string'],
            'favicon_source_filename' => ['type' => 'string'],
          ],
        ],
      ]) . "\n",
    );
    $this->writeFile(
      'themes/contrib/emulsify/emulsify.info.yml',
      "name: Emulsify\n",
    );
    $this->writeFile(
      'themes/custom/sfasu/sfasu.info.yml',
      "name: SFA\nbase theme: emulsify\n",
    );

    $baseTheme = new Extension($this->appRoot, 'theme', 'themes/contrib/emulsify/emulsify.info.yml');
    $baseTheme->info = ['name' => 'Emulsify'];

    $childTheme = new Extension($this->appRoot, 'theme', 'themes/custom/sfasu/sfasu.info.yml');
    $childTheme->info = ['name' => 'SFA'];
    $childTheme->base_themes = ['emulsify' => 'emulsify'];

    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('getList')->willReturn([
      'emulsify' => $baseTheme,
      'sfasu' => $childTheme,
    ]);
    $themeExtensionList->method('getPath')
      ->with('emulsify')
      ->willReturn('themes/contrib/emulsify');

    $repairer = new ChildThemeFaviconConfigRepairer(
      $this->appRoot,
      $themeExtensionList,
      $this->filesystem,
    );

    $result = $repairer->repair('sfasu');

    self::assertSame(1, $result['inspected_count']);
    self::assertSame(1, $result['updated_count']);
    self::assertSame(0, $result['unchanged_count']);
    self::assertSame([], $result['errors']);
    self::assertSame([
      'path' => 'themes/custom/sfasu',
      'install' => 'created',
      'schema' => 'created',
    ], $result['updated_themes']['sfasu']);

    self::assertSame([
      'favicon_package_enabled' => FALSE,
      'favicon_source_svg' => '',
      'favicon_source_filename' => '',
    ], Yaml::decode($this->readFile('themes/custom/sfasu/config/install/sfasu.settings.yml')));

    self::assertSame([
      'sfasu.settings' => [
        'type' => 'config_object',
        'label' => 'SFA settings',
        'mapping' => [
          'favicon_package_enabled' => ['type' => 'boolean'],
          'favicon_source_svg' => ['type' => 'string'],
          'favicon_source_filename' => ['type' => 'string'],
        ],
      ],
    ], Yaml::decode($this->readFile('themes/custom/sfasu/config/schema/sfasu.schema.yml')));
  }

  /**
   * Writes a fixture file relative to the temporary app root.
   */
  private function writeFile(string $relativePath, string $contents): void {
    $path = $this->appRoot . '/' . $relativePath;
    $this->filesystem->mkdir(dirname($path));
    $this->filesystem->dumpFile($path, $contents);
  }

  /**
   * Reads a generated fixture file relative to the temporary app root.
   */
  private function readFile(string $relativePath): string {
    $contents = file_get_contents($this->appRoot . '/' . $relativePath);
    if ($contents === FALSE) {
      throw new \RuntimeException(sprintf('Failed to read fixture file "%s".', $relativePath));
    }

    return $contents;
  }

}
